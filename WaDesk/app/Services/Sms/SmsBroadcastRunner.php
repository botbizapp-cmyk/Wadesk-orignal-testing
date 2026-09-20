<?php

namespace App\Services\Sms;

use App\Models\Broadcast;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\WaProviderConfig;
use App\Models\WaTemplate;
use App\Services\TemplateOverrideResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sends an SMS broadcast from PHP.
 *
 * WHY A PHP RUNNER. Every WhatsApp broadcast is handed to the Node bridge
 * (broadcastService.js), but Node only speaks the WhatsApp transports — it can't
 * send via the PHP Twilio-SMS / MSG91 transports. So a broadcast whose sender is
 * provider='sms' is intercepted at the top of dispatchToBridge and run here
 * instead: it REUSES the existing campaign builder, audience, scheduling and the
 * broadcasts index — only the transport differs.
 *
 * Reuses the existing broadcast_contacts pivot for per-recipient status, mirrors
 * each send into the unified team inbox (so it threads with the customer's other
 * messages and the delivery-status webhook can update it), and records segment
 * count + cost for reporting. SMS cost is NEVER billed to the WhatsApp wallet.
 */
class SmsBroadcastRunner
{
    public function run(Broadcast $b, ?array $retryContactIds = null): array
    {
        $wsId = (int) $b->workspace_id;

        $sender = SmsSender::fromConfig($b->device_id ? WaProviderConfig::find($b->device_id) : null)
            ?? SmsSender::firstForWorkspace($wsId);

        if (! $sender || ! $sender->isSendable()) {
            $b->forceFill(['status' => 'failed'])->save();
            Log::warning('[SMS-BCAST] no sendable SMS number', ['broadcast_id' => $b->id, 'device_id' => $b->device_id]);

            return ['ok' => false, 'error' => 'No sendable SMS number connected'];
        }

        // Plan quota — SMS-only cap, NEVER the WhatsApp wallet. check() is a no-op
        // when sms_monthly_limit isn't configured (unlimited).
        try {
            \App\Services\PlanLimitGuard::check($b->workspace, 'sms_monthly_limit', $this->monthlyOutbound($wsId));
        } catch (\App\Exceptions\PlanLimitReachedException $e) {
            $b->forceFill(['status' => 'failed'])->save();
            Log::warning('[SMS-BCAST] plan limit reached: ' . $e->getMessage(), ['broadcast_id' => $b->id]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // Message body — a template's plain-text body, else the freeform caption.
        $bodyRaw = $b->template_id
            ? (string) (optional(WaTemplate::find($b->template_id))->template_body ?? '')
            : (string) ($b->temp_caption ?? '');

        $recipients = $this->recipients($b, $retryContactIds);
        if (empty($recipients)) {
            $b->forceFill(['status' => 'failed'])->save();

            return ['ok' => false, 'error' => 'No recipients with a usable phone number'];
        }

        $resolver = app(TemplateOverrideResolver::class);
        $rate = $sender->rate_per_segment !== null ? (float) $sender->rate_per_segment : 0.0;
        $client = new SmsClient($sender);

        $sent = 0;
        $failed = 0;
        $segments = 0;

        foreach ($recipients as $cr) {
            $phone = (string) $cr['phone'];
            $body = $this->personalize($bodyRaw, $cr, $wsId, $resolver);
            if ($phone === '' || trim($body) === '') {
                $failed++;
                $this->markRecipient($b, (int) $cr['id'], 'failed', '', 'Empty phone or message');
                continue;
            }

            $res = $client->send($phone, $body);
            $seg = (int) ($res['segments'] ?? SmsSegments::measure($body)['segments']);
            $segments += $seg;

            if (! empty($res['ok'])) {
                $sent++;
                $sid = (string) ($res['message_id'] ?? '');
                $this->markRecipient($b, (int) $cr['id'], 'sent', $sid);
                $this->mirrorToInbox($sender, $wsId, $phone, $body, $sid, $seg);
            } else {
                $failed++;
                $this->markRecipient($b, (int) $cr['id'], 'failed', '', (string) ($res['error'] ?? 'SMS send failed'));
            }
        }

        $cost = round($segments * $rate, 4);
        $b->forceFill([
            'status'        => $failed > 0 ? ($sent > 0 ? 'completed_with_errors' : 'failed') : 'completed',
            'success_count' => $sent,
            'fail_count'    => $failed,
            'completed_at'  => now(),
        ])->save();

        Log::info('[SMS-BCAST] done', [
            'broadcast_id' => $b->id, 'sent' => $sent, 'failed' => $failed,
            'segments' => $segments, 'cost' => $cost, 'currency' => $sender->currency, 'provider' => $sender->provider,
        ]);

        return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'segments' => $segments, 'cost' => $cost, 'currency' => $sender->currency];
    }

    /** Outbound SMS this billing month for the workspace (plan-cap metric). */
    private function monthlyOutbound(int $wsId): int
    {
        return (int) InboxMessage::query()
            ->where('inbox_messages.provider', 'sms')
            ->where('inbox_messages.direction', 'out')
            ->join('conversations', 'conversations.id', '=', 'inbox_messages.conversation_id')
            ->where('conversations.workspace_id', $wsId)
            ->where('inbox_messages.created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /** The broadcast's recipients as { id, phone, name, ...attrs } — mirrors dispatchToBridge. */
    private function recipients(Broadcast $b, ?array $retryContactIds): array
    {
        $q = $b->contacts()->select([
            'contacts.id', 'contacts.country_code', 'contacts.mobile', 'contacts.name',
            'contacts.first_name', 'contacts.last_name', 'contacts.email', 'contacts.custom_attributes',
        ]);
        if (is_array($retryContactIds) && ! empty($retryContactIds)) {
            $q->whereIn('contacts.id', $retryContactIds);
        }

        return $q->get()->map(function ($c) {
            $cc    = preg_replace('/\D+/', '', (string) ($c->country_code ?? ''));
            $local = preg_replace('/\D+/', '', (string) ($c->mobile ?? ''));
            $phone = $cc && $local && strpos($local, $cc) !== 0 ? $cc . $local : $local;

            return [
                'id'                => $c->id,
                'phone'             => $phone,
                'name'              => (string) ($c->name ?? ''),
                'first_name'        => (string) ($c->first_name ?? ''),
                'last_name'         => (string) ($c->last_name ?? ''),
                'email'             => (string) ($c->email ?? ''),
                'custom_attributes' => is_array($c->custom_attributes) ? $c->custom_attributes : [],
            ];
        })->filter(fn ($c) => $c['phone'] !== '')->values()->all();
    }

    /** Replace {{token}} using the same resolver the WhatsApp campaign path uses. */
    private function personalize(string $raw, array $cr, int $wsId, TemplateOverrideResolver $resolver): string
    {
        if ($raw === '' || ! str_contains($raw, '{{')) {
            return $raw;
        }

        return (string) preg_replace_callback(
            TemplateOverrideResolver::TOKEN_RE,
            fn ($m) => $resolver->lookup(trim((string) $m[1]), $cr, $wsId),
            $raw
        );
    }

    /** Update this recipient's broadcast_contacts pivot row. */
    private function markRecipient(Broadcast $b, int $contactId, string $status, string $sid = '', string $error = ''): void
    {
        try {
            $b->contacts()->updateExistingPivot($contactId, array_filter([
                'status'              => $status,
                'whatsapp_message_id' => $sid ?: null,
                'error_message'       => $error ?: null,
                'sent_at'             => $status === 'sent' ? now() : null,
            ], fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            Log::warning('[SMS-BCAST] pivot update failed: ' . $e->getMessage(), ['broadcast_id' => $b->id, 'contact_id' => $contactId]);
        }
    }

    /** Mirror a sent campaign SMS into the unified inbox thread. */
    private function mirrorToInbox(SmsSender $sender, int $wsId, string $phone, string $body, string $sid, int $segments): void
    {
        try {
            $digits = preg_replace('/\D+/', '', $phone);
            $convo = Conversation::firstOrCreate(
                ['workspace_id' => $wsId, 'channel' => 'sms', 'raw_jid' => 'sms:' . $digits],
                ['title' => '+' . $digits, 'provider' => 'sms', 'origin' => 'sms', 'status' => 'pending',
                 'inbox_status' => 'open', 'last_message_at' => now(), 'contact_digits' => $digits],
            );

            InboxMessage::create([
                'conversation_id' => $convo->id,
                'provider'        => 'sms',
                'direction'       => 'out',
                'body'            => $body,
                'status'          => 'sent',
                'meta'            => ['wa_message_id' => $sid, 'sms' => array_filter([
                    'to' => $sender->from_number, 'segments' => $segments, 'provider' => $sender->provider, 'source' => 'broadcast',
                ])],
                'sent_at'         => now(),
            ]);

            $convo->forceFill(['last_message_at' => now(), 'preview' => Str::limit($body, 120)])->save();
            if (Schema::hasColumn('conversations', 'unread_count')) {
                // Outbound — don't bump unread.
            }
        } catch (\Throwable $e) {
            Log::warning('[SMS-BCAST] inbox mirror failed: ' . $e->getMessage());
        }
    }
}
