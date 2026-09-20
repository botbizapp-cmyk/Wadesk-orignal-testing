<?php

namespace App\Services\Push;

use App\Models\UserDeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns a new inbound WhatsApp message into an FCM push to the operators who
 * are pinned to the number that received it. Best-effort and self-contained:
 * NEVER throws (a push failure must not break the inbound webhook), no-ops when
 * FCM isn't configured, throttles bursts, and prunes dead tokens.
 */
class InboxPushNotifier
{
    public function __construct(private readonly FcmService $fcm) {}

    /**
     * @param int      $workspaceId    conversation's workspace
     * @param int|null $deviceId       the number/device that received the message
     * @param int      $conversationId for the tap deep-link
     * @param string   $title          sender display (name · number)
     * @param string   $body           message preview (or "📎 Image", etc.)
     * @param array    $extra          extra data payload (message_id, sender_jid…)
     */
    public function inbound(int $workspaceId, ?int $deviceId, int $conversationId, string $title, string $body, array $extra = []): void
    {
        try {
            if ($workspaceId <= 0 || !$this->fcm->enabled()) return;

            // Burst guard: at most one push per conversation per 3s.
            $throttleKey = 'fcm_throttle_' . $conversationId;
            if (Cache::get($throttleKey)) return;
            Cache::put($throttleKey, 1, 3);

            // Recipients: tokens for this workspace whose pinned device matches
            // the receiving number (or that subscribed to ALL devices, device_id
            // NULL) — same device scoping as the /inbox delta + campaign preflight.
            $rows = UserDeviceToken::query()
                ->where('workspace_id', $workspaceId)
                ->when($deviceId, fn ($q) => $q->where(function ($w) use ($deviceId) {
                    $w->where('device_id', $deviceId)->orWhereNull('device_id');
                }))
                ->get(['fcm_token']);
            if ($rows->isEmpty()) return;

            // Per-chat unread (direction=in, read_at NULL) → drives the body's
            // "+N messages" suffix + Android's per-chat count. Maintained by the
            // inbound writers + reset by POST /chats/{id}/read, so it's the same
            // number the app badges the row with.
            $chatUnread = (int) (\App\Models\Conversation::whereKey($conversationId)->value('unread_count') ?? 0);
            // Total unread across the workspace's shared inbox → the app-icon
            // badge (the operator's overall unread, not just this chat).
            $totalUnread = (int) \App\Models\Conversation::where('workspace_id', $workspaceId)->sum('unread_count');

            // Body: 1 unread → just the message text; 2+ unread → latest text +
            // "· +N messages" (N = the OTHER unread besides the one shown), the
            // WhatsApp "Alice: Sorry for the late reply · +3 messages" pattern.
            $extraMsgs   = max(0, $chatUnread - 1);
            $displayBody = mb_substr($body, 0, 160)
                . ($extraMsgs >= 1 ? ' · +' . $extraMsgs . ' message' . ($extraMsgs === 1 ? '' : 's') : '');

            // Group key shared by EVERY message from this conversation. Android
            // replaces the previous notification carrying the same `tag` (one
            // chat = one tile with the latest message, a single tap clears it);
            // iOS `thread-id` collapses them into one stack. Without these, every
            // message was a standalone notification cleared one at a time.
            $groupTag = 'chat_' . $conversationId;

            $res = $this->fcm->sendToTokens(
                $rows->pluck('fcm_token')->all(),
                ['title' => mb_substr($title, 0, 100), 'body' => mb_substr($displayBody, 0, 200)],
                array_merge([
                    'type'            => 'chat_message',
                    'conversation_id' => (string) $conversationId,
                    'workspace_id'    => (string) $workspaceId,
                    'device_id'       => (string) ($deviceId ?? ''),
                ], $extra),
                // Android: HIGH priority bypasses doze (arrives in seconds, not
                // minutes when idle); `tag` collapses/replaces per conversation.
                ['priority' => 'high', 'notification' => array_filter([
                    'channel_id'         => 'chat_messages',
                    'sound'              => 'default',
                    'tag'                => $groupTag,
                    'notification_count' => $chatUnread > 0 ? $chatUnread : null,
                ], fn ($v) => $v !== null)],
                // iOS: apns-priority 10 = immediate delivery; thread-id groups the
                // chat; badge = the user's TOTAL unread across the inbox.
                ['headers' => ['apns-priority' => '10'], 'payload' => ['aps' => array_filter([
                    'sound'             => 'default',
                    'content-available' => 1,
                    'thread-id'         => $groupTag,
                    'badge'             => $totalUnread > 0 ? $totalUnread : null,
                ], fn ($v) => $v !== null)]]
            );

            // Prune tokens FCM said are dead so we stop pushing to them.
            if (!empty($res['invalid'])) {
                $hashes = array_map(fn ($t) => UserDeviceToken::hashFor((string) $t), $res['invalid']);
                UserDeviceToken::whereIn('token_hash', $hashes)->delete();
            }
            Log::info('[FCM] inbound push', [
                'conv' => $conversationId, 'ws' => $workspaceId, 'device' => $deviceId,
                'sent' => $res['sent'], 'failed' => $res['failed'], 'pruned' => count($res['invalid']),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[FCM] inbound push failed: ' . $e->getMessage());
        }
    }
}
