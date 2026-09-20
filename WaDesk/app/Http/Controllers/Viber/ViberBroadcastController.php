<?php

namespace App\Http\Controllers\Viber;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\ViberBroadcast;
use App\Models\ViberBroadcastRecipient;
use App\Models\ViberChannel;
use App\Services\Viber\ViberChats;
use App\Services\Viber\ViberClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Viber broadcasts — Viber's broadcast_message (≤300 subscribed receivers per
 * call). Unlike LINE's one-recipient-per-push, each batch is a SINGLE
 * broadcast_message call for up to 300 ids; the returned failed_list marks the
 * failures, the rest are sent. Still browser-drained (no queue) so a big audience
 * finishes across calls. Mirrors LineBroadcastController.
 */
class ViberBroadcastController extends Controller
{
    /** Viber's per-call cap for broadcast_message. */
    private const BATCH = 300;

    public function index(): View
    {
        $wsId = $this->workspaceId();
        $broadcasts = ViberBroadcast::where('workspace_id', $wsId)->with('channel')->orderByDesc('id')->limit(50)->get();
        $agg = ViberBroadcast::where('workspace_id', $wsId)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(sent),0) sent, COALESCE(SUM(failed),0) failed, COALESCE(SUM(total),0) total')->first();

        return view('user.viber.broadcasts', [
            'channels'   => ViberChannel::allForWorkspace($wsId),
            'broadcasts' => $broadcasts,
            'stats'      => [
                'total' => (int) ($agg->c ?? 0), 'sent' => (int) ($agg->sent ?? 0),
                'failed' => (int) ($agg->failed ?? 0), 'audience' => (int) ($agg->total ?? 0),
                'sending' => (int) $broadcasts->where('status', 'sending')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        $wsId = $this->workspaceId();
        $channels = ViberChannel::allForWorkspace($wsId);
        $chats = [];
        foreach ($channels as $ch) {
            $chats[$ch->id] = ViberChats::forChannel($ch)->values()->all();
        }
        $templates = \App\Models\WaTemplate::query()->forCurrentWorkspace()->orderByDesc('id')->get()
            ->filter(fn ($t) => $t->engineKey() === 'viber')
            ->map(fn ($t) => ['id' => $t->id, 'name' => (string) $t->template_name, 'body' => (string) $t->template_body, 'buttons' => is_array($t->buttons) ? array_values($t->buttons) : []])
            ->values();

        return view('user.viber.broadcasts-create', compact('channels', 'chats', 'templates'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:191'],
            'viber_channel_id' => ['required', 'integer'],
            'template_id'      => ['nullable', 'integer'],
            'body'             => ['nullable', 'string', 'max:7000'],
            'user_ids'         => ['required', 'array', 'min:1'],
            'user_ids.*'       => ['string', 'max:64'],
            'media'            => ['nullable', 'file', 'max:26624', 'mimes:jpg,jpeg,png,gif,mp4'],
        ]);

        $wsId = $this->workspaceId();
        $channel = ViberChannel::where('workspace_id', $wsId)->find($data['viber_channel_id']);
        if (! $channel) {
            return back()->with('error', 'Pick a Viber channel that belongs to this workspace.');
        }

        $body = trim((string) ($data['body'] ?? ''));
        $file = $request->file('media');
        $buttons = null;
        $templateId = null;
        if (! empty($data['template_id'])) {
            $tpl = \App\Models\WaTemplate::query()->forCurrentWorkspace()->where('id', (int) $data['template_id'])->first();
            if ($tpl && $tpl->engineKey() === 'viber') {
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

        $available = ViberChats::forChannel($channel)->keyBy('user_id');
        $recipients = collect($data['user_ids'])->map(fn ($v) => trim((string) $v))->unique()
            ->filter(fn ($id) => $available->has($id))->map(fn ($id) => $available->get($id))->values();
        if ($recipients->isEmpty()) {
            return back()->with('error', 'None of those users belong to this channel. Pick recipients from the list.');
        }

        [$mediaPath, $mediaKind] = $this->storeMedia($file);

        $broadcast = ViberBroadcast::create([
            'workspace_id' => $wsId, 'viber_channel_id' => $channel->id, 'user_id' => Auth::id(),
            'name' => $data['name'], 'template_id' => $templateId, 'body' => $body ?: null, 'buttons' => $buttons,
            'media_path' => $mediaPath, 'media_kind' => $mediaKind, 'status' => ViberBroadcast::STATUS_DRAFT, 'total' => $recipients->count(),
        ]);
        foreach ($recipients->chunk(500) as $chunk) {
            ViberBroadcastRecipient::insert($chunk->map(fn ($c) => [
                'viber_broadcast_id' => $broadcast->id, 'viber_user_id' => (string) $c['user_id'],
                'title' => mb_substr((string) $c['title'], 0, 191), 'conversation_id' => (int) $c['id'],
                'status' => ViberBroadcastRecipient::STATUS_PENDING, 'created_at' => now(), 'updated_at' => now(),
            ])->all());
        }

        return redirect('/viber/broadcasts')->with('success', 'Broadcast created with '.$recipients->count().' recipients. Press Start sending.');
    }

    public function start(ViberBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);
        if (! $broadcast->hasPending()) {
            return back()->with('error', 'Every recipient has already been handled.');
        }
        if (! $broadcast->channel || ! $broadcast->channel->active) {
            return back()->with('error', 'The Viber channel for this broadcast is not connected/active.');
        }
        $broadcast->forceFill(['status' => ViberBroadcast::STATUS_SENDING, 'started_at' => $broadcast->started_at ?: now(), 'last_error' => null])->save();

        return back()->with('success', 'Sending started. Leave this page open until it finishes.');
    }

    public function pause(ViberBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);
        $broadcast->forceFill(['status' => ViberBroadcast::STATUS_DRAFT])->save();

        return back()->with('success', 'Paused. The remaining recipients are still queued.');
    }

    public function retry(ViberBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);
        $n = $broadcast->recipients()->where('status', ViberBroadcastRecipient::STATUS_FAILED)
            ->update(['status' => ViberBroadcastRecipient::STATUS_PENDING, 'error' => null]);
        if ($n === 0) {
            return back()->with('error', 'Nothing to retry — no recipient failed.');
        }
        $broadcast->forceFill(['failed' => max(0, (int) $broadcast->failed - $n), 'status' => ViberBroadcast::STATUS_DRAFT, 'finished_at' => null, 'last_error' => null])->save();

        return back()->with('success', $n.' failed recipient'.($n === 1 ? '' : 's').' queued again. Press Start sending.');
    }

    /** Send one batch of ≤300 via a single broadcast_message call; failed_list marks failures. */
    public function sendBatch(ViberBroadcast $broadcast): JsonResponse
    {
        $this->authorizeBroadcast($broadcast);
        if ($broadcast->status !== ViberBroadcast::STATUS_SENDING) {
            return response()->json(['ok' => false, 'error' => 'This broadcast is not sending.'], 422);
        }
        $channel = $broadcast->channel;
        if (! $channel || ! $channel->active) {
            $broadcast->forceFill(['status' => ViberBroadcast::STATUS_FAILED, 'last_error' => 'channel unavailable'])->save();

            return response()->json(['ok' => false, 'error' => 'The Viber channel is unavailable.'], 422);
        }

        $client  = new ViberClient((string) $channel->auth_token, $channel->senderObject());
        $message = $this->buildMessage($broadcast);

        $rows = $broadcast->recipients()->where('status', ViberBroadcastRecipient::STATUS_PENDING)->orderBy('id')->limit(self::BATCH)->get();
        if ($rows->isNotEmpty()) {
            $ids = $rows->pluck('viber_user_id')->all();
            $res = $client->broadcast($ids, $message);

            if (! ($res['ok'] ?? false)) {
                // Whole batch rejected → mark failed, stop.
                foreach ($rows as $row) {
                    $row->forceFill(['status' => ViberBroadcastRecipient::STATUS_FAILED, 'error' => mb_substr((string) ($res['error'] ?? 'rejected'), 0, 255)])->save();
                    $broadcast->increment('failed');
                }
                $broadcast->forceFill(['status' => ViberBroadcast::STATUS_FAILED, 'last_error' => mb_substr((string) ($res['error'] ?? ''), 0, 255)])->save();

                return response()->json(['ok' => false, 'error' => $res['error'] ?? 'broadcast failed'], 422);
            }

            // Per-receiver failures come back in failed_list.
            $failed = collect((array) data_get($res, 'data.failed_list', []))
                ->mapWithKeys(fn ($f) => [(string) ($f['receiver'] ?? '') => (string) ($f['status_message'] ?? 'failed')]);

            foreach ($rows as $row) {
                if ($failed->has($row->viber_user_id)) {
                    $row->forceFill(['status' => ViberBroadcastRecipient::STATUS_FAILED, 'error' => mb_substr((string) $failed->get($row->viber_user_id), 0, 255)])->save();
                    $broadcast->increment('failed');
                } else {
                    $row->forceFill(['status' => ViberBroadcastRecipient::STATUS_SENT, 'sent_at' => now(), 'error' => null])->save();
                    $broadcast->increment('sent');
                    $this->mirror($broadcast, $row);
                }
            }
        }

        $broadcast->refresh();
        $pending = $broadcast->recipients()->where('status', ViberBroadcastRecipient::STATUS_PENDING)->count();
        if ($pending === 0) {
            $broadcast->forceFill(['status' => ViberBroadcast::STATUS_DONE, 'finished_at' => now()])->save();
        }

        return response()->json([
            'ok' => true, 'pending' => $pending, 'sent' => (int) $broadcast->sent, 'failed' => (int) $broadcast->failed,
            'progress' => $broadcast->progress(), 'done' => $pending === 0,
        ]);
    }

    public function destroy(ViberBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);
        $broadcast->recipients()->delete();
        $broadcast->delete();

        return back()->with('success', 'Broadcast deleted.');
    }

    // -----------------------------------------------------------------

    /** Build the single Viber message object for the whole broadcast. */
    private function buildMessage(ViberBroadcast $broadcast): array
    {
        $text    = (string) ($broadcast->body ?? '');
        $buttons = is_array($broadcast->buttons) ? $broadcast->buttons : [];

        if ($broadcast->media_path) {
            $url  = media_url((string) $broadcast->media_path);
            $kind = (string) ($broadcast->media_kind ?: 'image');

            return $kind === 'video'
                ? ViberClient::videoMessage($url, 1)
                : ViberClient::pictureMessage($url, $text);
        }
        if ($buttons) {
            return ViberClient::templateToMessages($text, $buttons)[0];
        }

        return ViberClient::textMessage($text !== '' ? $text : ' ');
    }

    private function mirror(ViberBroadcast $broadcast, ViberBroadcastRecipient $row): void
    {
        if (! $row->conversation_id) {
            return;
        }
        try {
            InboxMessage::create([
                'conversation_id' => $row->conversation_id, 'provider' => 'viber', 'direction' => 'out',
                'body' => (string) $broadcast->body, 'status' => 'sent',
                'meta' => array_filter(['source' => 'viber_broadcast', 'broadcast_id' => $broadcast->id]), 'sent_at' => now(),
            ]);
            Conversation::whereKey($row->conversation_id)->update(['last_message_at' => now(), 'last_outbound_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('[VIBER-BROADCAST] mirror failed: '.$e->getMessage());
        }
    }

    private function storeMedia($file): array
    {
        if (! $file) {
            return [null, null];
        }
        $path = $file->store('viber-broadcasts', media_disk());
        $ext  = strtolower((string) $file->getClientOriginalExtension());
        $kind = $ext === 'mp4' ? 'video' : 'image';

        return [$path, $kind];
    }

    private function authorizeBroadcast(ViberBroadcast $broadcast): void
    {
        abort_unless((int) $broadcast->workspace_id === $this->workspaceId(), 404);
    }

    private function workspaceId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }
}
