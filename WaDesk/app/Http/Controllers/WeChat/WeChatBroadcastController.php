<?php

namespace App\Http\Controllers\WeChat;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\WeChatBroadcast;
use App\Models\WeChatBroadcastRecipient;
use App\Models\WeChatChannel;
use App\Services\WeChat\WeChatChats;
use App\Services\WeChat\WeChatClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * WeChat broadcasts — WeChat's mass-send (群发). Unlike LINE's page-drained
 * per-recipient loop, WeChat mass-send is ONE API call to an audience (all
 * followers, a tag, or an OpenID list ≥2). WeChat caps Service Accounts at ~4
 * mass sends per MONTH, so this is a one-shot send, not a drain. Mass-send
 * supports text/image/voice/mpnews — NOT interactive menus — so a broadcast
 * sends the body text (URL buttons appended as links).
 */
class WeChatBroadcastController extends Controller
{
    public function index(): View
    {
        $wsId = $this->workspaceId();

        return view('user.wechat.broadcasts', [
            'channels'   => WeChatChannel::allForWorkspace($wsId),
            'broadcasts' => WeChatBroadcast::where('workspace_id', $wsId)->with('channel')->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function create(): View
    {
        $wsId = $this->workspaceId();
        $channels = WeChatChannel::allForWorkspace($wsId);
        $chats = [];
        foreach ($channels as $ch) {
            $chats[$ch->id] = WeChatChats::forChannel($ch)->values()->all();
        }
        $templates = \App\Models\WaTemplate::query()->forCurrentWorkspace()
            ->orderByDesc('id')->get()
            ->filter(fn ($t) => $t->engineKey() === 'wechat')
            ->map(fn ($t) => ['id' => $t->id, 'name' => (string) $t->template_name, 'body' => (string) $t->template_body])
            ->values();

        return view('user.wechat.broadcasts-create', compact('channels', 'chats', 'templates'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:191'],
            'wechat_channel_id' => ['required', 'integer'],
            'template_id'       => ['nullable', 'integer'],
            'body'              => ['required', 'string', 'max:2000'],
            'audience'          => ['required', 'in:all,tag,list'],
            'tag_id'            => ['nullable', 'string', 'max:32'],
            'openids'           => ['nullable', 'array'],
            'openids.*'         => ['string', 'max:64'],
        ]);

        $wsId = $this->workspaceId();
        $channel = WeChatChannel::where('workspace_id', $wsId)->find($data['wechat_channel_id']);
        if (! $channel) {
            return back()->with('error', 'Pick a WeChat channel that belongs to this workspace.')->withInput();
        }

        $body = trim((string) $data['body']);
        $recipients = collect();
        $total = 0;

        if ($data['audience'] === 'list') {
            $available = WeChatChats::forChannel($channel)->keyBy('openid');
            $recipients = collect($data['openids'] ?? [])->map(fn ($v) => trim((string) $v))->unique()
                ->filter(fn ($id) => $available->has($id))->map(fn ($id) => $available->get($id))->values();
            if ($recipients->count() < 2) {
                return back()->with('error', 'A WeChat OpenID broadcast needs at least 2 recipients from the list.')->withInput();
            }
            $total = $recipients->count();
        }

        $broadcast = WeChatBroadcast::create([
            'workspace_id'      => $wsId,
            'wechat_channel_id' => $channel->id,
            'user_id'           => Auth::id(),
            'name'              => $data['name'],
            'body'              => $body,
            'audience'          => $data['audience'],
            'tag_id'            => $data['audience'] === 'tag' ? (string) ($data['tag_id'] ?? '') : null,
            'status'            => WeChatBroadcast::STATUS_DRAFT,
            'total'             => $total,
        ]);

        foreach ($recipients->chunk(500) as $chunk) {
            WeChatBroadcastRecipient::insert($chunk->map(fn ($c) => [
                'wechat_broadcast_id' => $broadcast->id,
                'openid'              => (string) $c['openid'],
                'title'               => mb_substr((string) $c['title'], 0, 191),
                'conversation_id'     => (int) $c['id'],
                'status'              => WeChatBroadcastRecipient::STATUS_PENDING,
                'created_at'          => now(),
                'updated_at'          => now(),
            ])->all());
        }

        return redirect('/wechat/broadcasts')->with('success', 'Broadcast created. Press Send to mass-send it (WeChat limits Service Accounts to ~4 per month).');
    }

    /** Perform the mass-send in ONE call (all / tag / openid list). */
    public function send(WeChatBroadcast $broadcast): RedirectResponse
    {
        $this->authorize_($broadcast);
        if ($broadcast->status === WeChatBroadcast::STATUS_DONE) {
            return back()->with('error', 'This broadcast was already sent.');
        }
        $channel = $broadcast->channel;
        if (! $channel || ! $channel->active) {
            return back()->with('error', 'The WeChat channel for this broadcast is not connected/active.');
        }

        $client  = new WeChatClient($channel);
        $message = WeChatClient::textMessage((string) $broadcast->body);

        $res = match ($broadcast->audience) {
            'all'  => $client->massSendAll(['is_to_all' => true], $message),
            'tag'  => $client->massSendAll(['tag_id' => (int) $broadcast->tag_id], $message),
            default => $client->massSend($broadcast->recipients()->pluck('openid')->all(), $message),
        };

        if (! ($res['ok'] ?? false)) {
            $broadcast->forceFill(['status' => WeChatBroadcast::STATUS_FAILED, 'last_error' => mb_substr((string) ($res['error'] ?? 'mass send failed'), 0, 255)])->save();

            return back()->with('error', 'WeChat rejected the broadcast: '.($res['error'] ?? 'unknown'));
        }

        $broadcast->forceFill([
            'status'      => WeChatBroadcast::STATUS_DONE,
            'mass_msg_id' => (string) (data_get($res, 'data.msg_id') ?? ''),
            'sent'        => (int) $broadcast->total,
            'started_at'  => now(),
            'finished_at' => now(),
            'last_error'  => null,
        ])->save();

        // Mirror to the inbox for the list audience (per-recipient threads).
        if ($broadcast->audience === 'list') {
            foreach ($broadcast->recipients as $r) {
                $r->forceFill(['status' => WeChatBroadcastRecipient::STATUS_SENT, 'sent_at' => now()])->save();
                $this->mirror($r->conversation_id, (string) $broadcast->body);
            }
        }

        return back()->with('success', 'Broadcast sent via WeChat mass-send.');
    }

    public function destroy(WeChatBroadcast $broadcast): RedirectResponse
    {
        $this->authorize_($broadcast);
        $broadcast->recipients()->delete();
        $broadcast->delete();

        return back()->with('success', 'Broadcast deleted.');
    }

    private function mirror(?int $conversationId, string $body): void
    {
        if (! $conversationId) {
            return;
        }
        try {
            InboxMessage::create([
                'conversation_id' => $conversationId,
                'provider'        => 'wechat',
                'direction'       => 'out',
                'body'            => $body,
                'status'          => 'sent',
                'meta'            => ['wechat' => ['source' => 'broadcast']],
                'sent_at'         => now(),
            ]);
            Conversation::whereKey($conversationId)->update(['last_message_at' => now(), 'last_outbound_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('[WECHAT-BROADCAST] mirror failed: '.$e->getMessage());
        }
    }

    private function authorize_(WeChatBroadcast $broadcast): void
    {
        abort_unless((int) $broadcast->workspace_id === $this->workspaceId(), 404);
    }

    private function workspaceId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }
}
