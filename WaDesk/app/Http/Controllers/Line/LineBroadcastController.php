<?php

namespace App\Http\Controllers\Line;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\LineBroadcast;
use App\Models\LineBroadcastRecipient;
use App\Models\LineChannel;
use App\Services\Line\LineChats;
use App\Services\Line\LineClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * LINE broadcasts.
 *
 * WHY THIS PAGE EXISTS — the same reason Telegram has one. The core campaign
 * composer sends to contact groups, and a contact group is a list of phone
 * numbers. A LINE OA cannot address a phone number, and cannot message anyone
 * who has not added it as a friend — so a LINE "campaign" over there is refused,
 * and the operator is left with a channel they can reply on but never announce
 * on. The audience LINE DOES allow is everyone already in a thread with the OA;
 * that list already lives in the inbox and this page turns it into a send.
 *
 * SENDING IS BATCHED, not queued (this install runs no queue workers). The
 * browser drives the loop: `sendBatch` sends a slice via push and reports what
 * is left; the page calls it again until nothing is pending. A closed tab PAUSES
 * (every recipient is a row) and "Resume" picks up exactly where it stopped.
 * Mirrors TelegramBroadcastController.
 */
class LineBroadcastController extends Controller
{
    /**
     * Recipients per batch. Push has a generous per-second ceiling, but we keep
     * the batch modest and page-paced so a burst never trips a rate limit that
     * would also stall customer replies.
     */
    private const BATCH = 40;

    public function index(): View
    {
        $wsId = $this->workspaceId();
        $channels = LineChannel::allForWorkspace($wsId);

        $broadcasts = LineBroadcast::query()
            ->where('workspace_id', $wsId)
            ->with('channel')
            ->orderByDesc('id')->limit(50)->get();

        $agg = LineBroadcast::query()->where('workspace_id', $wsId)
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(sent),0) AS sent, COALESCE(SUM(failed),0) AS failed, COALESCE(SUM(blocked),0) AS blocked, COALESCE(SUM(total),0) AS total')
            ->first();
        $stats = [
            'total'    => (int) ($agg->c ?? 0),
            'sent'     => (int) ($agg->sent ?? 0),
            'failed'   => (int) ($agg->failed ?? 0),
            'blocked'  => (int) ($agg->blocked ?? 0),
            'audience' => (int) ($agg->total ?? 0),
            'sending'  => (int) $broadcasts->where('status', 'sending')->count(),
        ];

        return view('user.line.broadcasts', [
            'broadcasts' => $broadcasts,
            'channels'   => $channels,
            'stats'      => $stats,
        ]);
    }

    /** Compose a new LINE broadcast — form + live preview + audience per channel. */
    public function create(): View
    {
        $wsId = $this->workspaceId();
        $channels = LineChannel::allForWorkspace($wsId);

        // Audience per channel, keyed by channel id so the form swaps lists when
        // the operator changes the sender (a userId belongs to ONE channel).
        $chats = [];
        foreach ($channels as $ch) {
            $chats[$ch->id] = LineChats::forChannel($ch)->values()->all();
        }

        // Local LINE templates — prefill body + carry buttons.
        $templates = \App\Models\WaTemplate::query()->forCurrentWorkspace()
            ->orderByDesc('id')->get()
            ->filter(fn ($t) => $t->engineKey() === 'line')
            ->map(fn ($t) => [
                'id'      => $t->id,
                'name'    => (string) $t->template_name,
                'body'    => (string) $t->template_body,
                'buttons' => is_array($t->buttons) ? array_values($t->buttons) : [],
            ])->values();

        return view('user.line.broadcasts-create', [
            'channels'  => $channels,
            'chats'     => $chats,
            'templates' => $templates,
        ]);
    }

    /**
     * Create a broadcast and snapshot its recipient list. Recipients are copied
     * into rows here rather than resolved at send time, so "sent to 40" always
     * means the 40 who were on the list when it ran.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:191'],
            'line_channel_id' => ['required', 'integer'],
            'template_id'     => ['nullable', 'integer'],
            'body'            => ['nullable', 'string', 'max:5000'],
            'user_ids'        => ['required', 'array', 'min:1'],
            'user_ids.*'      => ['string', 'max:64'],
            'media'           => ['nullable', 'file', 'max:51200',
                'mimes:jpg,jpeg,png,gif,webp,mp4,mov,m4a,mp3'],
        ]);

        $wsId = $this->workspaceId();
        $channel = LineChannel::where('workspace_id', $wsId)->find($data['line_channel_id']);

        if (! $channel) {
            return back()->with('error', 'Pick a LINE channel that belongs to this workspace.');
        }

        $body = trim((string) ($data['body'] ?? ''));
        $file = $request->file('media');

        // Optional LINE template — body prefills an empty composer, buttons ride
        // along. Workspace + channel scoped so a forged id can't pull another
        // tenant's or a WhatsApp template.
        $buttons    = null;
        $templateId = null;
        if (! empty($data['template_id'])) {
            $tpl = \App\Models\WaTemplate::query()->forCurrentWorkspace()
                ->where('id', (int) $data['template_id'])->first();
            if ($tpl && $tpl->engineKey() === 'line') {
                $templateId = (int) $tpl->id;
                if ($body === '') {
                    $body = (string) $tpl->template_body;
                }
                if (is_array($tpl->buttons) && $tpl->buttons) {
                    $buttons = array_values($tpl->buttons);
                }
            }
        }

        if ($body === '' && ! $file) {
            return back()->with('error', 'A broadcast needs a message, a file, or both.');
        }

        // Resolve the posted ids against the channel's OWN audience — a forged
        // userId is then a no-op (can't blast an arbitrary LINE user).
        $available = LineChats::forChannel($channel)->keyBy('user_id');
        $picked    = collect($data['user_ids'])->map(fn ($v) => trim((string) $v))->unique();

        $recipients = $picked
            ->filter(fn ($id) => $available->has($id))
            ->map(fn ($id) => $available->get($id))
            ->values();

        if ($recipients->isEmpty()) {
            return back()->with('error', 'None of those users belong to this channel. Pick recipients from the list.');
        }

        [$mediaPath, $mediaKind] = $this->storeMedia($file);

        $broadcast = LineBroadcast::create([
            'workspace_id'    => $wsId,
            'line_channel_id' => $channel->id,
            'user_id'         => Auth::id(),
            'name'            => $data['name'],
            'template_id'     => $templateId,
            'body'            => $body ?: null,
            'buttons'         => $buttons,
            'media_path'      => $mediaPath,
            'media_kind'      => $mediaKind,
            'status'          => LineBroadcast::STATUS_DRAFT,
            'total'           => $recipients->count(),
        ]);

        foreach ($recipients->chunk(500) as $chunk) {
            LineBroadcastRecipient::insert($chunk->map(fn ($c) => [
                'line_broadcast_id' => $broadcast->id,
                'line_user_id'      => (string) $c['user_id'],
                'title'             => mb_substr((string) $c['title'], 0, 191),
                'conversation_id'   => (int) $c['id'],
                'status'            => LineBroadcastRecipient::STATUS_PENDING,
                'created_at'        => now(),
                'updated_at'        => now(),
            ])->all());
        }

        return redirect('/line/broadcasts')
            ->with('success', 'Broadcast created with '.$recipients->count().' recipients. Press Start sending when ready.');
    }

    /** Flip a draft into sending so the page starts the loop. */
    public function start(LineBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);

        if (! $broadcast->hasPending()) {
            return back()->with('error', 'Every recipient in this broadcast has already been handled.');
        }

        $channel = $broadcast->channel;
        if (! $channel) {
            return back()->with('error', 'The LINE channel this broadcast was built for is no longer connected.');
        }
        if (! $channel->active) {
            return back()->with('error', 'That LINE channel is paused. Enable it before sending.');
        }

        $broadcast->forceFill([
            'status'     => LineBroadcast::STATUS_SENDING,
            'started_at' => $broadcast->started_at ?: now(),
            'last_error' => null,
        ])->save();

        return back()->with('success', 'Sending started. Leave this page open until it finishes.');
    }

    /** Stop a running broadcast. Pending rows stay pending, so it can resume. */
    public function pause(LineBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);
        $broadcast->forceFill(['status' => LineBroadcast::STATUS_DRAFT])->save();

        return back()->with('success', 'Paused. The remaining recipients are still queued.');
    }

    /**
     * Put the FAILED recipients back in the queue. Blocked rows are left alone —
     * the person blocked the OA and retrying is the same refusal.
     */
    public function retry(LineBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);

        $n = $broadcast->recipients()
            ->where('status', LineBroadcastRecipient::STATUS_FAILED)
            ->update(['status' => LineBroadcastRecipient::STATUS_PENDING, 'error' => null]);

        if ($n === 0) {
            return back()->with('error', 'Nothing to retry — no recipient failed.');
        }

        $broadcast->forceFill([
            'failed'      => max(0, (int) $broadcast->failed - $n),
            'status'      => LineBroadcast::STATUS_DRAFT,
            'finished_at' => null,
            'last_error'  => null,
        ])->save();

        return back()->with('success', $n.' failed recipient'.($n === 1 ? '' : 's').' queued again. Press Start sending.');
    }

    /**
     * Send one batch. Called repeatedly by the page until nothing is pending.
     * Returns JSON (the caller is a fetch loop) with live counts.
     */
    public function sendBatch(LineBroadcast $broadcast): JsonResponse
    {
        $this->authorizeBroadcast($broadcast);

        if ($broadcast->status !== LineBroadcast::STATUS_SENDING) {
            return response()->json(['ok' => false, 'error' => 'This broadcast is not sending.'], 422);
        }

        $channel = $broadcast->channel;
        if (! $channel || ! $channel->active) {
            return $this->stop($broadcast, $channel
                ? 'That LINE channel is paused.'
                : 'The LINE channel this broadcast belongs to is no longer connected.');
        }

        $client = new LineClient((string) $channel->activeAccessToken());
        $body    = (string) ($broadcast->body ?? '');
        $buttons = is_array($broadcast->buttons) ? $broadcast->buttons : [];

        $rows = $broadcast->recipients()
            ->where('status', LineBroadcastRecipient::STATUS_PENDING)
            ->orderBy('id')->limit(self::BATCH)->get();

        foreach ($rows as $row) {
            $text = $this->personalise($body, $row);
            $messages = $this->buildMessages($broadcast, $text, $buttons);

            if (! $messages) {
                // Nothing to send for this row — treat as done, not stuck.
                $row->forceFill(['status' => LineBroadcastRecipient::STATUS_SENT, 'sent_at' => now()])->save();
                $broadcast->increment('sent');
                continue;
            }

            $res = $client->push((string) $row->line_user_id, $messages);

            if ($res['ok'] ?? false) {
                $messageId = (string) (data_get($res, 'data.sentMessages.0.id') ?? '');
                $row->forceFill([
                    'status'              => LineBroadcastRecipient::STATUS_SENT,
                    'provider_message_id' => $messageId,
                    'sent_at'             => now(),
                    'error'               => null,
                ])->save();

                $broadcast->increment('sent');
                $this->mirror($broadcast, $row, $text, $messageId);
                continue;
            }

            $error = (string) ($res['error'] ?? 'LINE rejected the send.');
            $gone  = LineBroadcastRecipient::isUnreachable($error);

            $row->forceFill([
                'status' => $gone ? LineBroadcastRecipient::STATUS_BLOCKED : LineBroadcastRecipient::STATUS_FAILED,
                'error'  => mb_substr($error, 0, 255),
            ])->save();

            $broadcast->increment($gone ? 'blocked' : 'failed');
        }

        $broadcast->refresh();
        $pending = $broadcast->recipients()
            ->where('status', LineBroadcastRecipient::STATUS_PENDING)->count();

        if ($pending === 0) {
            $broadcast->forceFill([
                'status'      => LineBroadcast::STATUS_DONE,
                'finished_at' => now(),
            ])->save();
        }

        return response()->json([
            'ok'       => true,
            'pending'  => $pending,
            'sent'     => (int) $broadcast->sent,
            'failed'   => (int) $broadcast->failed,
            'blocked'  => (int) $broadcast->blocked,
            'progress' => $broadcast->progress(),
            'done'     => $pending === 0,
        ]);
    }

    public function destroy(LineBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);
        $broadcast->recipients()->delete();
        $broadcast->delete();

        return back()->with('success', 'Broadcast deleted.');
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Build the ≤5 LINE message objects for one recipient: media bubble (if any)
     * then the text/template. LINE media messages carry no caption, so the body
     * is always its own text bubble. Template buttons → quick replies / buttons
     * template via LineClient::templateToMessages.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildMessages(LineBroadcast $broadcast, string $text, array $buttons): array
    {
        $messages = [];

        if ($broadcast->media_path) {
            $url  = media_url((string) $broadcast->media_path);
            $kind = (string) ($broadcast->media_kind ?: 'image');
            $messages[] = match ($kind) {
                'video' => LineClient::videoMessage($url, $url),
                'audio' => LineClient::audioMessage($url, 60000),
                default => LineClient::imageMessage($url),
            };
        }

        if ($buttons) {
            foreach (LineClient::templateToMessages($text, $buttons) as $m) {
                $messages[] = $m;
            }
        } elseif (trim($text) !== '') {
            $messages[] = LineClient::textMessage($text);
        }

        return array_slice($messages, 0, 5);
    }

    /**
     * Store the attachment and classify it for the LINE message builder.
     *
     * @return array{0: ?string, 1: ?string}  [path, kind]
     */
    private function storeMedia($file): array
    {
        if (! $file) {
            return [null, null];
        }

        $path = $file->store('line-broadcasts', media_disk());
        $ext  = strtolower((string) $file->getClientOriginalExtension());

        $kind = match (true) {
            in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) => 'image',
            in_array($ext, ['mp4', 'mov'], true)                        => 'video',
            in_array($ext, ['m4a', 'mp3'], true)                        => 'audio',
            default                                                     => 'image',
        };

        return [$path, $kind];
    }

    /** `{{name}}` filled with the user's own display name. */
    private function personalise(string $body, LineBroadcastRecipient $row): string
    {
        return trim(str_replace(
            ['{{name}}', '{{ name }}'],
            (string) ($row->title ?: ''),
            $body
        ));
    }

    /** A sent broadcast belongs in the thread an operator would read it in. */
    private function mirror(LineBroadcast $broadcast, LineBroadcastRecipient $row, string $body, string $messageId): void
    {
        if (! $row->conversation_id) {
            return;
        }

        try {
            InboxMessage::create([
                'conversation_id' => $row->conversation_id,
                'provider'        => 'line',
                'direction'       => 'out',
                'body'            => $body,
                'status'          => 'sent',
                'meta'            => array_filter([
                    'source'        => 'line_broadcast',
                    'broadcast_id'  => $broadcast->id,
                    'wa_message_id' => $messageId ?: null,
                ]),
                'sent_at' => now(),
            ]);

            Conversation::whereKey($row->conversation_id)
                ->update(['last_message_at' => now(), 'last_outbound_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('[LINE-BROADCAST] mirror failed: '.$e->getMessage());
        }
    }

    private function stop(LineBroadcast $broadcast, string $why): JsonResponse
    {
        $broadcast->forceFill([
            'status'     => LineBroadcast::STATUS_FAILED,
            'last_error' => mb_substr($why, 0, 255),
        ])->save();

        return response()->json(['ok' => false, 'error' => $why], 422);
    }

    private function authorizeBroadcast(LineBroadcast $broadcast): void
    {
        abort_unless((int) $broadcast->workspace_id === $this->workspaceId(), 404);
    }

    private function workspaceId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }
}
