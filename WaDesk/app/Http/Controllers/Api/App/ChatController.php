<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Device;
use App\Models\Flow;
use App\Models\Message;
use App\Models\WaTemplate;
use App\Services\WhatsAppDispatcher;
use App\Services\WorkspaceEngine;
use App\Enums\WaProvider;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Mobile-app 1-to-1 chat API (B8 · TEAM INBOX).
 *
 * Mirrors the web /chat surface (App\Http\Controllers\ChatController) but
 * with a flat JSON contract the Flutter app can consume directly. Every
 * outbound send routes through the SAME WhatsAppDispatcher the web uses,
 * so Baileys + WABA Cloud API + Twilio all work polymorphically — the
 * caller never has to know which engine the workspace is on.
 *
 * Supported message kinds (POST /chats/{id}/messages):
 *   - text             body string
 *   - media            multipart file: image / video / audio / voice / document
 *   - location         latitude + longitude (+ optional name/address)
 *   - reply / quote    reply_to_message_id (matches any existing message id)
 *   - template         POST /chats/{id}/template { template_id }
 *   - flow             POST /chats/{id}/flow { flow_id }
 *   - reaction         POST /chats/{id}/messages/{m}/react { emoji }
 *
 * All paths are workspace-scoped via Conversation::forCurrentWorkspace().
 */
class ChatController extends Controller
{
    public function __construct(private readonly WhatsAppDispatcher $dispatcher)
    {
    }

    // -----------------------------------------------------------------
    // GET /chats — list conversations
    // -----------------------------------------------------------------
    public function index(Request $request): JsonResponse
    {
        $filter = (string) $request->query('filter', 'all'); // all|archived|scheduled|sent|pending|failed
        $q      = (string) $request->query('q', '');
        // Device scoping — the app's account/device picker sends ?device_id=N
        // (or ?sender=engine:id). Without this the list returned EVERY device's
        // chats, so after switching the picked number the app still showed the
        // OTHER number's conversations. Match conversation.device_id.
        $deviceId = $this->deviceIdFromRequest($request);

        // Delta polling: ?since=<ISO8601> returns ONLY conversations whose
        // last_message_at moved at/after that instant, so the chat-list screen
        // can poll every ~4s and jump a freshly-messaged chat to the top with a
        // new unread badge (WhatsApp behaviour) without re-pulling the whole
        // list. Absent / unparseable → full list, byte-identical to before.
        $since = $this->parseSince($request->query('since'));

        // Multi-engine: a workspace running several engines must SEE chats from
        // every enabled engine, not just its default. forCurrentEngine() scopes
        // to the enabled SET (whereIn) via HasEngineScope; for a single-engine
        // workspace it is byte-identical to the old forEngine(default).
        $items = Conversation::query()
            ->forCurrentWorkspace()
            // Show WhatsApp chat threads (chatOnly's origins) AND every connected
            // non-WhatsApp channel (SMS / Telegram / Facebook / Instagram / TikTok /
            // widget) — those carry origin=<channel>, so chatOnly() alone dropped
            // them and the app inbox only ever showed WhatsApp. Campaign-origin
            // convos still stay out (not chat-origin, channel not engine-agnostic).
            ->where(function ($qq) {
                $qq->whereIn('origin', ['chat', 'inbox', 'chatbot'])
                    ->orWhereIn('channel', \App\Models\Conversation::ENGINE_AGNOSTIC_CHANNELS);
            })
            ->forCurrentEngine()
            // Device filter is WhatsApp-only: a WhatsApp device_id must NOT hide
            // the other channels (SMS / Telegram / Facebook / Instagram / TikTok /
            // widget), which aren't tied to a WhatsApp device (their device_id is
            // null / a different engine). Without the orWhereIn, an app that always
            // sends X-Device-Id (a WhatsApp device) only ever saw WhatsApp threads.
            // So: match the chosen device OR any engine-agnostic channel OR any
            // WhatsApp GROUP (groups are workspace-shared across every number, so
            // they show on whichever number the app pins — matching the detail
            // exemption in convOffPinnedDevice()).
            ->when($deviceId, fn ($qq) => $qq->where(function ($w) use ($deviceId) {
                $w->where('device_id', $deviceId)
                    ->orWhereIn('channel', \App\Models\Conversation::ENGINE_AGNOSTIC_CHANNELS)
                    ->orWhere('raw_jid', 'like', '%@g.us');
            }))
            ->when($since, fn ($qq) => $qq->where('last_message_at', '>=', $since))
            ->filtered($filter)
            ->sorted('date-desc')
            ->limit(300)
            ->get();

        if ($q !== '') {
            $items = Conversation::filterBySearch($items, $q);
        }

        // Collapse any pre-existing duplicate GROUP threads so a group never
        // shows twice (see dedupeGroupThreads + the resolver group guard).
        $items = $this->dedupeGroupThreads($items);

        return response()->json([
            'success'     => true,
            'data'        => $items->map(fn ($c) => $this->presentConversation($c))->values(),
            'total'       => $items->count(),
            // Echoed so a client polling ?since= can use the server's own clock
            // as the next `since` (no device-clock skew — same contract as
            // /chats/{id}/messages?after=).
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Resolve the device the app wants the chat/archived list scoped to. The
     * account/device picker sends `sender=engine:id` (the multi-engine key,
     * same one start() reads) OR a bare `device_id`. Returns the
     * conversation.device_id to match, or null for "all devices" (no picker).
     */
    private function deviceIdFromRequest(Request $request): ?int
    {
        $wsId   = (int) ($request->user()?->current_workspace_id ?? 0);
        // sender=engine:id (multi-engine picker key) wins when present.
        $sender = trim((string) $request->query('sender', ''));
        if ($sender !== '' && $wsId > 0) {
            $picked = WorkspaceEngine::senderForKey($wsId, $sender);
            if ($picked && !empty($picked['id'])) return (int) $picked['id'];
        }
        // Then a bare device id — from ?device_id, OR the X-Device-Id header the
        // app can set ONCE in its HTTP client so EVERY list call auto-scopes to
        // the selected device without adding a query param to each request.
        $did = (int) ($request->query('device_id') ?: $request->header('X-Device-Id', 0));
        return $did > 0 ? $did : null;
    }

    /**
     * Parse a ?since= (inbox delta) / ?after= (thread delta) cursor into a
     * Carbon, or null when absent/unparseable so the caller returns the full
     * set. Accepts a unix timestamp or an ISO-8601 string — same contract as
     * messagesSince()'s `after`.
     */
    private function parseSince($raw): ?\Carbon\Carbon
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;
        try {
            return ctype_digit($raw)
                ? \Carbon\Carbon::createFromTimestamp((int) $raw)
                : \Carbon\Carbon::parse($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * True when a conversation belongs to a DIFFERENT number than the one the
     * app has pinned (validated X-Device-Id → app_device_id). Callers 404 on
     * true so a mutation/detail on a foreign device is invisible, matching the
     * device-scoped list. Absent header → never blocks (back-compat).
     */
    private function convOffPinnedDevice(Request $request, $conv): bool
    {
        $pinned = (int) $request->attributes->get('app_device_id', 0);
        if ($pinned <= 0 || !$conv) {
            return false;
        }
        // Engine-agnostic channels (SMS / Telegram / Facebook / Instagram / TikTok /
        // widget) aren't tied to a WhatsApp device — their device_id is null — so a
        // WhatsApp device pin must NOT hide them. Mirrors the inbox-LIST device
        // exemption; without this, opening a TikTok/SMS/etc thread 404'd with
        // "belongs to another device" whenever the app had pinned a WhatsApp device.
        if (in_array((string) $conv->channel, \App\Models\Conversation::ENGINE_AGNOSTIC_CHANNELS, true)) {
            return false;
        }
        // A WhatsApp GROUP is shared across every number in the workspace (any of
        // them that's a member receives the same posts), so it must NOT be hidden
        // behind the pinned-device filter — otherwise the group thread and its
        // live updates disappear the moment the app pins a different number. This
        // mirrors the group exemption in the chat/archived LIST device filter.
        if (str_ends_with((string) $conv->raw_jid, '@g.us')) {
            return false;
        }
        return (int) $conv->device_id !== $pinned;
    }

    /**
     * Collapse duplicate GROUP threads to ONE row per group so the app never
     * shows the same WhatsApp group twice. Before the resolver group guard, a
     * group both of the workspace's numbers were in produced one thread per
     * number ("came twice — one for admin, one for the member"); those old rows
     * can still exist. Keep the thread with the latest activity (which is also
     * where new posts now land, since the resolver returns the oldest/-lowest-id
     * thread). Non-group rows are keyed by their own id, so they're untouched.
     */
    private function dedupeGroupThreads(\Illuminate\Support\Collection $items): \Illuminate\Support\Collection
    {
        return $items
            ->groupBy(fn ($c) => str_ends_with((string) $c->raw_jid, '@g.us') ? 'g:' . $c->raw_jid : 'c:' . $c->id)
            ->map(fn ($grp) => $grp->sortByDesc(fn ($c) => $c->last_message_at?->getTimestamp() ?? (int) $c->id)->first())
            ->sortByDesc(fn ($c) => $c->last_message_at?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * Resolve a thread message from EITHER store. The app's merged thread
     * (show() / messagesSince()) contains rows from `messages` (legacy /chat +
     * app sends) AND `inbox_messages` (the team-inbox bubbles: inbound WhatsApp,
     * agent/AI replies). The app only holds the row id, so a per-message action
     * (react / star / pin / forward / delete) must look in BOTH — otherwise a
     * reaction on an inbound bubble 404s "Message not found" even though it's
     * right there in the thread.
     *
     * @return array{0: \App\Models\Message|\App\Models\InboxMessage|null, 1: 'message'|'inbox'|null}
     */
    private function resolveThreadMessage(int $conversationId, int $messageId): array
    {
        $m = Message::query()->where('conversation_id', $conversationId)->find($messageId);
        if ($m) return [$m, 'message'];

        $im = \App\Models\InboxMessage::query()->where('conversation_id', $conversationId)->find($messageId);
        if ($im) return [$im, 'inbox'];

        return [null, null];
    }

    // -----------------------------------------------------------------
    // GET /chats/{id} — single conversation + all messages
    // -----------------------------------------------------------------
    public function show(Request $request, int $id): JsonResponse
    {
        $c = Conversation::query()
            ->forCurrentWorkspace()
            ->with(['messages', 'inboxMessages'])
            ->find($id);

        if (! $c) {
            return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);
        }

        // Device scoping — a conversation on a DIFFERENT WhatsApp number than the
        // one the app has picked (validated X-Device-Id → app_device_id) must not
        // open here; 404 so the drill-down matches the (now device-scoped) list.
        // convOffPinnedDevice() exempts engine-agnostic channels (SMS / Telegram /
        // Facebook / Instagram / TikTok / widget) so a WhatsApp pin never hides them.
        if ($this->convOffPinnedDevice($request, $c)) {
            // Distinct from a genuine 404 so the app dev can tell a device-scope
            // reject apart from a missing row (still 404 so the drill-down stays
            // hidden, matching the device-scoped list).
            return response()->json(['success' => false, 'message' => 'Conversation belongs to another device.'], 404);
        }

        // A conversation's history can live in TWO tables: `messages` (legacy
        // /chat + app-sent replies) and `inbox_messages` (the Team-Inbox
        // bubbles the web inbox shows — inbound WhatsApp, agent/AI replies).
        // The mobile thread must mirror the web inbox, so merge both stores and
        // order chronologically. Reading only `messages` here was why real
        // inbox conversations opened blank on the app.
        $messages = collect()
            ->concat($c->messages->map(fn ($m) => [
                'ts'  => $m->created_at,
                'row' => $this->presentMessage($m),
            ]))
            ->concat($c->inboxMessages->map(fn ($m) => [
                'ts'  => $m->created_at,
                'row' => $this->presentInboxMessage($m),
            ]))
            ->sortBy('ts')
            ->pluck('row')
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'conversation' => $this->presentConversation($c),
                'messages'     => $messages,
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // GET /chats/{id}/messages?after=<timestamp> — polling delta.
    //
    // The chat/group detail loads the full thread once via GET /chats/{id},
    // takes the newest message's `server_time`, then polls THIS endpoint every
    // few seconds while the screen is open to fetch ONLY messages newer than
    // that — so new incoming/outgoing bubbles appear live without a full
    // refresh. Same message shape as show(); groups work identically (a group
    // is just a conversation). `after` accepts a unix timestamp or ISO string.
    // -----------------------------------------------------------------
    public function messagesSince(Request $request, int $id): JsonResponse
    {
        $c = Conversation::query()->forCurrentWorkspace()->find($id);
        if (! $c) {
            return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);
        }
        if ($this->convOffPinnedDevice($request, $c)) {
            return response()->json(['success' => false, 'message' => 'Conversation belongs to another device.'], 404);
        }

        // Capture the cursor for the NEXT poll BEFORE querying, so a message
        // written between the query and the response can't be skipped next time.
        $serverTime = now();

        $afterRaw = trim((string) $request->query('after', ''));
        $after    = null;
        if ($afterRaw !== '') {
            try {
                $after = ctype_digit($afterRaw)
                    ? \Carbon\Carbon::createFromTimestamp((int) $afterRaw)
                    : \Carbon\Carbon::parse($afterRaw);
            } catch (\Throwable $e) {
                $after = null;
            }
        }

        // No cursor yet → nothing "new" (the app should load the thread via
        // GET /chats/{id} first, then poll with its server_time).
        if ($after === null) {
            return response()->json([
                'success' => true,
                'data'    => ['messages' => [], 'server_time' => $serverTime->toIso8601String()],
            ]);
        }

        // >= (not >) so a same-second message is never missed; the app dedups by
        // message id. Query only the NEW rows from BOTH stores (never the whole
        // thread) so a busy chat polled every few seconds stays cheap. The
        // per-table cap is a DoS ceiling: a normal poll (recent `after`) returns
        // only a handful of rows, but a pathologically old `after` can never
        // dump the entire history — it returns at most the newest ~300 per store.
        $cap = 300;
        $fresh = collect()
            ->concat($c->messages()->where('created_at', '>=', $after)->orderByDesc('created_at')->limit($cap)->get()
                ->map(fn ($m) => ['ts' => $m->created_at, 'row' => $this->presentMessage($m)]))
            ->concat($c->inboxMessages()->where('created_at', '>=', $after)->orderByDesc('created_at')->limit($cap)->get()
                ->map(fn ($m) => ['ts' => $m->created_at, 'row' => $this->presentInboxMessage($m)]))
            ->sortBy('ts')   // oldest → newest
            ->pluck('row')
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'messages'    => $fresh,
                'server_time' => $serverTime->toIso8601String(),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // POST /chats — start a conversation with a phone number (or fetch
    // the existing one). Returns the conversation id the app should
    // POST follow-up messages to.
    // -----------------------------------------------------------------
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'     => 'required|string|max:32',
            'name'      => 'nullable|string|max:191',
            'device_id' => 'nullable|integer',
            'sender'    => 'nullable|string|max:64', // multi-engine "engine:id" picker key
        ]);

        $user   = $request->user();
        $wsId   = (int) ($user->current_workspace_id ?? 0);
        $digits = preg_replace('/\D+/', '', $data['phone']);
        if ($digits === '') {
            return response()->json(['success' => false, 'message' => 'phone must contain digits.'], 422);
        }

        // Resolve the sender device — multi-engine aware. Priority:
        //   1. `sender=engine:id` composite key — engine is the picker.
        //   2. `device_id` (legacy bare id) — Baileys devices table.
        //   3. First active Baileys device.
        // We need the ENGINE the device actually runs on (not the workspace
        // default) so the dispatcher routes through the right transport on
        // every subsequent send. Picking workspace default here was the
        // multi-engine bug: a workspace with Twilio default + Baileys side-
        // car would stamp 'platform=T' on a Baileys chat and the dispatcher
        // would try to send Twilio messages from a Baileys-paired number.
        $deviceId = null;
        $engine   = null;
        if (! empty($data['sender'])) {
            $picked = WorkspaceEngine::senderForKey($wsId, (string) $data['sender']);
            if ($picked) {
                $deviceId = (int) $picked['id'];
                $engine   = (string) $picked['engine'];
            }
        }
        if (! $deviceId && ! empty($data['device_id'])) {
            $d = Device::query()->forCurrentWorkspace()->find((int) $data['device_id']);
            if ($d) {
                $deviceId = (int) $d->id;
                $engine   = WorkspaceEngine::ENGINE_BAILEYS; // devices table is Baileys-only
            }
        }
        if (! $deviceId) {
            // `active` is the operator's on/off flag, NOT the live session
            // state — a device can sit active=1 while its session is dead. Ask
            // for status=connected too, otherwise a workspace whose only
            // Unofficial number is offline binds every new thread to it and the
            // send fails, even with a healthy WABA/Twilio number available.
            $d = Device::query()->forCurrentWorkspace()
                ->where('active', 1)->where('status', 'connected')
                ->orderByDesc('id')->first();
            if ($d) {
                $deviceId = (int) $d->id;
                $engine   = WorkspaceEngine::ENGINE_BAILEYS;
            }
        }
        if (! $deviceId && ! $engine) {
            // No live Unofficial device — use a connected WABA/Twilio account
            // before falling through to the bare workspace default, so the
            // thread is pinned to a number that can actually deliver.
            $cfg = \App\Models\WaProviderConfig::query()
                ->where('workspace_id', $wsId)
                ->whereIn('provider', ['waba', 'twilio'])
                ->where('status', \App\Models\WaProviderConfig::STATUS_CONNECTED)
                ->orderByDesc('is_primary')->orderByDesc('connected_at')
                ->first();
            if ($cfg) {
                $deviceId = (int) $cfg->id;   // polymorphic, same as conversations.device_id
                $engine   = (string) $cfg->provider;
            }
        }
        if (! $deviceId) {
            // Still nothing live — keep the old behaviour of pinning to an
            // active-but-offline device rather than leaving the thread unbound.
            $d = Device::query()->forCurrentWorkspace()->where('active', 1)->orderByDesc('id')->first();
            if ($d) {
                $deviceId = (int) $d->id;
                $engine   = WorkspaceEngine::ENGINE_BAILEYS;
            }
        }
        // No device on this workspace at all — fall back to workspace default
        // so the chat row still has SOMETHING for the dispatcher to read.
        // The first send will surface a clear error then.
        if (! $engine) {
            $engine = WorkspaceEngine::for($wsId);
        }

        // Find-or-open the thread for this number, SCOPED TO THE PICKED DEVICE.
        // Passing $deviceId makes the resolver partition by the receiving/sending
        // number on a MULTI-number workspace: opening the same contact from
        // device 116 no longer returns device 115's conversation (which then
        // 404'd on POST /chats/{id}/messages because 322 belongs to 115). Each
        // device keeps its own thread for the same number; the app then POSTs
        // messages to the id that matches its pinned device. Single-number
        // workspaces are unaffected — the resolver ignores the device there and
        // keeps ONE THREAD PER NUMBER. (device_id below stamps the new row.)
        $existing = \App\Services\Inbox\ConversationResolver::find((int) $wsId, $digits, $deviceId);
        if ($existing) {
            // Rename-on-existing: a POST that carries a `name` must RENAME the
            // thread, not silently drop it. (The get-or-create only ever set the
            // name on CREATION, so renaming an existing chat was a no-op — the
            // reported bug.) Prefer the dedicated PATCH /chats/{id} endpoint, but
            // keep this working since the app already posts here.
            if (trim((string) ($data['name'] ?? '')) !== '') {
                $this->applyChatName($existing, (string) $data['name']);
            }

            return response()->json([
                'success' => true,
                'data'    => $this->presentConversation($existing),
            ]);
        }

        $legacy = WaProvider::tryFrom($engine)?->legacyCode() ?? 'W';

        $c = Conversation::create([
            'user_id'          => $user->id,
            'workspace_id'     => $wsId ?: null,
            'device_id'        => $deviceId,
            'title'            => ($data['name'] ?? '') ?: $digits,
            'preview'          => null,
            'status'           => 'pending',
            'platform'         => $legacy,
            'provider'         => $engine,
            'origin'           => 'chat',
            'recipients_count' => 1,
            'last_message_at'  => now(),
            'raw_jid'          => $digits . '@s.whatsapp.net',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $this->presentConversation($c),
        ], 201);
    }

    /**
     * PATCH /chats/{id} — rename a thread. Body: { name }. Dedicated endpoint so
     * the intent is explicit (vs. overloading POST /chats). An empty/blank name
     * clears the custom name back to the bare "+phone". Returns the updated
     * conversation in the same shape as every other chat endpoint.
     */
    public function rename(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['name' => 'nullable|string|max:191']);

        $c = Conversation::query()->forCurrentWorkspace()->find($id);
        if (! $c) {
            return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);
        }

        $this->applyChatName($c, (string) ($data['name'] ?? ''));

        return response()->json([
            'success' => true,
            'data'    => $this->presentConversation($c),
        ], 200);
    }

    // -----------------------------------------------------------------
    // POST /chats/{id}/messages — send a message into a conversation.
    // Accepts text + media + location + reply (quoted_message_id).
    // -----------------------------------------------------------------
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'body'                  => 'required_without_all:media,latitude|nullable|string|max:4096',
            'media'                 => 'nullable|file|max:51200|mimes:jpg,jpeg,png,gif,webp,mp4,webm,mov,mp3,wav,m4a,ogg,opus,pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt',
            'media_kind'            => 'nullable|in:image,video,audio,voice,document',
            // Location pin — latitude + longitude required together. Name +
            // address aren't accepted yet because the underlying dispatcher
            // path (/api/send-location) only ships lat/lng; a future patch
            // can plumb them through Baileys's name/address fields.
            'latitude'              => 'nullable|numeric|between:-90,90',
            'longitude'             => 'nullable|numeric|between:-180,180',
            'reply_to_message_id'   => 'nullable|integer',
            'scheduled_at'          => 'nullable|string',
            'timezone'              => 'nullable|string|max:64',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $conversation = Conversation::query()->forCurrentWorkspace()->find($id);
        if (! $conversation) {
            return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);
        }
        // A send to a conversation that lives on a DIFFERENT number than the one
        // the app has pinned is the exact repro in the bug report — say so plainly
        // instead of a generic 404 (this path should now be rare: POST /chats is
        // device-scoped, so the app gets the id matching its pinned device).
        if ($this->convOffPinnedDevice($request, $conversation)) {
            return response()->json(['success' => false, 'message' => 'Conversation belongs to another device.'], 404);
        }

        $data = $validator->validated();
        $tz   = $data['timezone']
            ?? $conversation->scheduled_timezone
            ?? optional($request->user()?->currentWorkspace)->timezone
            ?? config('app.timezone', 'UTC');

        // Optional scheduling.
        $isScheduled = false;
        $scheduledUtc = null;
        if (! empty($data['scheduled_at'])) {
            try {
                $parsed = Carbon::parse($data['scheduled_at'], $tz);
                if ($parsed->lt(now()->addMinute())) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Scheduled time must be at least 1 minute in the future (in ' . $tz . ').',
                    ], 422);
                }
                $scheduledUtc = $parsed->setTimezone('UTC');
                $isScheduled  = true;
                $conversation->update(['scheduled_timezone' => $tz]);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'message' => 'Could not parse scheduled_at.'], 422);
            }
        }

        // Optional media upload.
        $mediaPath = null;
        $mediaType = null;
        if ($request->hasFile('media')) {
            $file     = $request->file('media');
            $origName = $file->getClientOriginalName();
            $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $origName) ?: 'file';
            $mediaPath = $file->storeAs('chat-media', \Illuminate\Support\Str::random(10) . '__' . $safeName, media_disk());
            // Explicit media_kind from the app wins; otherwise derive from extension.
            $mediaType = $data['media_kind'] ?? $this->resolveMediaType($file->getClientOriginalExtension());
        } elseif (! empty($data['latitude']) && ! empty($data['longitude'])) {
            $mediaType = 'location';
        }

        // Resolve sender device + recipient phone exactly the same way
        // the web ChatController does (see comments in that file).
        $devicePhone = null;
        if ($conversation->device_id) {
            $device = Device::query()->forCurrentWorkspace()->find($conversation->device_id);
            if ($device) {
                $devicePhone = preg_replace('/\D+/', '', (string) ($device->country_code . $device->phone_number)) ?: null;
            }
        }
        $toNumber = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'out')
            ->whereNotNull('to_number')
            ->orderByDesc('id')
            ->value('to_number');
        if (! $toNumber) {
            $toNumber = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'in')
                ->whereNotNull('from_number')
                ->orderByDesc('id')
                ->value('from_number');
        }
        if (! $toNumber && ! empty($conversation->raw_jid)) {
            $digits = preg_replace('/\D+/', '', explode('@', (string) $conversation->raw_jid)[0]);
            if ($digits !== '') $toNumber = $digits;
        }
        if (! $toNumber) {
            return response()->json([
                'success' => false,
                'message' => 'No recipient on this conversation.',
            ], 422);
        }

        // Resolve a quoted message if the app passed one — must belong
        // to the same conversation so an operator can't quote across
        // tenants. Stored as quoted_message_id on the new row; the
        // dispatcher passes it through to the engine.
        $quotedId = null;
        if (! empty($data['reply_to_message_id'])) {
            $quoted = Message::query()
                ->where('id', (int) $data['reply_to_message_id'])
                ->where('conversation_id', $conversation->id)
                ->first();
            if ($quoted) $quotedId = $quoted->id;
        }

        // Group sends require the full `@g.us` JID in meta.target_jid so the
        // dispatcher hands it to Node verbatim — formatPhoneNumber would
        // otherwise wrap the group id as @s.whatsapp.net and the message
        // would land on a fabricated user account instead of the group.
        $messageMeta = null;
        if (! empty($conversation->raw_jid) && str_ends_with((string) $conversation->raw_jid, '@g.us')) {
            $messageMeta = ['target_jid' => (string) $conversation->raw_jid];
        }

        // Resolve the engine/platform to send on — independent of which table
        // we write. Reply on the SAME engine the conversation runs on; when the
        // legacy `platform` is NULL, infer from provider → last inbound channel
        // → workspace default so a multi-engine workspace never mis-routes.
        //
        // GROUP HARD RULE — WhatsApp groups (`@g.us` jid) can ONLY go through
        // Baileys (WABA Cloud + Twilio don't support business-to-group). Force
        // baileys and self-heal a legacy/mis-stamped (provider=NULL) group row.
        $isGroupConv = ! empty($conversation->raw_jid) && str_ends_with((string) $conversation->raw_jid, '@g.us');
        if ($isGroupConv) {
            $engineStr = WorkspaceEngine::ENGINE_BAILEYS;
            if ($conversation->provider !== WorkspaceEngine::ENGINE_BAILEYS) {
                $conversation->forceFill([
                    'provider' => WorkspaceEngine::ENGINE_BAILEYS,
                    'platform' => WaProvider::tryFrom(WorkspaceEngine::ENGINE_BAILEYS)?->legacyCode() ?? 'W',
                ])->save();
            }
        } else {
            $engineStr = $conversation->provider
                ?: \App\Models\InboxMessage::where('conversation_id', $conversation->id)
                    ->where('direction', 'in')->whereNotNull('provider')
                    ->orderByDesc('id')->value('provider')
                ?: WorkspaceEngine::for($conversation->workspace_id);
        }
        $engineFallback = WaProvider::tryFrom($engineStr)?->legacyCode() ?? 'W';
        $platform = $isGroupConv ? $engineFallback : ($conversation->platform ?: $engineFallback);

        // ────────────────────────────────────────────────────────────────
        // TWO send paths, ONE shared thread:
        //   • SCHEDULED → legacy `messages` table + WhatsAppDispatcher::schedule.
        //     `inbox_messages` has no scheduled_at column and the team inbox
        //     never schedules, so scheduled replies stay on the old path.
        //   • IMMEDIATE → `inbox_messages` + InboxDispatcher::send — the EXACT
        //     path the web Team Inbox uses. This is what makes a reply typed on
        //     the phone appear in the web inbox (and vice-versa): both surfaces
        //     now write the same table. (Was: Message + WhatsAppDispatcher, which
        //     the web inbox never reads — so app-sends were invisible there.)
        // ────────────────────────────────────────────────────────────────
        if ($isScheduled) {
            $message = Message::create([
                'conversation_id'    => $conversation->id,
                'user_id'            => $request->user()->id,
                'workspace_id'       => $conversation->workspace_id,
                'direction'          => 'out',
                'from_number'        => $devicePhone,
                'to_number'          => $toNumber,
                'body'               => $data['body'] ?? null,
                'media_path'         => $mediaPath,
                'media_type'         => $mediaType,
                'latitude'           => $data['latitude']  ?? null,
                'longitude'          => $data['longitude'] ?? null,
                'status'             => 'pending',
                'scheduled_at'       => $scheduledUtc,
                'quoted_message_id'  => $quotedId,
                'meta'               => $messageMeta,
            ]);
            try {
                $result = $this->dispatcher->schedule($message, $platform);
                $this->applyDispatchResult($message, $result, true);
            } catch (\App\Exceptions\PlanLimitReachedException $e) {
                $message->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 191)]);
                $this->refreshConversationAfterSend($conversation, $message);
                return response()->json(['success' => false, 'error' => 'out_of_credits', 'message' => $e->getMessage() ?: 'Out of message credits.'], 402);
            } catch (\Throwable $e) {
                Log::error('[App\Chat] sendMessage schedule threw', ['conv' => $id, 'err' => $e->getMessage()]);
                $message->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 191)]);
                $this->refreshConversationAfterSend($conversation, $message);
                return response()->json(['success' => false, 'message' => 'Failed to schedule message.', 'error' => $e->getMessage()], 500);
            }
            $this->refreshConversationAfterSend($conversation, $message);
            $fresh = $message->fresh();
            return response()->json([
                'success' => true,
                'message' => 'Message scheduled.',
                'data'    => [
                    'message'      => $this->presentMessage($fresh),
                    'conversation' => $this->presentConversation($conversation->refresh()),
                    'dispatch'     => $result ?? null,
                ],
            ], 201);
        }

        // Immediate send — Team-Inbox path (mirrors TeamInboxController).
        $inboxMeta = is_array($messageMeta) ? $messageMeta : [];
        if ($quotedId) $inboxMeta['quoted_message_id'] = $quotedId;
        $message = \App\Models\InboxMessage::create([
            'conversation_id' => $conversation->id,
            'user_id'         => $request->user()->id,
            'direction'       => 'out',
            'from_number'     => $devicePhone,
            'to_number'       => $toNumber,
            'body'            => $data['body'] ?? null,
            'media_path'      => $mediaPath,
            'media_type'      => $mediaType,
            'latitude'        => $data['latitude']  ?? null,
            'longitude'       => $data['longitude'] ?? null,
            'status'          => 'pending',
            'meta'            => $inboxMeta ?: null,
        ]);
        try {
            $result = app(\App\Services\InboxDispatcher::class)->send($message, $platform);
            if (($result['ok'] ?? false) === true) {
                $update = ['status' => 'sent', 'sent_at' => now()];
                // Stash wa_message_id (lives in meta on inbox rows) so the
                // bubble can be pinned / starred / reacted to / status-tracked.
                if (! empty($result['provider_id'])) {
                    $existing = is_array($message->meta) ? $message->meta : [];
                    $update['meta'] = array_merge($existing, ['wa_message_id' => (string) $result['provider_id']]);
                }
                $message->update($update);
            } else {
                $message->update(['status' => 'failed', 'failure_reason' => mb_substr((string) ($result['error'] ?? 'unknown error'), 0, 191)]);
            }
        } catch (\App\Exceptions\PlanLimitReachedException $e) {
            $message->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 191)]);
            $this->touchConversationOutbound($conversation, $message);
            return response()->json(['success' => false, 'error' => 'out_of_credits', 'message' => $e->getMessage() ?: 'Out of message credits.'], 402);
        } catch (\Throwable $e) {
            Log::error('[App\Chat] sendMessage dispatcher threw', ['conv' => $id, 'err' => $e->getMessage()]);
            $message->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 191)]);
            $this->touchConversationOutbound($conversation, $message);
            return response()->json(['success' => false, 'message' => 'Failed to send message.', 'error' => $e->getMessage()], 500);
        }

        $this->touchConversationOutbound($conversation, $message);

        // Surface the REAL dispatch outcome — status='failed' when the engine
        // returned ok=false (device offline, group rejected, bad number) so the
        // app never shows "sent" while nothing arrived.
        $fresh = $message->fresh();
        if ($fresh && $fresh->status === 'failed') {
            return response()->json([
                'success' => false,
                'message' => 'Message could not be delivered.',
                'error'   => $fresh->failure_reason ?: 'The messaging engine rejected the send.',
                'data'    => [
                    'message'      => $this->presentInboxMessage($fresh),
                    'conversation' => $this->presentConversation($conversation->refresh()),
                    'dispatch'     => $result ?? null,
                ],
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Message sent.',
            'data'    => [
                'message'      => $this->presentInboxMessage($fresh),
                'conversation' => $this->presentConversation($conversation->refresh()),
                'dispatch'     => $result ?? null,
            ],
        ], 201);
    }

    /**
     * Update a conversation's list-row denormals after an immediate inbox send.
     * (refreshConversationAfterSend() reads the `messages` table, so it can't be
     * reused for the inbox path — this mirrors what the web inbox writes.)
     */
    private function touchConversationOutbound(Conversation $conversation, \App\Models\InboxMessage $message): void
    {
        $body = self::safeAttr($message, 'body');
        $conversation->forceFill([
            'preview'          => ($body !== null && $body !== '')
                ? mb_substr($body, 0, 191)
                : ($message->media_type ? '[' . $message->media_type . ']' : $conversation->preview),
            'last_message_at'  => $message->sent_at ?: $message->created_at ?: now(),
            'last_outbound_at' => now(),
        ])->save();
    }

    // -----------------------------------------------------------------
    // POST /chats/{id}/template — send a saved template into a chat.
    // -----------------------------------------------------------------
    public function sendTemplate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'template_id' => 'required|integer|exists:wa_templates,id',
        ]);

        $conversation = Conversation::query()->forCurrentWorkspace()->find($id);
        if (! $conversation) {
            return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);
        }

        $template = WaTemplate::query()->find($data['template_id']);
        if (! $template) {
            return response()->json(['success' => false, 'message' => 'Template not found.'], 404);
        }

        // Resolve recipient + device (same rule as sendMessage).
        $devicePhone = null;
        if ($conversation->device_id) {
            $device = Device::query()->forCurrentWorkspace()->find($conversation->device_id);
            if ($device) {
                $devicePhone = preg_replace('/\D+/', '', (string) ($device->country_code . $device->phone_number)) ?: null;
            }
        }
        $toNumber = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('to_number')
            ->orderByDesc('id')
            ->value('to_number');
        if (! $toNumber && ! empty($conversation->raw_jid)) {
            $digits = preg_replace('/\D+/', '', explode('@', (string) $conversation->raw_jid)[0]);
            if ($digits !== '') $toNumber = $digits;
        }
        if (! $toNumber) {
            return response()->json(['success' => false, 'message' => 'No recipient on this conversation.'], 422);
        }

        // Resolve the conversation's contact so {{name}}/{{1}} etc. resolve
        // off the real contact attributes — mirror the web sendTemplate.
        $jidDigits = preg_replace('/\D+/', '', (string) ($conversation->raw_jid ?? '')) ?: $toNumber;
        $contact = null;
        if ($jidDigits !== '') {
            $last10 = substr($jidDigits, -10);
            $contact = \App\Models\Contact::query()
                ->where('workspace_id', $conversation->workspace_id)
                ->whereNotNull('mobile')
                ->get()
                ->first(function ($c) use ($jidDigits, $last10) {
                    $d = preg_replace('/\D+/', '', (string) $c->mobile);
                    return $d !== '' && ($d === $jidDigits || str_ends_with($d, $jidDigits)
                        || ($last10 !== '' && str_ends_with($d, $last10)));
                });
        }

        // Auth (OTP) templates: mint a fresh 6-digit code per send so any
        // {{1}}/{{otp}}/{{code}} placeholder + the copy-code button payload
        // share the same value.
        $category = strtolower((string) ($template->meta_category ?? $template->category ?? ''));
        $isAuth   = $category === 'authentication';
        $otpCode  = $isAuth ? (string) random_int(100000, 999999) : null;

        // Flatten variable_map → {slot => key} so positional {{1}} can resolve
        // to a contact attribute. variable_map persists in the nested shape
        // {header:[{num,key}], body:[{num,key}]}; tolerate the legacy flat
        // {"1":"name"} too. Same as web's sendTemplate.
        $variableMap = is_array($template->variable_map) ? $template->variable_map : [];
        $rawBodyMap  = is_array($variableMap['body'] ?? null) ? $variableMap['body'] : [];
        $bodyMap = [];
        foreach ($rawBodyMap as $slot => $entry) {
            if (is_array($entry) && isset($entry['num'], $entry['key']) && $entry['key'] !== '') {
                $bodyMap[(string) $entry['num']] = (string) $entry['key'];
            } elseif (is_string($entry) && $entry !== '') {
                $bodyMap[(string) $slot] = $entry;
            }
        }

        // Scalarise a custom value — a custom attribute may hold a nested array;
        // casting that straight to string would warn + emit "Array".
        $scalar = fn ($v) => is_scalar($v) ? (string) $v : '';
        $contactAttr = function (string $key) use ($contact, $jidDigits, $scalar): string {
            $norm = str_replace([' ', '-'], '_', strtolower(trim($key)));
            if (! $contact) {
                return in_array($norm, ['phone', 'mobile', 'number', 'phone_number', 'whatsapp'], true) ? $jidDigits : '';
            }
            // Fixed attributes → the REAL Contact columns. The Contact table uses
            // `mobile` (NOT `phone_number`) and has no `company` column, so the old
            // aliases silently resolved {{phone}} etc. to blank. Cover every fixed
            // key GET /attributes exposes (address / country_code / last_name /
            // language / title / subject …) so they all resolve on send.
            $aliases = [
                'name' => 'name', 'full_name' => 'name',
                'first_name' => 'first_name', 'middle_name' => 'middle_name', 'last_name' => 'last_name',
                'title' => 'title', 'subject' => 'subject', 'language' => 'language', 'address' => 'address',
                'email' => 'email', 'country_code' => 'country_code',
                'phone' => 'mobile', 'mobile' => 'mobile', 'number' => 'mobile',
                'phone_number' => 'mobile', 'whatsapp' => 'mobile',
            ];
            if (isset($aliases[$norm])) {
                $col = $aliases[$norm];
                $val = $scalar($contact->{$col} ?? '');
                if ($val === '' && $col === 'mobile') $val = $jidDigits; // fall back to thread number
                if ($val === '' && $col === 'name') {
                    $val = trim($scalar($contact->first_name ?? '') . ' ' . $scalar($contact->last_name ?? ''));
                }
                return $val;
            }
            // Custom attributes — exact, normalised, then a CASE-INSENSITIVE match.
            // The stored key casing ("Order ID" / "orderId") often differs from the
            // {{token}} the template uses, which is why custom attrs "didn't go".
            $custom = is_array($contact->custom_attributes ?? null) ? $contact->custom_attributes : [];
            if (array_key_exists($key, $custom))  return $scalar($custom[$key]);
            if (array_key_exists($norm, $custom)) return $scalar($custom[$norm]);
            foreach ($custom as $ck => $cv) {
                if (strcasecmp(str_replace([' ', '-'], '_', (string) $ck), $norm) === 0) return $scalar($cv);
            }
            return '';
        };

        $resolveToken = function (string $key) use ($bodyMap, $otpCode, $contactAttr): string {
            $lower = strtolower($key);
            if ($otpCode !== null && in_array($lower, ['1', 'otp', 'code'], true)) return $otpCode;
            if (ctype_digit($key)) {
                $named = $bodyMap[$key] ?? null;
                return $named !== null && $named !== '' ? $contactAttr((string) $named) : '';
            }
            return $contactAttr($key);
        };

        $substitute = fn (string $text) => preg_replace_callback(
            '/\{\{\s*([^{}]+?)\s*\}\}/',
            fn ($m) => $resolveToken(trim((string) $m[1])),
            $text
        );

        $resolvedBody   = $substitute((string) $template->template_body);
        $resolvedHeader = $template->header ? $substitute((string) $template->header) : '';
        $resolvedFooter = (string) ($template->footer ?? '');

        // Resolve each button — substitute placeholders in value + text. Drop
        // structurally-invalid action buttons (URL/call/copy with empty value)
        // because WhatsApp strips the WHOLE button set when one is invalid.
        $resolvedButtons = [];
        foreach ((is_array($template->buttons) ? $template->buttons : []) as $b) {
            if (! is_array($b)) continue;
            $b['value'] = isset($b['value']) ? $substitute((string) $b['value']) : '';
            $b['text']  = isset($b['text'])  ? $substitute((string) $b['text'])  : '';
            if ($isAuth && in_array(($b['type'] ?? ''), ['copy_code', 'otp_copy', 'otp_one_tap'], true)) {
                $b['value'] = $otpCode ?? $b['value'];
            }
            $btype = strtolower((string) ($b['type'] ?? ''));
            $bval  = trim((string) ($b['value'] ?? ''));
            $burl  = trim((string) ($b['url'] ?? ''));
            $isQuickReply = $btype === '' || in_array($btype, ['quick_reply', 'reply', 'quick reply'], true);
            if (! $isQuickReply && $bval === '' && $burl === '') continue;
            $resolvedButtons[] = $b;
        }

        // Positional template_vars for the Twilio ContentSid path.
        $templateVars = [];
        foreach ($bodyMap as $pos => $named) {
            if (! is_string($named) && ! is_numeric($named)) continue;
            $templateVars[(string) $pos] = $resolveToken((string) $named);
        }
        if ($otpCode !== null && ! isset($templateVars['1'])) $templateVars['1'] = $otpCode;

        // Group sends need the full @g.us JID in meta.target_jid so the
        // dispatcher hands it to Node verbatim (otherwise the digits get
        // wrapped as @s.whatsapp.net and the message lands on a fake user).
        $targetJid = null;
        if (! empty($conversation->raw_jid) && str_ends_with((string) $conversation->raw_jid, '@g.us')) {
            $targetJid = (string) $conversation->raw_jid;
        }

        $messageMeta = array_filter([
            'template_id'   => $template->id,
            'template_name' => $template->template_name,
            'category'      => $category ?: null,
            'template_type' => $template->template_type ?: null,
            // Carousel cards must ride along or the dispatcher sends only
            // the body text and drops every card.
            'carousel_data' => ($template->template_type === 'carousel' && ! empty($template->carousel_data)) ? $template->carousel_data : null,
            'buttons'       => $resolvedButtons ?: null,
            'header'        => $resolvedHeader ?: null,
            // LOCATION header — ships as a location pin (Unofficial API) or
            // Meta's location header param (WABA), handled downstream.
            'header_location' => (is_array($template->header_location) && ! empty($template->header_location)) ? $template->header_location : null,
            'footer'        => $resolvedFooter ?: null,
            'otp_code'      => $otpCode,
            'template_vars' => $templateVars ?: null,
            'target_jid'    => $targetJid,
        ], fn ($v) => $v !== null && $v !== '');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id'         => $request->user()->id,
            'workspace_id'    => $conversation->workspace_id,
            'direction'       => 'out',
            'device_id'       => $conversation->device_id,
            'from_number'     => $devicePhone,
            'to_number'       => $toNumber,
            'body'            => $resolvedBody,
            'template_id'     => $template->id,
            // Attachment piggybacks on the Message media_* columns so the
            // dispatcher routes through /api/send-media-message for image/
            // video/document templates instead of just sending text.
            'media_path'      => $template->attachment_file ?: null,
            'media_type'      => $template->attachment_type ?: null,
            'status'          => 'pending',
            'meta'            => $messageMeta ?: null,
        ]);

        \Illuminate\Support\Facades\Log::info('[App\Chat] sendTemplate', [
            'tpl'     => $template->id,
            'conv'    => $conversation->id,
            'buttons' => count($resolvedButtons),
            'media'   => $template->attachment_type ? $template->attachment_type . ':' . $template->attachment_file : null,
            'carousel'=> $template->template_type === 'carousel',
            'otp'     => $otpCode !== null,
        ]);

        // Reply on the SAME engine the conversation runs on. When the legacy
        // `platform` column is NULL, infer from the conversation's own provider,
        // then its last inbound channel, and only then the workspace default —
        // so a multi-engine workspace never mis-routes a reply.
        //
        // GROUP HARD RULE — see sendMessage() for the full explanation. Groups
        // (`@g.us` jid) ONLY work on Baileys; Twilio/WABA-Cloud silently drop
        // group sends. Force Baileys when the conversation is a group so a
        // multi-engine workspace where Twilio is default doesn't ship the
        // template into a black hole.
        $isGroupConv = ! empty($conversation->raw_jid) && str_ends_with((string) $conversation->raw_jid, '@g.us');
        if ($isGroupConv) {
            $engineStr = WorkspaceEngine::ENGINE_BAILEYS;
            // Self-heal — same logic as sendMessage(). Stamp provider=baileys
            // onto the row so the team-inbox / analytics / next send see the
            // right engine without re-doing this detection.
            if ($conversation->provider !== WorkspaceEngine::ENGINE_BAILEYS) {
                $conversation->forceFill([
                    'provider' => WorkspaceEngine::ENGINE_BAILEYS,
                    'platform' => WaProvider::tryFrom(WorkspaceEngine::ENGINE_BAILEYS)?->legacyCode() ?? 'W',
                ])->save();
            }
        } else {
            $engineStr = $conversation->provider
                ?: \App\Models\InboxMessage::where('conversation_id', $conversation->id)
                    ->where('direction', 'in')->whereNotNull('provider')
                    ->orderByDesc('id')->value('provider')
                ?: WorkspaceEngine::for($conversation->workspace_id);
        }
        $engineFallback = WaProvider::tryFrom($engineStr)?->legacyCode() ?? 'W';
        $platform = $isGroupConv ? $engineFallback : ($conversation->platform ?: $engineFallback);
        try {
            $result = $this->dispatcher->send($message, $platform);
            $this->applyDispatchResult($message, $result, false);
        } catch (\Throwable $e) {
            Log::error('[App\Chat] sendTemplate threw', ['conv' => $id, 'err' => $e->getMessage()]);
            $message->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 191)]);
            $this->refreshConversationAfterSend($conversation, $message);
            return response()->json(['success' => false, 'message' => 'Failed to send template.', 'error' => $e->getMessage()], 500);
        }

        $this->refreshConversationAfterSend($conversation, $message);

        // Surface the real engine outcome (see sendMessage() for the rationale)
        // instead of always claiming success.
        $fresh = $message->fresh();
        if ($fresh && $fresh->status === 'failed') {
            return response()->json([
                'success' => false,
                'message' => 'Template could not be delivered.',
                'error'   => $fresh->failure_reason ?: 'The messaging engine rejected the send.',
                'data'    => [
                    'message'      => $this->presentMessage($fresh),
                    'conversation' => $this->presentConversation($conversation->refresh()),
                    'dispatch'     => $result,
                ],
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Template sent.',
            'data'    => [
                'message'      => $this->presentMessage($fresh),
                'conversation' => $this->presentConversation($conversation->refresh()),
                'dispatch'     => $result,
            ],
        ], 201);
    }

    // -----------------------------------------------------------------
    // POST /chats/{id}/flow — start a flow for this contact.
    // The Node bridge owns flow execution; we just kick it off and let
    // it send the first node's message back through the engine.
    // -----------------------------------------------------------------
    public function startFlow(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'flow_id' => 'required|integer|exists:flows,id',
        ]);

        $conversation = Conversation::query()->forCurrentWorkspace()->find($id);
        if (! $conversation || $this->convOffPinnedDevice($request, $conversation)) {
            return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);
        }

        $flow = Flow::query()->find($data['flow_id']);
        if (! $flow || ! $flow->is_active) {
            return response()->json(['success' => false, 'message' => 'Flow not found or inactive.'], 404);
        }
        if ((int) ($flow->workspace_id ?? 0) !== (int) $conversation->workspace_id) {
            return response()->json(['success' => false, 'message' => 'Flow does not belong to this workspace.'], 403);
        }

        // Recipient + device — flow start needs the sender device's phone.
        $devicePhone = null;
        if ($conversation->device_id) {
            $device = Device::query()->forCurrentWorkspace()->find($conversation->device_id);
            if ($device) {
                $devicePhone = preg_replace('/\D+/', '', (string) ($device->country_code . $device->phone_number)) ?: null;
            }
        }
        $toNumber = ! empty($conversation->raw_jid)
            ? preg_replace('/\D+/', '', explode('@', (string) $conversation->raw_jid)[0])
            : '';
        if (! $devicePhone || ! $toNumber) {
            return response()->json(['success' => false, 'message' => 'No device or recipient on this conversation.'], 422);
        }

        $nodeUrl = rtrim((string) (\App\Models\SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', '')), '/');
        if ($nodeUrl === '') {
            return response()->json(['success' => false, 'message' => 'Node bridge URL not configured.'], 500);
        }

        try {
            $r = \Illuminate\Support\Facades\Http::withHeaders([
                    'X-Node-Token' => node_token(),
                ])
                ->timeout(15)
                ->acceptJson()
                ->post($nodeUrl . '/api/flow/start/' . rawurlencode($devicePhone), [
                    'flowId'            => $flow->id,
                    'targetPhoneNumber' => $toNumber,
                    'campaignId'        => null,
                    'contactId'         => null,
                ]);
            if (! $r->successful()) {
                return response()->json(['success' => false, 'message' => 'Flow start failed.', 'error' => mb_substr((string) $r->body(), 0, 200)], 502);
            }
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Flow start failed.', 'error' => $e->getMessage()], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Flow started.',
            'data'    => ['flow_id' => $flow->id, 'flow_name' => $flow->flow_name, 'conversation_id' => $conversation->id],
        ]);
    }

    // -----------------------------------------------------------------
    // POST /chats/{id}/read — mark all inbound messages read.
    // Sends an `MD_READ` ACK through the engine so the customer sees the
    // blue ticks (the dispatcher's read-receipts pipeline handles this).
    // -----------------------------------------------------------------
    public function markRead(Request $request, int $id): JsonResponse
    {
        $c = Conversation::query()->forCurrentWorkspace()->find($id);
        if (! $c || $this->convOffPinnedDevice($request, $c)) return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);

        Message::query()
            ->where('conversation_id', $c->id)
            ->where('direction', 'in')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        // Inbound WhatsApp bubbles live in inbox_messages — clear those too, and
        // zero the canonical conversations.unread_count the badge now reads, so
        // opening a chat actually clears its unread indicator.
        \App\Models\InboxMessage::query()
            ->where('conversation_id', $c->id)
            ->where('direction', 'in')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        if ((int) ($c->unread_count ?? 0) !== 0) {
            $c->forceFill(['unread_count' => 0])->save();
        }

        return response()->json(['success' => true, 'message' => 'Marked as read.']);
    }

    // -----------------------------------------------------------------
    // POST /chats/{id}/assign-agent — attach / detach an AI agent to this
    // conversation. Body: { agent_id: <int> } to attach, or { agent_id: null }
    // (or omit) to detach. Mirrors the web Team-Inbox "Assign AI agent" action
    // (TeamInboxController@assignAgent) — sets Conversation.assignee_agent_id,
    // which is the SAME field both the Baileys and WABA inbound paths read to
    // decide whether the AI auto-replies. Detaching also clears any attached
    // voice-assistant so ALL AI stops.
    // -----------------------------------------------------------------
    public function assignAgent(Request $request, int $id): JsonResponse
    {
        $c = Conversation::query()->forCurrentWorkspace()->find($id);
        if (! $c || $this->convOffPinnedDevice($request, $c)) return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);

        $data    = $request->validate(['agent_id' => 'nullable|integer']);
        $agentId = $data['agent_id'] ?? null;

        // Attaching: the agent must belong to this workspace (403 otherwise).
        if ($agentId) {
            $exists = \App\Models\AiAgent::forWorkspace((int) $request->user()->current_workspace_id)
                ->whereKey($agentId)->exists();
            if (! $exists) {
                return response()->json(['success' => false, 'message' => 'AI agent not found.'], 404);
            }
        }

        $old = $c->assignee_agent_id;
        if ($agentId) {
            $c->update(['assignee_agent_id' => $agentId]);
            // Stop any running flow session so the flow doesn't keep replying
            // over the AI agent that just took over.
            $this->endActiveFlowSession($c);
        } else {
            // Full detach — clear the agent AND any voice-assistant meta so both
            // AI triggers stop (parity with the web detach path).
            $meta = is_array($c->routing_meta) ? $c->routing_meta : [];
            unset($meta['voice_assistant_id'], $meta['voice_assistant_name'], $meta['voice_assistant_at']);
            $c->forceFill(['assignee_agent_id' => null, 'routing_meta' => $meta])->save();
        }

        // Best-effort audit trail (never block the response on it).
        try {
            \App\Models\ConversationEvent::record(
                $c->id, $c->workspace_id, $request->user()->id,
                $agentId ? 'agent_assigned' : 'agent_unassigned',
                ['old' => $old, 'new' => $agentId],
            );
        } catch (\Throwable $e) {
            Log::warning('[APP-CHAT] assignAgent event record failed: ' . $e->getMessage());
        }

        return response()->json([
            'success'             => true,
            'message'             => $agentId ? 'AI agent attached.' : 'AI agent detached.',
            'assignee_agent_id'   => $agentId,
            'assignee_agent_name' => $agentId ? self::agentName((int) $agentId) : null,
        ]);
    }

    /**
     * Tell the Baileys/Node runtime to END any active flow session for this
     * conversation's customer, so a manually-attached AI agent cleanly takes
     * over instead of the flow AND the AI both replying. Best-effort — a Node
     * hiccup never blocks the assignment. Only meaningful for the Unofficial
     * (Baileys) engine; WABA/Twilio just get ended:0.
     */
    private function endActiveFlowSession(Conversation $c): void
    {
        try {
            $phone = preg_replace('/\D+/', '', (string) ($c->raw_jid ?: ''));
            if ($phone === '' && $c->contact_id) {
                $phone = preg_replace('/\D+/', '', (string) (Contact::query()
                    ->whereKey($c->contact_id)->value('phone') ?? ''));
            }
            if ($phone === '') return;

            $serverUrl = '';
            $cfg = \App\Models\WaProviderConfig::query()->primaryForWorkspace($c->workspace_id)->first();
            if ($cfg) $serverUrl = (string) ($cfg->creds()['server_url'] ?? '');
            if ($serverUrl === '') {
                $serverUrl = (string) (\App\Models\SystemSetting::get('baileys_server_url') ?: env('SERVER_URL', ''));
            }
            if ($serverUrl === '') return;

            \Illuminate\Support\Facades\Http::timeout(5)
                ->withHeaders(['X-Node-Token' => (string) node_token()])
                ->post(rtrim($serverUrl, '/') . '/api/flow-end', [
                    'workspace_id'   => (int) $c->workspace_id,
                    'customer_phone' => $phone,
                ]);
        } catch (\Throwable $e) {
            Log::warning('[APP-CHAT] flow-end call failed: ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // POST /chats/{id}/archive — toggle the archive flag.
    // -----------------------------------------------------------------
    public function archive(Request $request, int $id): JsonResponse
    {
        $c = Conversation::query()->forCurrentWorkspace()->find($id);
        if (! $c || $this->convOffPinnedDevice($request, $c)) return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);

        $c->update(['archived' => ! $c->archived]);

        return response()->json([
            'success' => true,
            'message' => $c->archived ? 'Conversation archived.' : 'Conversation unarchived.',
            'data'    => $this->presentConversation($c),
        ]);
    }

    // -----------------------------------------------------------------
    // GET /chats/archived — list every archived conversation.
    //
    // Returns both 1-to-1 AND group archived threads. Use
    // `?kind=one_to_one|group` to filter to one type; default = both.
    // The list is workspace-scoped, multi-engine aware, sorted newest
    // first, capped at 300. Same row shape as `GET /chats` — each row
    // already carries `is_group` so the app can render either type
    // from the merged feed.
    // -----------------------------------------------------------------
    public function archivedIndex(Request $request): JsonResponse
    {
        $kind  = strtolower((string) $request->query('kind', 'all')); // all|one_to_one|group
        $qStr  = (string) $request->query('q', '');
        // Same device scoping as index() — the Archived list must show only the
        // picked device's archived chats, not every device's (the exact bug the
        // client hit: "connect other device but still shows this device's
        // archived chats").
        $deviceId = $this->deviceIdFromRequest($request);

        $items = Conversation::query()
            ->forCurrentWorkspace()
            ->chatOnly()
            ->forCurrentEngine()
            // Groups are workspace-shared — show on whichever number is pinned
            // (parity with the main chat list + convOffPinnedDevice()).
            ->when($deviceId, fn ($qq) => $qq->where(function ($w) use ($deviceId) {
                $w->where('device_id', $deviceId)->orWhere('raw_jid', 'like', '%@g.us');
            }))
            ->where('archived', true)
            ->sorted('date-desc')
            ->limit(300)
            ->get();

        if ($qStr !== '') {
            $items = Conversation::filterBySearch($items, $qStr);
        }

        // Collapse duplicate group threads to one row per group (parity with the
        // main chat list) before splitting by kind / counting.
        $items = $this->dedupeGroupThreads($items);

        // Split by raw_jid suffix. Group raw_jids always end in `@g.us`.
        if ($kind === 'group') {
            $items = $items->filter(fn ($c) => str_ends_with((string) $c->raw_jid, '@g.us'));
        } elseif ($kind === 'one_to_one' || $kind === 'one-to-one' || $kind === 'dm') {
            $items = $items->filter(fn ($c) => ! str_ends_with((string) $c->raw_jid, '@g.us'));
        }

        $rows = $items->values()->map(fn ($c) => $this->presentConversation($c))->values();

        // Per-kind counts even when no filter applied — saves a second
        // round trip when the app wants tab badges ("DMs · Groups").
        $groupCount  = $rows->filter(fn ($r) => ! empty($r['is_group']))->count();
        $directCount = $rows->count() - $groupCount;

        return response()->json([
            'success' => true,
            'data'    => $rows,
            'total'   => $rows->count(),
            'counts'  => [
                'one_to_one' => $directCount,
                'group'      => $groupCount,
            ],
            'kind'    => $kind,
        ]);
    }

    // -----------------------------------------------------------------
    // DELETE /chats/{id} — delete the conversation + every message.
    // -----------------------------------------------------------------
    public function destroy(Request $request, int $id): JsonResponse
    {
        $c = Conversation::query()->forCurrentWorkspace()->find($id);
        if (! $c || $this->convOffPinnedDevice($request, $c)) return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);

        Message::query()->where('conversation_id', $c->id)->delete();
        $c->delete();

        return response()->json(['success' => true, 'message' => 'Conversation deleted.']);
    }

    // -----------------------------------------------------------------
    // POST /chats/{c}/messages/{m}/react — react to a message with an
    // emoji. Empty string clears the reaction (WhatsApp convention).
    // -----------------------------------------------------------------
    public function messageReact(Request $request, int $c, int $m): JsonResponse
    {
        $data = $request->validate(['emoji' => 'present|string|max:16']);

        $conversation = Conversation::query()->forCurrentWorkspace()->find($c);
        if (! $conversation || $this->convOffPinnedDevice($request, $conversation)) return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);

        // Look in BOTH stores — an inbound bubble lives in inbox_messages.
        [$msg, $store] = $this->resolveThreadMessage((int) $c, (int) $m);
        if (! $msg) return response()->json(['success' => false, 'message' => 'Message not found.'], 404);

        $msg->update(['reaction' => $store === 'inbox' ? ($data['emoji'] ?: null) : $data['emoji']]);
        // Push the reaction through the RIGHT engine for the store: team-inbox
        // bubbles go via InboxDispatcher (what the web inbox uses), legacy /chat
        // rows via WhatsAppDispatcher. (Baileys-only — WABA no-ops silently.)
        try {
            if ($store === 'inbox') {
                app(\App\Services\InboxDispatcher::class)->reaction($msg, $data['emoji']);
            } else {
                $this->dispatcher->reaction($msg, $data['emoji']);
            }
        } catch (\Throwable $e) {
            Log::warning('[App\Chat] reaction dispatch failed', ['msg' => $m, 'store' => $store, 'err' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => $data['emoji'] === '' ? 'Reaction cleared.' : 'Reaction sent.',
            'data'    => $store === 'inbox' ? $this->presentInboxMessage($msg->fresh()) : $this->presentMessage($msg->fresh()),
        ]);
    }

    // -----------------------------------------------------------------
    // PATCH /chats/{c}/messages/{m}/star — toggle a star on a message.
    // -----------------------------------------------------------------
    public function messageToggleStar(Request $request, int $c, int $m): JsonResponse
    {
        $conversation = Conversation::query()->forCurrentWorkspace()->find($c);
        if (! $conversation || $this->convOffPinnedDevice($request, $conversation)) return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);

        [$msg, $store] = $this->resolveThreadMessage((int) $c, (int) $m);
        if (! $msg) return response()->json(['success' => false, 'message' => 'Message not found.'], 404);

        $msg->update(['starred' => ! $msg->starred]);

        return response()->json([
            'success' => true,
            'message' => $msg->starred ? 'Message starred.' : 'Star removed.',
            'data'    => $store === 'inbox' ? $this->presentInboxMessage($msg) : $this->presentMessage($msg),
        ]);
    }

    // -----------------------------------------------------------------
    // DELETE /chats/{c}/messages/{m} — delete a message locally.
    // (Engine-side "delete for everyone" requires the engine's provider
    // message id; we surface a follow-up endpoint for that later.)
    // -----------------------------------------------------------------
    public function messageDestroy(Request $request, int $c, int $m): JsonResponse
    {
        $conversation = Conversation::query()->forCurrentWorkspace()->find($c);
        if (! $conversation || $this->convOffPinnedDevice($request, $conversation)) return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);

        [$msg] = $this->resolveThreadMessage((int) $c, (int) $m);
        if (! $msg) return response()->json(['success' => false, 'message' => 'Message not found.'], 404);

        $msg->delete();

        // The conversation carries a denormalised `preview` / `last_message_at`
        // written when the message was sent/received. Deleting the message
        // (SoftDeletes) removes it from the thread but leaves that cached
        // preview stale — e.g. a removed video keeps showing "[video]".
        // Recompute both from the newest surviving message (soft-deleted rows
        // are excluded by the global scope), or clear them if none remain.
        $this->recomputeConversationPreview($conversation);

        return response()->json(['success' => true, 'message' => 'Message deleted.']);
    }

    // -----------------------------------------------------------------
    // POST /chats/{c}/messages/{m}/pin — pin / unpin a message on the
    // recipient's WhatsApp. Baileys-only (Meta Cloud + Twilio silently
    // no-op via the dispatcher). Works for 1:1, WhatsApp groups, and
    // saved-queue / customer-group chats — the conversation's raw_jid
    // tells the dispatcher which thread to target.
    //
    // Body:
    //   pin       (bool, optional, default true)  — false to UN-pin
    //   duration  (string, optional)              — `24h` (default) | `7d` | `30d`
    // -----------------------------------------------------------------
    public function messagePin(Request $request, int $c, int $m): JsonResponse
    {
        $data = $request->validate([
            'pin'      => 'sometimes|boolean',
            'duration' => 'nullable|in:24h,7d,30d',
        ]);

        $conversation = Conversation::query()->forCurrentWorkspace()->find($c);
        if (! $conversation || $this->convOffPinnedDevice($request, $conversation)) return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);

        [$msg, $store] = $this->resolveThreadMessage((int) $c, (int) $m);
        if (! $msg) return response()->json(['success' => false, 'message' => 'Message not found.'], 404);

        $pin      = (bool) ($data['pin'] ?? true);
        $duration = match ($data['duration'] ?? '24h') {
            '7d'  => 604800,
            '30d' => 2592000,
            default => 86400,
        };

        // Pre-flight — pin requires the engine's wa_message_id stamped on
        // the row at send time. Legacy rows saved before that tracking
        // existed have no id, so the dispatcher would 502 with a vague
        // "engine declined". Return a clean 422 with actionable wording
        // instead of bubbling the dispatcher's internal error code up.
        $msgMeta = is_array($msg->meta) ? $msg->meta : [];
        if (empty($msgMeta['wa_message_id']) && empty($msgMeta['wamid'])) {
            return response()->json([
                'success' => false,
                'code'    => 'message_not_pinnable',
                'message' => 'This message can\'t be pinned — it was saved before WhatsApp-id tracking. Pin only works on messages received or sent AFTER the upgrade. Send a new message and try pinning that one.',
            ], 422);
        }

        try {
            $result = $store === 'inbox'
                ? app(\App\Services\InboxDispatcher::class)->pin($msg, $pin, $duration)
                : $this->dispatcher->pin($msg, $pin, $duration);
        } catch (\Throwable $e) {
            Log::warning('[App\Chat] pin dispatch failed', ['msg' => $m, 'store' => $store, 'err' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Pin failed.', 'error' => $e->getMessage()], 500);
        }

        if (empty($result['ok'])) {
            return response()->json([
                'success' => false,
                'message' => $pin ? 'Pin failed.' : 'Unpin failed.',
                'error'   => $result['error'] ?? 'engine declined',
            ], 502);
        }

        $msg->update(['meta' => array_merge(is_array($msg->meta) ? $msg->meta : [], ['pinned' => $pin])]);

        return response()->json([
            'success' => true,
            'message' => $pin ? 'Message pinned.' : 'Message unpinned.',
            'data'    => $store === 'inbox' ? $this->presentInboxMessage($msg->fresh()) : $this->presentMessage($msg->fresh()),
        ]);
    }

    // -----------------------------------------------------------------
    // POST /chats/{c}/messages/{m}/forward — forward a message into
    // another chat (or several). WhatsApp's native "forward" is a
    // resend with the same content + media + a "Forwarded" hint; we
    // implement it by creating a new outbound row in each target
    // conversation that mirrors the body / media_path / media_type /
    // location of the source message, then dispatching through the
    // same send pipeline used by /chats/{id}/messages.
    //
    // Body:
    //   to_conversation_ids  (int[], required, 1-50)  destination chats
    //
    // Each target conversation must belong to the same workspace; ids
    // belonging to another tenant are silently dropped (counted in
    // `missing_ids`, NOT 403'd).
    // -----------------------------------------------------------------
    public function messageForward(Request $request, int $c, int $m): JsonResponse
    {
        $data = $request->validate([
            'to_conversation_ids'   => 'required|array|min:1|max:50',
            'to_conversation_ids.*' => 'integer',
        ]);

        $sourceConv = Conversation::query()->forCurrentWorkspace()->find($c);
        if (! $sourceConv || $this->convOffPinnedDevice($request, $sourceConv)) return response()->json(['success' => false, 'message' => 'Source conversation not found.'], 404);
        // Source can be in either store (both carry body/media_path/media_type).
        [$source] = $this->resolveThreadMessage((int) $c, (int) $m);
        if (! $source) return response()->json(['success' => false, 'message' => 'Source message not found.'], 404);

        $ids = array_values(array_unique(array_map('intval', (array) $data['to_conversation_ids'])));
        $found = Conversation::query()->forCurrentWorkspace()->whereIn('id', $ids)->get()->keyBy('id');
        $missing = array_values(array_diff($ids, $found->keys()->all()));

        $createdIds = [];
        $errors     = [];

        foreach ($ids as $destId) {
            $destConv = $found->get($destId);
            if (! $destConv) continue;

            // Resolve recipient phone for the destination, mirroring sendMessage's logic.
            $devicePhone = null;
            if ($destConv->device_id) {
                $device = Device::query()->forCurrentWorkspace()->find($destConv->device_id);
                if ($device) {
                    $devicePhone = preg_replace('/\D+/', '', (string) ($device->country_code . $device->phone_number)) ?: null;
                }
            }
            $toNumber = Message::query()
                ->where('conversation_id', $destConv->id)
                ->where('direction', 'out')
                ->whereNotNull('to_number')
                ->orderByDesc('id')
                ->value('to_number')
                ?: Message::query()
                ->where('conversation_id', $destConv->id)
                ->where('direction', 'in')
                ->whereNotNull('from_number')
                ->orderByDesc('id')
                ->value('from_number');
            if (! $toNumber && ! empty($destConv->raw_jid)) {
                $digits = preg_replace('/\D+/', '', explode('@', (string) $destConv->raw_jid)[0]);
                if ($digits !== '') $toNumber = $digits;
            }
            if (! $toNumber) {
                $errors[$destId] = 'no recipient on destination';
                continue;
            }

            // Preserve the TEMPLATE when forwarding one. A template message
            // renders as a full WhatsApp-style card (name/header/body/footer/
            // buttons) from its template_id — and the dispatcher only sends it
            // AS a template (not plain body text) when the template fields ride
            // along in meta. Copying just body/media dropped both, so the
            // forwarded copy arrived as a bare text bubble on the recipient's
            // phone AND rendered plain in the app. Carry the source's template_id
            // column + its structural meta so the destination is identical to a
            // direct template send.
            $srcMeta       = is_array($source->meta ?? null) ? $source->meta : [];
            $srcTemplateId = $source->template_id ?: ($srcMeta['template_id'] ?? null);

            // Group sends require @g.us target_jid (matches sendMessage's pattern).
            $meta = ['forwarded' => true, 'forwarded_from_message_id' => $source->id];
            // Structural template fields the dispatcher + client renderer read.
            // target_jid is intentionally NOT copied (it points at the SOURCE
            // thread) — the destination group jid is set below and must win.
            foreach (['template_id', 'template_name', 'template_type', 'category',
                      'buttons', 'header', 'footer', 'header_location',
                      'carousel_data', 'template_vars'] as $k) {
                if (isset($srcMeta[$k]) && $srcMeta[$k] !== null && $srcMeta[$k] !== '') {
                    $meta[$k] = $srcMeta[$k];
                }
            }
            if (! empty($destConv->raw_jid) && str_ends_with((string) $destConv->raw_jid, '@g.us')) {
                $meta['target_jid'] = (string) $destConv->raw_jid;
            }

            $copy = Message::create([
                'conversation_id' => $destConv->id,
                'user_id'         => $request->user()->id,
                'workspace_id'    => $destConv->workspace_id,
                'direction'       => 'out',
                'from_number'     => $devicePhone,
                'to_number'       => $toNumber,
                'body'            => $source->body,
                // Template id column → the /chats messages response returns it,
                // and the app rebuilds the card from it (templateCard()).
                'template_id'     => $srcTemplateId,
                'media_path'      => $source->media_path,
                'media_type'      => $source->media_type,
                'latitude'        => $source->latitude,
                'longitude'       => $source->longitude,
                'status'          => 'pending',
                'meta'            => $meta,
            ]);

            // Dispatch via the same pipeline as a normal chat send.
            $engineStr = $destConv->provider
                ?: WorkspaceEngine::for($destConv->workspace_id);
            $platform = $destConv->platform ?: (WaProvider::tryFrom($engineStr)?->legacyCode() ?? 'W');
            try {
                $result = $this->dispatcher->send($copy, $platform);
                $this->applyDispatchResult($copy, $result, false);
                // Stamp the provider's wa_message_id on the row so the bridge's
                // fromMe echo (WaInboundController) DEDUPS against THIS row
                // instead of inserting a second identical bubble (issue #1).
                // Stored in BOTH the column (presenter reads it) and meta (the
                // dedup matches on meta.wa_message_id).
                if (! empty($result['provider_id'])) {
                    $wamid    = (string) $result['provider_id'];
                    $existing = is_array($copy->meta) ? $copy->meta : [];
                    $copy->update([
                        'wa_message_id' => $wamid,
                        'meta'          => array_merge($existing, ['wa_message_id' => $wamid]),
                    ]);
                }
                $this->refreshConversationAfterSend($destConv, $copy);
                $createdIds[$destId] = $copy->id;
            } catch (\App\Exceptions\PlanLimitReachedException $e) {
                $copy->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 191)]);
                $errors[$destId] = 'out_of_credits';
            } catch (\Throwable $e) {
                $copy->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 191)]);
                Log::warning('[App\Chat] forward dispatch failed', ['dest' => $destId, 'err' => $e->getMessage()]);
                $errors[$destId] = mb_substr($e->getMessage(), 0, 191);
            }
        }

        return response()->json([
            'success'        => count($createdIds) > 0,
            'message'        => count($createdIds) . ' forward(s) sent.',
            'forwarded_to'   => $createdIds,    // [destConvId => newMessageId]
            'errors'         => $errors,        // [destConvId => reason]
            'missing_ids'    => $missing,
            'source_message' => ['id' => $source->id, 'conversation_id' => $sourceConv->id],
        ]);
    }

    // -----------------------------------------------------------------
    // POST /chats/bulk-delete — delete many chats in one call.
    // Body: { ids: [int, ...] }. Each id is workspace-scoped via the
    // forCurrentWorkspace filter, so a forged id in another tenant just
    // gets skipped (not erased). Messages are removed alongside.
    // -----------------------------------------------------------------
    public function bulkDelete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids'   => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $ids = array_values(array_unique(array_map('intval', (array) $request->input('ids'))));
        $found = Conversation::query()->forCurrentWorkspace()->whereIn('id', $ids)->pluck('id')->all();
        $missing = array_values(array_diff($ids, $found));

        $deleted = 0;
        foreach ($found as $cid) {
            Message::query()->where('conversation_id', $cid)->delete();
            Conversation::query()->forCurrentWorkspace()->where('id', $cid)->delete();
            $deleted++;
        }

        return response()->json([
            'success'       => true,
            'message'       => "Deleted {$deleted} conversation(s).",
            'deleted_count' => $deleted,
            'deleted_ids'   => $found,
            'missing_ids'   => $missing,
        ]);
    }

    // -----------------------------------------------------------------
    // POST /bulk-delete — UNIFIED bulk delete across chats + queues +
    // groups (anything the chat list surfaces). Lets the app dev send
    // ONE multi-select selection from the chat list and have it apply
    // to every resource type at once, instead of having to inspect
    // each row's type and route to three different endpoints.
    //
    // Body:
    //   chat_ids[]          (int[], optional) — Conversation rows (1-to-1 + group chats)
    //   queue_ids[]         (int[], optional) — Broadcast rows (saved queues)
    //   contact_group_ids[] (int[], optional) — ContactGroup rows (segments)
    //
    // At least one of the three arrays is required. Each id is workspace-
    // scoped — ids belonging to another tenant are silently skipped (counted
    // in the per-kind `missing_ids`).
    //
    // Response is per-kind so the app can update its local state
    // selectively (e.g. close 3 chat threads but keep 1 queue's UI open
    // because that one failed to resolve).
    // -----------------------------------------------------------------
    public function bulkDeleteAll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'chat_ids'             => 'nullable|array|max:500',
            'chat_ids.*'           => 'integer',
            'queue_ids'            => 'nullable|array|max:500',
            'queue_ids.*'          => 'integer',
            'contact_group_ids'    => 'nullable|array|max:500',
            'contact_group_ids.*'  => 'integer',
        ]);

        $chatIds    = array_values(array_unique(array_map('intval', (array) ($data['chat_ids']          ?? []))));
        $queueIds   = array_values(array_unique(array_map('intval', (array) ($data['queue_ids']         ?? []))));
        $groupIds   = array_values(array_unique(array_map('intval', (array) ($data['contact_group_ids'] ?? []))));

        if (empty($chatIds) && empty($queueIds) && empty($groupIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Provide at least one of chat_ids[], queue_ids[], contact_group_ids[].',
            ], 422);
        }

        // ── Chats (1-to-1 + WhatsApp groups + queue-chats — every Conversation row)
        $chatsResult = ['deleted_count' => 0, 'deleted_ids' => [], 'missing_ids' => []];
        if (! empty($chatIds)) {
            $found = Conversation::query()->forCurrentWorkspace()->whereIn('id', $chatIds)->pluck('id')->all();
            $chatsResult['missing_ids'] = array_values(array_diff($chatIds, $found));
            foreach ($found as $cid) {
                Message::query()->where('conversation_id', $cid)->delete();
                Conversation::query()->forCurrentWorkspace()->where('id', $cid)->delete();
                $chatsResult['deleted_count']++;
            }
            $chatsResult['deleted_ids'] = $found;
        }

        // ── Queues (Broadcasts) — including their pivot rows so no orphans.
        $queuesResult = ['deleted_count' => 0, 'deleted_ids' => [], 'missing_ids' => []];
        if (! empty($queueIds)) {
            try {
                $foundQ = \App\Models\Broadcast::query()->forCurrentWorkspace()->whereIn('id', $queueIds)->pluck('id')->all();
                $queuesResult['missing_ids'] = array_values(array_diff($queueIds, $foundQ));
                if (! empty($foundQ)) {
                    // Pivot first → then the broadcast. Mirrors WaCampaignsController::bulkDelete.
                    \DB::table('broadcast_contacts')->whereIn('broadcast_id', $foundQ)->delete();
                    \App\Models\Broadcast::query()->forCurrentWorkspace()->whereIn('id', $foundQ)->delete();
                    $queuesResult['deleted_count'] = count($foundQ);
                    $queuesResult['deleted_ids']   = $foundQ;
                }
            } catch (\Throwable $e) {
                Log::warning('[App\Chat] bulkDeleteAll queues failed', ['err' => $e->getMessage()]);
            }
        }

        // ── Contact groups (segments) — pivot + the group row.
        $contactGroupsResult = ['deleted_count' => 0, 'deleted_ids' => [], 'missing_ids' => []];
        if (! empty($groupIds)) {
            try {
                $foundG = \App\Models\ContactGroup::query()->forCurrentWorkspace()->whereIn('id', $groupIds)->pluck('id')->all();
                $contactGroupsResult['missing_ids'] = array_values(array_diff($groupIds, $foundG));
                if (! empty($foundG)) {
                    \DB::table('contact_group_contact')->whereIn('group_id', $foundG)->delete();
                    \App\Models\ContactGroup::query()->forCurrentWorkspace()->whereIn('id', $foundG)->delete();
                    $contactGroupsResult['deleted_count'] = count($foundG);
                    $contactGroupsResult['deleted_ids']   = $foundG;
                }
            } catch (\Throwable $e) {
                Log::warning('[App\Chat] bulkDeleteAll contact groups failed', ['err' => $e->getMessage()]);
            }
        }

        $total = $chatsResult['deleted_count'] + $queuesResult['deleted_count'] + $contactGroupsResult['deleted_count'];

        return response()->json([
            'success'           => true,
            'message'           => "Deleted {$total} item(s).",
            'total_deleted'     => $total,
            'chats'             => $chatsResult,
            'queues'            => $queuesResult,
            'contact_groups'    => $contactGroupsResult,
        ]);
    }

    // -----------------------------------------------------------------
    // POST /chats/{id}/queue-send — send a saved queue's content INTO
    // this chat. The app's "Send saved queue" composer button hits this.
    // For a template queue we forward to sendTemplate; for a custom
    // queue we synthesize a sendMessage body+media call. The dispatch
    // path is identical to a normal chat send — the queue is just a
    // bundle the operator pre-saved.
    //
    // Body: { queue_id: int }
    // -----------------------------------------------------------------
    public function sendQueueIntoChat(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['queue_id' => 'required|integer']);

        $conversation = Conversation::query()->forCurrentWorkspace()->find($id);
        if (! $conversation) {
            return response()->json(['success' => false, 'message' => 'Conversation not found.'], 404);
        }

        $broadcast = \App\Models\Broadcast::query()->forCurrentWorkspace()->find((int) $data['queue_id']);
        if (! $broadcast) {
            return response()->json(['success' => false, 'message' => 'Queue not found.'], 404);
        }

        if ($broadcast->template_id) {
            $request->merge(['template_id' => (int) $broadcast->template_id]);
            return $this->sendTemplate($request, $id);
        }

        // Custom-message queue — synthesize a sendMessage body+location.
        $request->merge([
            'body' => (string) ($broadcast->temp_caption ?? ''),
        ]);
        if (! empty($broadcast->latitude) && ! empty($broadcast->longitude)) {
            $request->merge([
                'latitude'  => (float) $broadcast->latitude,
                'longitude' => (float) $broadcast->longitude,
            ]);
        }
        return $this->sendMessage($request, $id);
    }

    // -----------------------------------------------------------------
    // GET /chats/search-recipients?q= — search workspace contacts by
    // name or phone digits. Used by the "new chat" picker in the app.
    // -----------------------------------------------------------------
    public function searchRecipients(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') return response()->json(['success' => true, 'data' => []]);

        $digits = preg_replace('/\D+/', '', $q);
        $needle = mb_strtolower($q);

        $hits = Contact::query()
            ->forCurrentWorkspace()
            ->limit(500)
            ->get()
            ->filter(function (Contact $c) use ($needle, $digits) {
                if ($digits !== '' && str_contains(preg_replace('/\D+/', '', (string) $c->mobile), $digits)) return true;
                if (str_contains(mb_strtolower((string) $c->name), $needle)) return true;
                return false;
            })
            ->take(50)
            ->values()
            ->map(fn (Contact $c) => [
                'id'     => $c->id,
                'name'   => $c->name,
                'mobile' => $c->mobile,
                'email'  => $c->email,
            ])
            ->all();

        return response()->json(['success' => true, 'data' => $hits]);
    }

    // =================================================================
    // Helpers — presenters + dispatcher result mapping. Kept lean for
    // the app contract; the web ChatController has the canonical
    // versions with admin-only extras (counts/category/etc).
    // =================================================================

    private function applyDispatchResult(Message $message, array $result, bool $scheduled): void
    {
        if ($result['ok'] ?? false) {
            $message->status  = $scheduled ? 'scheduled' : 'sent';
            $message->sent_at = $scheduled ? null : now();
            if (! empty($result['provider_id']) && empty($message->from_number)) {
                $message->from_number = $result['provider_id'];
            }
        } else {
            $message->status         = 'failed';
            $message->failure_reason = (string) ($result['error'] ?? 'unknown error');
        }
        $message->save();
    }

    /**
     * Rebuild a conversation's denormalised list-row fields (`preview`,
     * `last_message_at`) from the newest surviving message. Called after a
     * message is soft-deleted so a removed media message doesn't leave a
     * stale "[video]" / "[image]" preview on the thread. If the thread is now
     * empty, both fields are blanked.
     */
    private function recomputeConversationPreview(Conversation $conversation): void
    {
        $latest = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $latest) {
            $conversation->forceFill(['preview' => null, 'last_message_at' => null])->save();
            return;
        }

        $body = self::safeAttr($latest, 'body');
        $conversation->forceFill([
            'preview'         => ($body !== null && $body !== '')
                ? mb_substr($body, 0, 191)
                : ($latest->media_type ? '[' . $latest->media_type . ']' : null),
            'last_message_at' => $latest->sent_at ?: $latest->created_at ?: $conversation->last_message_at,
        ])->save();
    }

    private function refreshConversationAfterSend(Conversation $conversation, Message $message): void
    {
        $statuses = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'out')
            ->pluck('status');

        $newStatus = match (true) {
            $statuses->contains('scheduled') && ! $statuses->contains('sent') && ! $statuses->contains('failed') => 'scheduled',
            $statuses->isNotEmpty() && $statuses->every(fn ($s) => $s === 'failed') => 'failed',
            $statuses->contains('failed') && $statuses->contains('sent') => 'partial',
            $statuses->contains('sent') || $statuses->contains('delivered') || $statuses->contains('read') => 'sent',
            default => $conversation->status ?: 'pending',
        };

        $conversation->forceFill([
            'preview'         => $message?->body ?: ($message?->media_type ? '[' . $message->media_type . ']' : $conversation->preview),
            'last_message_at' => $message?->sent_at ?: $message?->created_at ?: now(),
            'status'          => $newStatus,
        ])->save();
    }

    /**
     * Read an attribute that's cast 'encrypted' WITHOUT crashing on
     * legacy plaintext rows. Encrypted Eloquent casts throw a
     * DecryptException when the column value isn't a valid encrypted
     * payload — which happens for rows inserted before the cast was
     * added, or by code that bypassed the cast (raw query / DB::table).
     * We catch that and fall back to the raw column value so the API
     * doesn't 500 on a single corrupt row. Backfill those rows with a
     * data migration when possible (see EncryptionBackfill command);
     * this helper is the safety net so the inbox keeps loading
     * regardless.
     */
    private static function safeAttr($model, string $field): ?string
    {
        try {
            return $model->{$field};
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Plaintext leftover from before the cast was added (or
            // a raw INSERT). Return whatever the DB literally holds.
            $raw = $model->getRawOriginal($field);
            return is_string($raw) ? $raw : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The real phone digits for a thread, '' when there is none.
     * For a @lid (linked-identity) chat the phone is NOT in raw_jid — that's the
     * LID id — it lives in contact_digits (or the alt_jid phone form, or the
     * "+<digits>" the title carries). Groups / Instagram / Facebook return ''.
     */
    private static function convPhone(Conversation $c): string
    {
        $rawJid = (string) $c->raw_jid;
        if (str_ends_with($rawJid, '@g.us')) {
            return '';
        }
        if (str_contains($rawJid, '@s.whatsapp.net')) {
            return (string) preg_replace('/\D+/', '', explode('@', $rawJid)[0]);
        }
        // @lid or other non-phone raw_jid → the real phone is NOT in raw_jid, and
        // NOT in contact_digits either — for a @lid row that column holds the LID
        // (linked-identity id), not the phone. It lives in alt_jid (the
        // @s.whatsapp.net form) or is embedded in the stored title as "+<digits>".
        $alt = (string) ($c->alt_jid ?? '');
        if (str_contains($alt, '@s.whatsapp.net')) {
            return (string) preg_replace('/\D+/', '', explode('@', $alt)[0]);
        }
        if (preg_match('/\+(\d{8,15})\s*$/', (string) self::safeAttr($c, 'title'), $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Rename a thread. `conversations` has NO contact_name column — the display
     * name is `title`, stored in the SAME "<name> · +<phone>" shape
     * ConversationResolver::defaultTitle writes, so the web inbox stays
     * consistent and the app serializer (presentConversation) splits it back to a
     * clean name. Empty name → fall back to just the phone. Groups / non-phone
     * channels store the bare name.
     */
    private function applyChatName(Conversation $c, string $name): void
    {
        $name  = trim($name);
        $phone = self::convPhone($c);

        if ($name === '') {
            $c->title = $phone !== '' ? '+' . $phone : (string) self::safeAttr($c, 'title');
        } elseif ($phone !== '') {
            $c->title = $name . ' · +' . $phone;
        } else {
            $c->title = $name;
        }
        $c->save();
    }

    private function presentConversation(Conversation $c): array
    {
        // Split the stored thread title into a CLEAN name + phone. Threads opened
        // via ConversationResolver store the title as "<name> · +<digits>" (or
        // just "+<digits>" when the contact is unnamed). The app wants those two
        // parts separately so it can show a clean primary line ("Himanshu") and
        // format the number itself on a secondary line — instead of always
        // getting "Himanshu · +91…". We split on the app surface only, so what's
        // stored (and the web inbox) is untouched.
        $rawTitle = (string) (self::safeAttr($c, 'title') ?? '');
        // Real phone for the thread. For @lid (linked-identity) chats the phone is
        // NOT in raw_jid — that's the LID id — so it comes from contact_digits.
        // Groups / non-phone channels resolve to ''.
        $phone    = self::convPhone($c);

        $name = $rawTitle;
        if ($phone !== '') {
            $suffix = ' · +' . $phone;
            if (str_ends_with($rawTitle, $suffix)) {
                $name = substr($rawTitle, 0, -strlen($suffix));       // "<name>"
            } elseif ($rawTitle === '+' . $phone || $rawTitle === $phone) {
                $name = '';                                           // number-only, no name set
            }
        }
        $name         = trim($name);
        $phoneDisplay = $phone !== '' ? '+' . $phone : '';
        // Primary display: the contact name when set, else the +phone. No suffix.
        $title        = $name !== '' ? $name : ($phoneDisplay !== '' ? $phoneDisplay : $rawTitle);

        return [
            'id'               => $c->id,
            'title'            => $title,
            // Broken-out fields so the app can format name / phone lines itself.
            'name'             => $name,
            'phone'            => $phone,
            'phone_display'    => $phoneDisplay,
            'preview'          => self::safeAttr($c, 'preview'),
            'status'           => $c->status,
            'archived'         => (bool) $c->archived,
            'platform'         => $c->platform,
            // The channel the thread arrived on (whatsapp | sms | telegram |
            // facebook | instagram | tiktok | chatbot_widget) so the app can show
            // the right badge/icon and route replies. platform stays for back-compat.
            'channel'          => $c->channel ?: 'whatsapp',
            'device_id'        => $c->device_id,
            'recipients_count' => $c->recipients_count,
            'last_message_at'  => $c->last_message_at?->toIso8601String(),
            'last_message_ts'  => $c->last_message_at?->getTimestamp(),
            // Canonical unread = the conversation's own counter, which BOTH
            // inbound paths bump (WaInboundController / WaWebhookController) and
            // markRead() zeroes on open. The old Message-table count missed
            // inbound WhatsApp entirely (those land in inbox_messages), so a new
            // customer message never lit the badge — the exact #4 complaint.
            'unread_count'     => (int) ($c->unread_count ?? 0),
            'is_group'         => str_ends_with((string) $c->raw_jid, '@g.us'),
            'raw_jid'          => $c->raw_jid,
            'created_at'       => $c->created_at?->toIso8601String(),
            // Which AI agent (if any) is currently attached to this chat. The
            // screen uses this to show the "AI attached" state + pre-select the
            // agent in the assign picker. Null = no AI on this conversation.
            'assignee_agent_id'   => $c->assignee_agent_id,
            'assignee_agent_name' => $c->assignee_agent_id ? self::agentName((int) $c->assignee_agent_id) : null,
        ];
    }

    /**
     * Resolve an AI agent's name, memoised per-request so a chat LIST doesn't
     * fire one query per row for the attached-agent label.
     */
    private static array $agentNameMemo = [];
    private static function agentName(int $id): ?string
    {
        if (!array_key_exists($id, self::$agentNameMemo)) {
            self::$agentNameMemo[$id] = \App\Models\AiAgent::whereKey($id)->value('name');
        }
        return self::$agentNameMemo[$id];
    }

    private function presentMessage(Message $m): array
    {
        $mediaName = null;
        $mediaSize = null;
        $mediaMime = null;
        if ($m->media_path) {
            $base = basename($m->media_path);
            $mediaName = str_contains($base, '__') ? substr($base, strpos($base, '__') + 2) : $base;
            $abs = storage_path('app/public/' . $m->media_path);
            if (is_file($abs)) {
                $mediaSize = filesize($abs);
                $mediaMime = \Illuminate\Support\Facades\File::mimeType($abs) ?: null;
            }
        }
        return [
            'id'                  => $m->id,
            'conversation_id'     => $m->conversation_id,
            'direction'           => $m->direction,
            // Explicit side flags so the app never has to interpret "in"/"out".
            //   is_outbound / isSentByMe = true  → WE sent it   → right side
            //   false → the customer sent it → left side
            'is_outbound'         => $m->direction === 'out',
            'isSentByMe'          => $m->direction === 'out',
            // body / to_number / from_number are encrypted casts — fall
            // back to raw on legacy plaintext rows (see safeAttr docs).
            'body'                => self::safeAttr($m, 'body'),
            'media_url'           => $m->media_path ? media_url($m->media_path) : null,
            'media_type'          => $m->media_type,
            'media_name'          => $mediaName,
            'media_size'          => $mediaSize,
            'media_mime'          => $mediaMime,
            'latitude'            => $m->latitude !== null ? (float) $m->latitude : null,
            'longitude'           => $m->longitude !== null ? (float) $m->longitude : null,
            'status'              => $m->status,
            'pinned'              => (bool) $m->pinned,
            'starred'             => (bool) $m->starred,
            'reaction'            => $m->reaction,
            'template_id'         => $m->template_id,
            // Render hints so the app draws the right bubble without guessing:
            // 'type' one of text|image|video|audio|document|location|template.
            'type'                => $this->msgType($m),
            // Full template content (name/header/body/footer/buttons) so a
            // template message renders like the web inbox, not a bare id.
            'template'            => $m->template_id ? $this->templateCard((int) $m->template_id) : null,
            'quoted_message_id'   => $m->quoted_message_id ?? null,
            'whatsapp_message_id' => $m->wa_message_id ?? null,
            'scheduled_at'        => $m->scheduled_at?->toDateTimeString(),
            'sent_at'             => $m->sent_at?->toIso8601String(),
            'delivered_at'        => $m->delivered_at?->toIso8601String(),
            'read_at'             => $m->read_at?->toIso8601String(),
            'created_at'          => $m->created_at?->toIso8601String(),
        ] + $this->aiFields($m);
    }

    /**
     * Who sent an outbound bubble — the AI agent or a human operator — mirroring
     * what the web Team Inbox shows (the "AgentName ★score" tag). The app uses
     * `is_ai` to badge AI replies differently from human ones. All values come
     * from columns already on the row (agent_id / quality_score / quality_note /
     * user_id) — no schema change. Works for both Message and InboxMessage.
     */
    private function aiFields($m): array
    {
        $agentId = $m->agent_id ? (int) $m->agent_id : null;
        $isAi    = $m->direction === 'out' && $agentId !== null;
        $info    = $isAi ? $this->aiAgentInfo($agentId) : ['name' => null, 'color' => null];

        return [
            // 'customer' (inbound) | 'ai' (auto-reply) | 'human' (operator)
            'sent_by'       => $m->direction === 'in' ? 'customer' : ($isAi ? 'ai' : 'human'),
            'is_ai'         => $isAi,                                    // <- the simple AI-vs-human flag
            'agent_id'      => $agentId,                                 // AI agent id (null when human)
            'agent_name'    => $info['name'],                           // e.g. "test"
            'agent_color'   => $info['color'],
            'user_id'       => $m->user_id ? (int) $m->user_id : null,   // human operator (null when AI)
            'quality_score' => $m->quality_score !== null ? (int) $m->quality_score : null, // the ★ (1–10)
            'quality_note'  => $m->quality_note ?? null,
        ];
    }

    /** Memoised AI-agent name/color lookup so a thread of AI replies is one query per agent. */
    private static array $aiAgentInfoCache = [];
    private function aiAgentInfo(int $agentId): array
    {
        if (! array_key_exists($agentId, self::$aiAgentInfoCache)) {
            $a = \App\Models\AiAgent::find($agentId);
            self::$aiAgentInfoCache[$agentId] = ['name' => $a?->name, 'color' => $a?->avatar_color];
        }

        return self::$aiAgentInfoCache[$agentId];
    }

    /**
     * Present an InboxMessage (the Team-Inbox bubble table) in the EXACT
     * same wire-shape as presentMessage(), so the app renders web-inbox
     * history identically. `wa_message_id` lives in `meta` here (not a
     * column); `quoted_message_id`/`scheduled_at` don't exist on inbox
     * rows, so they resolve to null.
     */
    private function presentInboxMessage(\App\Models\InboxMessage $m): array
    {
        $mediaName = null;
        $mediaSize = null;
        $mediaMime = null;
        if ($m->media_path) {
            $base = basename($m->media_path);
            $mediaName = str_contains($base, '__') ? substr($base, strpos($base, '__') + 2) : $base;
            $abs = storage_path('app/public/' . $m->media_path);
            if (is_file($abs)) {
                $mediaSize = filesize($abs);
                $mediaMime = \Illuminate\Support\Facades\File::mimeType($abs) ?: null;
            }
        }
        $meta = is_array($m->meta) ? $m->meta : [];

        return [
            'id'                  => $m->id,
            'conversation_id'     => $m->conversation_id,
            'direction'           => $m->direction,
            'is_outbound'         => $m->direction === 'out',
            'isSentByMe'          => $m->direction === 'out',
            'body'                => self::safeAttr($m, 'body'),
            'media_url'           => $m->media_path ? media_url($m->media_path) : null,
            'media_type'          => $m->media_type,
            'media_name'          => $mediaName,
            'media_size'          => $mediaSize,
            'media_mime'          => $mediaMime,
            'latitude'            => $m->latitude !== null ? (float) $m->latitude : null,
            'longitude'           => $m->longitude !== null ? (float) $m->longitude : null,
            'status'              => $m->status,
            'pinned'              => (bool) $m->pinned,
            'starred'             => (bool) $m->starred,
            'reaction'            => $m->reaction,
            'template_id'         => $m->template_id,
            'type'                => $this->msgType($m),
            'template'            => $m->template_id ? $this->templateCard((int) $m->template_id) : null,
            'quoted_message_id'   => $m->quoted_message_id ?? null,
            'whatsapp_message_id' => $meta['wa_message_id'] ?? null,
            'scheduled_at'        => null,
            'sent_at'             => $m->sent_at?->toIso8601String(),
            'delivered_at'        => $m->delivered_at?->toIso8601String(),
            'read_at'             => $m->read_at?->toIso8601String(),
            'created_at'          => $m->created_at?->toIso8601String(),
        ] + $this->aiFields($m);
    }

    /**
     * The bubble kind the app should draw. Works for both Message and
     * InboxMessage (both expose template_id / latitude / media_type / media_path).
     */
    private function msgType($m): string
    {
        if ($m->template_id) return 'template';
        if ($m->latitude !== null && $m->longitude !== null) return 'location';
        $mt = (string) ($m->media_type ?? '');
        if (in_array($mt, ['image', 'video', 'audio', 'document'], true)) return $mt;
        if ($m->media_path) return 'document';
        return 'text';
    }

    /**
     * A template's renderable content, memoised per request so a thread full of
     * template sends doesn't fire one query per bubble. Null if the template row
     * is gone. Mirrors what the web Team Inbox shows for a template message.
     */
    private static array $tplCardCache = [];
    private function templateCard(int $id): ?array
    {
        if (!array_key_exists($id, self::$tplCardCache)) {
            $t = \App\Models\WaTemplate::find($id);
            self::$tplCardCache[$id] = $t ? [
                'id'      => $t->id,
                'name'    => (string) $t->template_name,
                'header'  => (string) ($t->header ?? ''),
                'body'    => (string) ($t->template_body ?? ''),
                'footer'  => (string) ($t->footer ?? ''),
                'buttons' => is_array($t->buttons) ? array_values($t->buttons) : [],
            ] : null;
        }
        return self::$tplCardCache[$id];
    }

    private function resolveMediaType(string $extension): string
    {
        $extension = strtolower($extension);
        return match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) => 'image',
            in_array($extension, ['mp4', 'webm', 'mov'],                true) => 'video',
            in_array($extension, ['mp3', 'wav', 'm4a', 'ogg', 'opus'],  true) => 'audio',
            default                                                             => 'document',
        };
    }
}
