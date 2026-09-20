<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile-app Inbox bundle.
 *
 * The chat-list / inbox screen used to fan out to SEVEN endpoints on every open
 * (/chats, /groups, /get-contact-groups, /get-queues, /queues/pinned,
 * /all-archive-queue, /chats/archived). This is ONE call that returns all of it.
 *
 * Each sub-payload is the EXACT JSON its own endpoint returns — we invoke the
 * very same controllers and collect their responses — so the app can keep
 * parsing each part with its existing models. Nothing about the individual
 * endpoints changes; they stay for anything that still wants a single slice.
 */
class InboxController extends Controller
{
    /** GET /inbox — everything the chat-list screen needs, in one response. */
    public function bundle(Request $request): JsonResponse
    {
        // ── Delta polling: GET /inbox?since=<ISO8601|unix> ──────────────────
        // Return ONLY the chats / groups / queues that changed at/after `since`,
        // so the chat-list screen can poll every ~4s and jump a freshly-messaged
        // conversation to the top (WhatsApp behaviour) without re-pulling the
        // whole inbox. The heavy, rarely-moving slices (contact groups, pinned /
        // archived) are skipped, and the Node group-bridge is NOT hit — a group
        // IS a conversation, so its changed row already flows through /chats and
        // we just split it back out by is_group. Absent `since` → the full
        // bundle below, byte-identical to before.
        $sinceRaw = trim((string) $request->query('since', ''));
        if ($sinceRaw !== '') {
            $rows   = $this->sliceRows(fn () => app(ChatController::class)->index($request));
            $chats  = array_values(array_filter($rows, fn ($r) => empty($r['is_group'])));
            $groups = array_values(array_filter($rows, fn ($r) => ! empty($r['is_group'])));

            return response()->json([
                'success' => true,
                'data'    => [
                    'chats'       => $chats,
                    'groups'      => $groups,
                    'queues'      => $this->sliceRows(fn () => app(QueueController::class)->getQueues($request)),
                    // The client uses this as the NEXT `since` — the server's own
                    // clock, so a device-clock skew can never skip a message.
                    'server_time' => now()->toIso8601String(),
                ],
            ]);
        }

        // ── Full bundle ─────────────────────────────────────────────────────
        // Each is invoked with the SAME request, so filters (e.g. ?filter=all)
        // and the X-Workspace-Id / X-Device-Id scoping apply uniformly. A single
        // slice failing must not fail the whole bundle — wrap each so the app
        // still gets the parts that succeeded.
        $slice = function (callable $fn) {
            try {
                return $fn()->getData(true);
            } catch (\Throwable $e) {
                \Log::warning('[inbox-bundle] slice failed: ' . $e->getMessage());
                return ['success' => false, 'error' => 'slice_failed'];
            }
        };

        // The `chats` bucket must EXCLUDE groups — groups have their OWN bucket
        // (GroupController::index) carrying the REAL announce_only from Node's
        // group metadata. ChatController::index lists EVERY conversation (groups
        // included), so without this filter each group appeared in BOTH buckets:
        // once in chats with a stale announce_only=false, once in groups with the
        // true value — the app dev's "group shows twice, one true one false".
        // This mirrors the delta path (which already splits chats = non-groups).
        $chatsSlice = $slice(fn () => app(ChatController::class)->index($request));
        if (isset($chatsSlice['data']) && is_array($chatsSlice['data'])) {
            $chatsSlice['data'] = array_values(array_filter(
                $chatsSlice['data'],
                fn ($r) => is_array($r) ? empty($r['is_group']) : true
            ));
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'chats'           => $chatsSlice,
                'groups'          => $slice(fn () => app(GroupController::class)->index($request)),
                'contact_groups'  => $slice(fn () => app(ContactGroupController::class)->index($request)),
                'queues'          => $slice(fn () => app(QueueController::class)->getQueues($request)),
                'pinned_queues'   => $slice(fn () => app(QueueController::class)->getPinnedQueues($request)),
                'archived_queues' => $slice(fn () => app(QueueController::class)->all_archive_queue($request)),
                'archived_chats'  => $slice(fn () => app(ChatController::class)->archivedIndex($request)),
                'server_time'     => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Invoke a sub-endpoint and return just its `data` array of rows (the shape
     * the delta merges by id). Never throws — a failed slice degrades to [].
     */
    private function sliceRows(callable $fn): array
    {
        try {
            $r = $fn()->getData(true);
            return is_array($r['data'] ?? null) ? $r['data'] : [];
        } catch (\Throwable $e) {
            \Log::warning('[inbox-bundle] delta slice failed: ' . $e->getMessage());
            return [];
        }
    }
}
