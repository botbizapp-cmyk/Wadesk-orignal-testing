<?php

namespace App\Services\Sms;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\WaProviderConfig;
use App\Models\WaTemplate;
use App\Models\WpCampaign;
use App\Models\WpCampaignContact;
use App\Services\TemplateOverrideResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends an SMS campaign from PHP — the campaign twin of SmsBroadcastRunner.
 *
 * WHY. The WhatsApp campaign runtime (runCampaignNowPaced) only speaks the Node
 * WhatsApp transports; it can't reach the PHP Twilio/MSG91 SMS transports. So a
 * campaign whose sender is provider='sms' is intercepted at the top of
 * runCampaignNowPaced and run here instead. It REUSES the campaign builder,
 * audience, scheduling and the campaign index/detail analytics — only the
 * transport differs. Per-recipient status lands in wp_campaign_contacts (so
 * recomputeAggregates() + the detail page light up), each send is mirrored into
 * the unified inbox (so the /api/sms/status webhook can flip it to delivered),
 * and SMS cost is NEVER billed to the WhatsApp wallet.
 */
class SmsCampaignRunner
{
    /** @param array<int,int> $contactIds recipients resolved by the campaign store/dispatch */
    public function run(WpCampaign $campaign, array $contactIds): array
    {
        $wsId = (int) $campaign->workspace_id;

        $sender = SmsSender::fromConfig($campaign->device_id ? WaProviderConfig::find($campaign->device_id) : null)
            ?? SmsSender::firstForWorkspace($wsId);

        if (! $sender || ! $sender->isSendable()) {
            $campaign->forceFill(['status' => 'failed'])->save();
            Log::warning('[SMS-CAMPAIGN] no sendable SMS number', ['campaign_id' => $campaign->id, 'device_id' => $campaign->device_id]);
            return ['ok' => false, 'error' => 'No sendable SMS number connected'];
        }

        // SMS-only plan cap — never the WhatsApp wallet.
        try {
            \App\Services\PlanLimitGuard::check($campaign->workspace, 'sms_monthly_limit', $this->monthlyOutbound($wsId));
        } catch (\App\Exceptions\PlanLimitReachedException $e) {
            $campaign->forceFill(['status' => 'failed'])->save();
            Log::warning('[SMS-CAMPAIGN] plan limit reached: ' . $e->getMessage(), ['campaign_id' => $campaign->id]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // Body — a template's plain-text body, else the free-text custom_message.
        $bodyRaw = $campaign->template_id
            ? (string) (optional(WaTemplate::find($campaign->template_id))->template_body ?? '')
            : (string) ($campaign->custom_message ?? '');

        $recipients = $this->recipients($wsId, $contactIds);
        if (empty($recipients)) {
            $campaign->forceFill(['status' => 'failed'])->save();
            return ['ok' => false, 'error' => 'No recipients with a usable phone number'];
        }

        $resolver = app(TemplateOverrideResolver::class);
        $client   = new SmsClient($sender);

        $sent = 0; $failed = 0; $segments = 0;

        foreach ($recipients as $cr) {
            $phone = (string) $cr['phone'];
            $body  = $this->personalize($bodyRaw, $cr, $wsId, $resolver);
            if ($phone === '' || trim($body) === '') {
                $failed++;
                $this->markRecipient($campaign, (int) $cr['id'], 'failed', '', 'Empty phone or message');
                continue;
            }

            $res = $client->send($phone, $body);
            $seg = (int) ($res['segments'] ?? SmsSegments::measure($body)['segments']);
            $segments += $seg;

            if (! empty($res['ok'])) {
                $sent++;
                $sid = (string) ($res['message_id'] ?? '');
                $this->markRecipient($campaign, (int) $cr['id'], 'sent', $sid);
                $this->mirrorToInbox($sender, $wsId, $phone, $body, $sid, $seg);
            } else {
                $failed++;
                $this->markRecipient($campaign, (int) $cr['id'], 'failed', '', (string) ($res['error'] ?? 'SMS send failed'));
            }
        }

        // Roll the per-recipient log into the campaign KPI columns + finalize status.
        $campaign->forceFill([
            'status'       => $failed > 0 ? ($sent > 0 ? 'completed_with_errors' : 'failed') : 'completed',
            'completed_at' => now(),
        ])->save();
        try { $campaign->recomputeAggregates(); } catch (\Throwable $e) { /* KPIs best-effort */ }

        Log::info('[SMS-CAMPAIGN] done', [
            'campaign_id' => $campaign->id, 'sent' => $sent, 'failed' => $failed,
            'segments' => $segments, 'provider' => $sender->provider,
        ]);

        return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'segments' => $segments];
    }

    /** Recipients as { id, phone, name, ...attrs }, opted-out + phoneless dropped. */
    private function recipients(int $wsId, array $contactIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $contactIds)));
        if (empty($ids)) return [];

        return Contact::query()->where('workspace_id', $wsId)
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('is_unsubscribed')->orWhere('is_unsubscribed', false))
            ->get(['id', 'country_code', 'mobile', 'name', 'first_name', 'last_name', 'email', 'custom_attributes'])
            ->map(function ($c) {
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

    private function personalize(string $raw, array $cr, int $wsId, TemplateOverrideResolver $resolver): string
    {
        if ($raw === '' || ! str_contains($raw, '{{')) return $raw;
        return (string) preg_replace_callback(
            TemplateOverrideResolver::TOKEN_RE,
            fn ($m) => $resolver->lookup(trim((string) $m[1]), $cr, $wsId),
            $raw
        );
    }

    /** Update the recipient's wp_campaign_contacts row (pre-created in store()). */
    private function markRecipient(WpCampaign $campaign, int $contactId, string $status, string $sid = '', string $error = ''): void
    {
        try {
            $row = WpCampaignContact::query()
                ->where('campaign_id', $campaign->id)->where('contact_id', $contactId)->first();
            $data = ['status' => $status];
            if ($sid !== '')   $data['whatsapp_message_id'] = $sid;
            if ($status === 'sent') $data['sent_at'] = now();
            if ($error !== '') $data['error_message'] = $error;
            if ($row) {
                $row->forceFill($data)->save();
            } else {
                WpCampaignContact::create($data + ['campaign_id' => $campaign->id, 'contact_id' => $contactId]);
            }
        } catch (\Throwable $e) {
            Log::warning('[SMS-CAMPAIGN] recipient update failed: ' . $e->getMessage(), ['campaign_id' => $campaign->id, 'contact_id' => $contactId]);
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
                'conversation_id' => $convo->id, 'provider' => 'sms', 'direction' => 'out',
                'body' => $body, 'status' => 'sent',
                'meta' => ['wa_message_id' => $sid, 'sms' => array_filter([
                    'to' => $sender->from_number, 'segments' => $segments, 'provider' => $sender->provider, 'source' => 'campaign',
                ])],
                'sent_at' => now(),
            ]);
            $convo->forceFill(['last_message_at' => now(), 'preview' => Str::limit($body, 120)])->save();
        } catch (\Throwable $e) {
            Log::warning('[SMS-CAMPAIGN] inbox mirror failed: ' . $e->getMessage());
        }
    }

    private function monthlyOutbound(int $wsId): int
    {
        return (int) InboxMessage::query()
            ->where('inbox_messages.provider', 'sms')->where('inbox_messages.direction', 'out')
            ->join('conversations', 'conversations.id', '=', 'inbox_messages.conversation_id')
            ->where('conversations.workspace_id', $wsId)
            ->where('inbox_messages.created_at', '>=', now()->startOfMonth())
            ->count();
    }
}
