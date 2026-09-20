<?php

namespace App\Services\AiCrm;

use App\Models\Conversation;
use App\Models\Device;
use App\Models\InboxMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp staff-command gate for the AI CRM Copilot.
 *
 * Called EARLY on every inbound WhatsApp message. If — and only if — the
 * workspace has opted in AND the sender's number matches a manager/admin/owner
 * member's own registered mobile, the message is a CRM command: it's routed to
 * AiCrmCopilotService and the reply is sent back over WhatsApp, and maybeHandle()
 * returns TRUE so the normal inbound pipeline (flows / customer AI) is skipped.
 *
 * Everything else returns FALSE untouched — a customer's message NEVER reaches
 * the Copilot. The exact-number + role gate is the whole safety story.
 */
class WaCopilotRouter
{
    private const CHANNEL = 'whatsapp';
    private const HISTORY_MAX = 10;
    /** Roles allowed to command the CRM over WhatsApp. */
    private const ALLOWED_ROLES = ['owner', 'admin', 'manager'];

    public function __construct(private AiCrmCopilotService $copilot)
    {
    }

    /**
     * @return bool  true = handled as a CRM command (stop normal processing)
     */
    public function maybeHandle(Conversation $convo, string $senderPhone, string $text): bool
    {
        try {
            $wsId = (int) $convo->workspace_id;
            if (!$wsId) return false;

            $ws = Workspace::find($wsId);
            if (!$ws || !$ws->wa_copilot_enabled) return false;

            $text = trim($text);
            if ($text === '') return false;

            $staff = $this->resolveStaff($ws, $senderPhone);
            if (!$staff) return false; // not a registered staff commander → customer, untouched

            // It's a staff command. Run the copilot with a short rolling history.
            $history = $this->history($wsId, $staff->id);
            $result  = $this->copilot->ask($ws, $staff, self::CHANNEL, $history, $text);
            $reply   = (string) ($result['reply'] ?? '');

            $history[] = ['role' => 'user', 'text' => $text];
            $history[] = ['role' => 'assistant', 'text' => $reply];
            $this->setHistory($wsId, $staff->id, $history);

            if ($reply !== '') {
                $this->sendReply($convo, $senderPhone, $reply);
            }
            return true;
        } catch (\Throwable $e) {
            Log::warning('[AI-CRM-WA] gate failed: ' . $e->getMessage());
            return false; // fail open to normal processing — never swallow a customer message
        }
    }

    /**
     * Find the manager/admin/owner member of this workspace whose OWN registered
     * mobile matches the sender. Returns null when the sender isn't such a member.
     */
    private function resolveStaff(Workspace $ws, string $senderPhone): ?User
    {
        $sender = preg_replace('/\D+/', '', $senderPhone);
        if (strlen($sender) < 8) return null;

        $members = $ws->members()->wherePivotIn('role', self::ALLOWED_ROLES)->get();
        foreach ($members as $u) {
            $stored = \App\Models\Contact::canonicalizePhone($u->country_code, $u->mobile);
            if ($stored === '' || strlen($stored) < 8) continue;
            if ($this->phonesMatch($stored, $sender)) {
                return $u;
            }
        }
        return null;
    }

    /** Equal, or one is a suffix of the other (handles cc-with/without), min 8 digits. */
    private function phonesMatch(string $a, string $b): bool
    {
        if ($a === $b) return true;
        $min = min(strlen($a), strlen($b));
        if ($min < 8) return false;
        return substr($a, -$min) === substr($b, -$min);
    }

    /** Create the outbound reply row in the same thread and dispatch it over WhatsApp. */
    private function sendReply(Conversation $convo, string $senderPhone, string $reply): void
    {
        $device = $convo->device_id ? Device::find($convo->device_id) : null;
        $fromNumber = $device
            ? preg_replace('/\D+/', '', (string) ($device->country_code . $device->phone_number))
            : '';
        $toNumber = preg_replace('/\D+/', '', $senderPhone);

        $meta = [];
        if ($convo->raw_jid) $meta['target_jid'] = $convo->raw_jid;
        $meta['ai_crm_copilot'] = true; // marks this as a Copilot reply, not a customer AI reply

        $msg = InboxMessage::create([
            'conversation_id' => $convo->id,
            'user_id'         => $convo->user_id,
            'direction'       => 'out',
            'from_number'     => $fromNumber,
            'to_number'       => $toNumber,
            'body'            => $reply,
            'meta'            => $meta,
            'status'          => 'pending',
            'sent_at'         => now(),
        ]);

        $convo->update([
            'preview'          => mb_substr($reply, 0, 191),
            'last_message_at'  => now(),
            'last_outbound_at' => now(),
        ]);

        try {
            $result = app(\App\Services\InboxDispatcher::class)->send($msg, $convo->platform ?? 'W');
            $msg->update(($result['ok'] ?? false)
                ? ['status' => 'sent', 'sent_at' => now()]
                : ['status' => 'failed', 'failure_reason' => mb_substr((string) ($result['error'] ?? 'dispatch failed'), 0, 191)]);
        } catch (\Throwable $e) {
            Log::warning('[AI-CRM-WA] dispatch failed: ' . $e->getMessage());
            $msg->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 191)]);
        }
    }

    // ---- rolling history (cache) -------------------------------------------

    private function historyKey(int $wsId, int $userId): string
    {
        return "aicrm_wa_history:{$wsId}:{$userId}";
    }
    private function history(int $wsId, int $userId): array
    {
        $h = Cache::get($this->historyKey($wsId, $userId), []);
        return is_array($h) ? $h : [];
    }
    private function setHistory(int $wsId, int $userId, array $history): void
    {
        if (count($history) > self::HISTORY_MAX) {
            $history = array_slice($history, -self::HISTORY_MAX);
        }
        Cache::put($this->historyKey($wsId, $userId), $history, now()->addHours(6));
    }
}
