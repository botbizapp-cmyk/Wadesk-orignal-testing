<?php

namespace App\Http\Controllers\Telegram;

use App\Models\TelegramBot;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastRecipient;
use App\Services\Telegram\TelegramChats;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramMedia;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Telegram broadcasts.
 *
 * WHY THIS PAGE EXISTS. The core campaign composer sends to contact groups, and
 * a contact group is a list of phone numbers. A Telegram bot cannot address a
 * phone number, and cannot message anyone who has not first messaged it — so a
 * Telegram "campaign" over there was refused, correctly, and the operator was
 * left with a channel they could reply on but never announce on.
 *
 * The audience Telegram DOES allow is everyone already in a thread with the bot,
 * plus every group and channel it has been added to. That list already exists in
 * the inbox; this page turns it into something you can send to.
 *
 * SENDING IS BATCHED, not queued. This install deliberately runs no queue
 * workers, and a broadcast cannot fit in one request. The browser drives the
 * loop: `sendBatch` sends a slice and reports what is left, and the page calls it
 * again until nothing is pending. A closed tab PAUSES the broadcast rather than
 * losing it — every recipient's state is a row, and "Resume sending" picks up
 * exactly where it stopped.
 */
class TelegramBroadcastController extends Controller
{
    /**
     * Chats per batch.
     *
     * Telegram's documented ceiling is about 30 messages a second overall, and
     * roughly 20 a minute into any single group. This is a bulk send to DIFFERENT
     * chats so the per-group limit is not the binding one — but a 429 costs the
     * bot a timed lockout that also stops customer replies, so the batch is sized
     * well under the ceiling and paced by the page between calls.
     */
    private const BATCH = 25;

    public function index(): View
    {
        $wsId = $this->workspaceId();
        $bots = TelegramBot::allForWorkspace($wsId);

        $broadcasts = TelegramBroadcast::query()
            ->where('workspace_id', $wsId)
            ->with('bot')
            ->orderByDesc('id')->limit(50)->get();

        // Roll-up stats for the KPI cards (mirrors the WhatsApp broadcast index).
        $agg = TelegramBroadcast::query()->where('workspace_id', $wsId)
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

        return view('user.telegram.broadcasts', [
            'broadcasts' => $broadcasts,
            'bots'       => $bots,
            'stats'      => $stats,
        ]);
    }

    /** Compose a new Telegram broadcast — matches the WhatsApp create page (form + live preview). */
    public function create(): View
    {
        $wsId = $this->workspaceId();
        $bots = TelegramBot::allForWorkspace($wsId);

        // The audience per bot, keyed by bot id so the form swaps lists when the
        // operator changes the sender (a chat id belongs to ONE bot).
        $chats = [];
        foreach ($bots as $bot) {
            $chats[$bot->id] = TelegramChats::forBot($bot)->values()->all();
        }

        // Local Telegram templates — prefill body + carry buttons.
        $templates = \App\Models\WaTemplate::query()->forCurrentWorkspace()
            ->orderByDesc('id')->get()
            ->filter(fn ($t) => $t->engineKey() === 'telegram')
            ->map(fn ($t) => [
                'id'      => $t->id,
                'name'    => (string) $t->template_name,
                'body'    => (string) $t->template_body,
                'buttons' => is_array($t->buttons) ? array_values($t->buttons) : [],
            ])->values();

        return view('user.telegram.broadcasts-create', [
            'bots'      => $bots,
            'chats'     => $chats,
            'templates' => $templates,
        ]);
    }

    /**
     * Create a broadcast and snapshot its recipient list.
     *
     * Recipients are copied into rows here rather than resolved at send time. A
     * broadcast that reads "sent to 40" must mean the 40 who were on the list
     * when it ran — recomputing later would silently include everyone who has
     * messaged the bot since, which for a one-off announcement means sending it
     * twice to people who arrived mid-send.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:191'],
            'telegram_bot_id' => ['required', 'integer'],
            'template_id'     => ['nullable', 'integer'],
            'body'            => ['nullable', 'string', 'max:4096'],
            'chat_ids'        => ['required', 'array', 'min:1'],
            'chat_ids.*'      => ['string', 'max:32'],
            // One optional attachment, sent with the body as its caption.
            'media'           => ['nullable', 'file', 'max:51200', // 50MB — Telegram's bot upload ceiling.
                'mimes:jpg,jpeg,png,gif,webp,mp4,mov,pdf,doc,docx,mp3,ogg'],
        ]);

        $wsId = $this->workspaceId();
        $bot  = TelegramBot::where('workspace_id', $wsId)->find($data['telegram_bot_id']);

        if (! $bot) {
            return back()->with('error', 'Pick a Telegram bot that belongs to this workspace.');
        }

        $body = trim((string) ($data['body'] ?? ''));
        $file = $request->file('media');

        // Optional Telegram template — its body prefills an empty composer and its
        // buttons ride along so the broadcast sends the same tappable keyboard the
        // inbox template send does. Workspace + channel scoped so a forged id can't
        // pull another tenant's or a WhatsApp template.
        $buttons    = null;
        $templateId = null;
        if (! empty($data['template_id'])) {
            $tpl = \App\Models\WaTemplate::query()->forCurrentWorkspace()
                ->where('id', (int) $data['template_id'])->first();
            if ($tpl && $tpl->engineKey() === 'telegram') {
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

        // RESOLVE THE PICKED IDS AGAINST THE BOT'S OWN CHATS. The posted ids are
        // just strings from a form; taking them at face value would let a crafted
        // request message any chat id on Telegram that this bot happens to be
        // able to reach, including ones belonging to another workspace's threads.
        // Intersecting with the real list makes a forged id a no-op.
        $available = TelegramChats::forBot($bot)->keyBy('chat_id');
        $picked    = collect($data['chat_ids'])->map(fn ($v) => trim((string) $v))->unique();

        $recipients = $picked
            ->filter(fn ($id) => $available->has($id))
            ->map(fn ($id) => $available->get($id))
            ->values();

        if ($recipients->isEmpty()) {
            return back()->with('error', 'None of those chats belong to this bot. Pick recipients from the list.');
        }

        [$mediaPath, $mediaKind] = $this->storeMedia($file);

        $broadcast = TelegramBroadcast::create([
            'workspace_id'    => $wsId,
            'telegram_bot_id' => $bot->id,
            'user_id'         => Auth::id(),
            'name'            => $data['name'],
            'template_id'     => $templateId,
            'body'            => $body ?: null,
            'buttons'         => $buttons,
            'media_path'      => $mediaPath,
            'media_kind'      => $mediaKind,
            'status'          => TelegramBroadcast::STATUS_DRAFT,
            'total'           => $recipients->count(),
        ]);

        foreach ($recipients->chunk(500) as $chunk) {
            TelegramBroadcastRecipient::insert($chunk->map(fn ($c) => [
                'telegram_broadcast_id' => $broadcast->id,
                'chat_id'         => (string) $c['chat_id'],
                'title'           => mb_substr((string) $c['title'], 0, 191),
                'kind'            => $c['type'] === 'private'
                    ? TelegramBroadcastRecipient::KIND_PRIVATE
                    : TelegramBroadcastRecipient::KIND_GROUP,
                'conversation_id' => (int) $c['id'],
                'status'          => TelegramBroadcastRecipient::STATUS_PENDING,
                'created_at'      => now(),
                'updated_at'      => now(),
            ])->all());
        }

        return redirect('/telegram/broadcasts')
            ->with('success', 'Broadcast created with ' . $recipients->count() . ' recipients. Press Start sending when ready.');
    }

    /** Flip a draft into sending so the page starts the loop. */
    public function start(TelegramBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);

        if (! $broadcast->hasPending()) {
            return back()->with('error', 'Every recipient in this broadcast has already been handled.');
        }

        $bot = $broadcast->bot;
        if (! $bot) {
            return back()->with('error', 'The bot this broadcast was built for is no longer connected.');
        }
        if (! $bot->active) {
            return back()->with('error', 'The bot @' . $bot->bot_username . ' is paused. Resume it before sending.');
        }

        $broadcast->forceFill([
            'status'     => TelegramBroadcast::STATUS_SENDING,
            'started_at' => $broadcast->started_at ?: now(),
            'last_error' => null,
        ])->save();

        return back()->with('success', 'Sending started. Leave this page open until it finishes.');
    }

    /** Stop a running broadcast. Pending rows stay pending, so it can resume. */
    public function pause(TelegramBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);

        $broadcast->forceFill(['status' => TelegramBroadcast::STATUS_DRAFT])->save();

        return back()->with('success', 'Paused. The remaining recipients are still queued.');
    }

    /**
     * Put the FAILED recipients back in the queue.
     *
     * Blocked rows are deliberately left alone: the person pressed Stop or the
     * bot was removed from the group, and retrying that is not a second chance,
     * it is the same refusal again. Failures are the opposite — a rate limit or
     * a network blip — and without this a single 429 leaves a broadcast in a
     * state with nothing pending and no way to finish it.
     */
    public function retry(TelegramBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);

        $n = $broadcast->recipients()
            ->where('status', TelegramBroadcastRecipient::STATUS_FAILED)
            ->update(['status' => TelegramBroadcastRecipient::STATUS_PENDING, 'error' => null]);

        if ($n === 0) {
            return back()->with('error', 'Nothing to retry — no recipient failed.');
        }

        // The counter goes back with them, or the totals would count the same
        // recipient as both failed and sent once the retry succeeds.
        $broadcast->forceFill([
            'failed'      => max(0, (int) $broadcast->failed - $n),
            'status'      => TelegramBroadcast::STATUS_DRAFT,
            'finished_at' => null,
            'last_error'  => null,
        ])->save();

        return back()->with('success', $n . ' failed recipient' . ($n === 1 ? '' : 's') . ' queued again. Press Start sending.');
    }

    /**
     * Send one batch. Called repeatedly by the page until nothing is pending.
     *
     * Returns JSON rather than a redirect because the caller is a fetch loop, and
     * it reports counts so the page shows real progress instead of a spinner.
     */
    public function sendBatch(TelegramBroadcast $broadcast): JsonResponse
    {
        $this->authorizeBroadcast($broadcast);

        if ($broadcast->status !== TelegramBroadcast::STATUS_SENDING) {
            return response()->json(['ok' => false, 'error' => 'This broadcast is not sending.'], 422);
        }

        $bot = $broadcast->bot;
        if (! $bot || ! $bot->active) {
            return $this->stop($broadcast, $bot
                ? 'The bot @' . $bot->bot_username . ' is paused.'
                : 'The bot this broadcast belongs to is no longer connected.');
        }

        $client = new TelegramClient((string) $bot->bot_token);
        $body   = (string) ($broadcast->body ?? '');

        // Bytes read ONCE for the whole batch, not per recipient — and only
        // until Telegram hands back a file_id, after which nothing is uploaded
        // again at all. See sendOne().
        $media = $broadcast->media_path && ! $broadcast->media_file_id
            ? TelegramMedia::readLocalMedia((string) $broadcast->media_path)
            : [null, ''];

        $rows = $broadcast->recipients()
            ->where('status', TelegramBroadcastRecipient::STATUS_PENDING)
            ->orderBy('id')->limit(self::BATCH)->get();

        foreach ($rows as $row) {
            $text = $this->personalise($body, $row);
            $res  = $this->sendOne($client, $broadcast, $row, $text, $media);

            if ($res['ok'] ?? false) {
                $messageId = (string) (data_get($res, 'result.message_id') ?? '');

                $row->forceFill([
                    'status'              => TelegramBroadcastRecipient::STATUS_SENT,
                    'provider_message_id' => $messageId,
                    'sent_at'             => now(),
                    'error'               => null,
                ])->save();

                $broadcast->increment('sent');
                $this->mirror($broadcast, $row, $text, $messageId);

                // Capture Telegram's own id for the uploaded file from the FIRST
                // success and reuse it for every recipient after — one upload per
                // broadcast instead of one per person.
                if ($broadcast->media_path && ! $broadcast->media_file_id) {
                    if ($fileId = $this->fileIdFrom($res, (string) $broadcast->media_kind)) {
                        $broadcast->forceFill(['media_file_id' => $fileId])->save();
                        $media = [null, ''];   // nothing left to upload this batch
                    }
                }
                continue;
            }

            $error = (string) ($res['error'] ?? 'Telegram rejected the send.');
            $gone  = TelegramBroadcastRecipient::isUnreachable($error);

            $row->forceFill([
                'status' => $gone
                    ? TelegramBroadcastRecipient::STATUS_BLOCKED
                    : TelegramBroadcastRecipient::STATUS_FAILED,
                'error'  => mb_substr($error, 0, 255),
            ])->save();

            $broadcast->increment($gone ? 'blocked' : 'failed');
        }

        $broadcast->refresh();
        $pending = $broadcast->recipients()
            ->where('status', TelegramBroadcastRecipient::STATUS_PENDING)->count();

        if ($pending === 0) {
            $broadcast->forceFill([
                'status'      => TelegramBroadcast::STATUS_DONE,
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

    public function destroy(TelegramBroadcast $broadcast): RedirectResponse
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
     * One message to one chat.
     *
     * Three routes to the same place, cheapest first:
     *   file_id  — Telegram already holds the file, so this is a plain API call
     *   upload   — the bytes go up with the request; works with no public URL
     *   url      — last resort, and only when the file is not on our own disk
     *
     * @param  array{0: ?string, 1: string} $media  [bytes, filename]
     */
    private function sendOne(
        TelegramClient $client,
        TelegramBroadcast $broadcast,
        TelegramBroadcastRecipient $row,
        string $text,
        array $media
    ): array {
        $chatId = (string) $row->chat_id;

        // Template buttons → the same Telegram keyboard the inbox send builds.
        $markupJson = null;
        $buttons = is_array($broadcast->buttons) ? $broadcast->buttons : [];
        if ($buttons) {
            $mk = TelegramClient::templateButtonsMarkup($buttons);
            $markupJson = $mk['reply_markup'];
            if (($mk['append'] ?? '') !== '') {
                $text = trim($text."\n".$mk['append']);
            }
        }

        if (! $broadcast->media_path) {
            return $client->sendMessage($chatId, $text, '', $markupJson);
        }

        $kind    = (string) ($broadcast->media_kind ?: 'image');
        $caption = $text !== '' ? $text : null;
        $opts    = $markupJson !== null ? ['reply_markup' => $markupJson] : [];

        if ($broadcast->media_file_id) {
            $res = $client->sendMedia($chatId, $kind, (string) $broadcast->media_file_id, $caption, $opts);
        } elseif ($media[0] !== null) {
            $res = $client->uploadMedia($chatId, $kind, $media[0], $media[1], $caption, $opts);
        } else {
            $res = $client->sendMedia($chatId, $kind, media_url((string) $broadcast->media_path), $caption, $opts);
        }

        // A caption maxes out at 1024 characters against 4096 for plain text.
        // Telegram would silently drop the tail, so send the remainder as a
        // follow-up rather than losing the end of the message.
        if (($res['ok'] ?? false) && mb_strlen($text) > TelegramClient::CAPTION_LIMIT) {
            $client->sendMessage($chatId, mb_substr($text, TelegramClient::CAPTION_LIMIT - 1));
        }

        return $res;
    }

    /**
     * Telegram's id for the file we just uploaded.
     *
     * The field differs per method, and a photo is returned as an ARRAY of sizes
     * — the largest last, which is the one to reuse. Returning the wrong element
     * would resend a thumbnail to everyone after the first recipient.
     */
    private function fileIdFrom(array $res, string $kind): ?string
    {
        if ($kind === '' || $kind === 'image') {
            $sizes = data_get($res, 'result.photo');

            return is_array($sizes) && $sizes !== []
                ? (string) data_get(end($sizes), 'file_id')
                : null;
        }

        $field = match ($kind) {
            'video'      => 'video',
            'audio'      => 'audio',
            'voice'      => 'voice',
            'document'   => 'document',
            'sticker'    => 'sticker',
            'animation'  => 'animation',
            'video_note' => 'video_note',
            default      => null,
        };

        if (! $field) {
            return null;
        }

        $id = data_get($res, 'result.' . $field . '.file_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Store the attachment and work out which Telegram method will send it.
     *
     * @return array{0: ?string, 1: ?string}  [path, kind]
     */
    private function storeMedia($file): array
    {
        if (! $file) {
            return [null, null];
        }

        $path = $file->store('telegram-broadcasts', media_disk());
        $ext  = strtolower((string) $file->getClientOriginalExtension());

        $kind = match (true) {
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) => 'image',
            $ext === 'gif'                                       => 'animation',
            in_array($ext, ['mp4', 'mov'], true)                 => 'video',
            in_array($ext, ['mp3', 'ogg'], true)                 => 'audio',
            default                                              => 'document',
        };

        return [$path, $kind];
    }

    /**
     * `{{name}}` filled with the chat's own title.
     *
     * Only the one token. A Telegram chat carries no attributes of its own — it
     * is not a contact row — so anything richer would resolve to blanks and read
     * as a broken merge field.
     */
    private function personalise(string $body, TelegramBroadcastRecipient $row): string
    {
        return trim(str_replace(
            ['{{name}}', '{{ name }}'],
            (string) ($row->title ?: ''),
            $body
        ));
    }

    /** A sent broadcast belongs in the thread an operator would read it in. */
    private function mirror(TelegramBroadcast $broadcast, TelegramBroadcastRecipient $row, string $body, string $messageId): void
    {
        if (! $row->conversation_id) {
            return;
        }

        try {
            InboxMessage::create([
                'conversation_id' => $row->conversation_id,
                'provider'        => 'telegram',
                'direction'       => 'out',
                'body'            => $body,
                'status'          => 'sent',
                'meta'            => array_filter([
                    'source'        => 'telegram_broadcast',
                    'broadcast_id'  => $broadcast->id,
                    'wa_message_id' => $messageId ?: null,
                ]),
                'sent_at' => now(),
            ]);

            Conversation::whereKey($row->conversation_id)
                ->update(['last_message_at' => now(), 'last_outbound_at' => now()]);
        } catch (\Throwable $e) {
            // The message is already delivered — losing its copy in the inbox
            // must not turn a successful send into a failed row.
            Log::warning('[TG-BROADCAST] mirror failed: ' . $e->getMessage());
        }
    }

    /** One problem, one place — recorded on the broadcast, not on every row. */
    private function stop(TelegramBroadcast $broadcast, string $why): JsonResponse
    {
        $broadcast->forceFill([
            'status'     => TelegramBroadcast::STATUS_FAILED,
            'last_error' => mb_substr($why, 0, 255),
        ])->save();

        return response()->json(['ok' => false, 'error' => $why], 422);
    }

    private function authorizeBroadcast(TelegramBroadcast $broadcast): void
    {
        abort_unless((int) $broadcast->workspace_id === $this->workspaceId(), 404);
    }

    private function workspaceId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }
}
