<?php

namespace App\Http\Controllers\Facebook;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\FacebookBroadcast;
use App\Models\FacebookBroadcastRecipient;
use App\Models\FacebookPage;
use App\Models\InboxMessage;
use App\Services\Facebook\FacebookPageClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Facebook Messenger broadcasts — parity with Telegram broadcasts. Audience is
 * the Page's existing inbox threads still inside Meta's 24h standard-messaging
 * window (a Page may only message a PSID that messaged it in the last 24h). The
 * send loop is browser-driven and batched — no queue, no cron — exactly like the
 * Telegram module. Sends mirror into the inbox thread.
 */
class FacebookBroadcastController extends Controller
{
    /** Recipients sent per browser-driven batch tick. */
    private const BATCH = 25;

    /** Meta standard-messaging window. */
    private const WINDOW_HOURS = 24;

    public function index(): View
    {
        $wsId  = $this->workspaceId();
        $pages = FacebookPage::forWorkspace($wsId)->connected()->orderBy('name')->get();

        // Eligible audience per page (24h window), resolved once for the form.
        $audience = [];
        foreach ($pages as $page) {
            $audience[$page->id] = $this->eligibleAudience($page)->all();
        }

        // Local Facebook templates — prefill body + carry buttons (same list the
        // inbox composer uses). Channel-scoped so no WhatsApp/other-channel bleed.
        $templates = \App\Models\WaTemplate::query()->forCurrentWorkspace()
            ->orderByDesc('id')->get()
            ->filter(fn ($t) => $t->engineKey() === 'facebook')
            ->map(fn ($t) => [
                'id'      => $t->id,
                'name'    => (string) $t->template_name,
                'body'    => (string) $t->template_body,
                'buttons' => is_array($t->buttons) ? array_values($t->buttons) : [],
            ])->values();

        return view('user.facebook.broadcasts', [
            'broadcasts' => FacebookBroadcast::query()
                ->where('workspace_id', $wsId)
                ->with('page')
                ->orderByDesc('id')->limit(50)->get(),
            'pages'     => $pages,
            'audience'  => $audience,
            'templates' => $templates,
        ]);
    }

    /**
     * Create a broadcast and snapshot its recipient list NOW — a broadcast that
     * reads "sent to 40" must mean the 40 who were eligible when it ran, not
     * everyone who messaged since.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:191'],
            'facebook_page_id' => ['required', 'integer'],
            'template_id'      => ['nullable', 'integer'],
            'body'             => ['nullable', 'string', 'max:2000'],
            'psids'            => ['required', 'array', 'min:1'],
            'psids.*'          => ['string', 'max:64'],
        ]);

        $wsId = $this->workspaceId();
        $page = FacebookPage::forWorkspace($wsId)->connected()->find($data['facebook_page_id']);
        if (! $page) {
            return back()->with('error', 'Pick a connected Facebook Page.');
        }

        $body = trim((string) ($data['body'] ?? ''));

        // Optional local Facebook template — its body prefills an empty composer,
        // its buttons ride along. Workspace + channel scoped against forgery.
        $buttons = null;
        $templateId = null;
        if (! empty($data['template_id'])) {
            $tpl = \App\Models\WaTemplate::query()->forCurrentWorkspace()->where('id', (int) $data['template_id'])->first();
            if ($tpl && $tpl->engineKey() === 'facebook') {
                $templateId = (int) $tpl->id;
                if ($body === '') {
                    $body = (string) $tpl->template_body;
                }
                if (is_array($tpl->buttons) && $tpl->buttons) {
                    $buttons = array_values($tpl->buttons);
                }
            }
        }

        if ($body === '') {
            return back()->with('error', 'A broadcast needs a message.');
        }

        // Resolve the posted PSIDs against the page's OWN eligible audience — a
        // crafted id that is not a real 24h-window thread becomes a no-op.
        $available  = $this->eligibleAudience($page)->keyBy('psid');
        $picked     = collect($data['psids'])->map(fn ($v) => trim((string) $v))->unique();
        $recipients = $picked->filter(fn ($id) => $available->has($id))->map(fn ($id) => $available->get($id))->values();

        if ($recipients->isEmpty()) {
            return back()->with('error', 'None of those recipients are within the 24-hour window. Pick from the list.');
        }

        $broadcast = FacebookBroadcast::create([
            'workspace_id'     => $wsId,
            'facebook_page_id' => $page->id,
            'user_id'          => Auth::id(),
            'name'             => $data['name'],
            'template_id'      => $templateId,
            'body'             => $body,
            'buttons'          => $buttons,
            'status'           => FacebookBroadcast::STATUS_DRAFT,
            'total'            => $recipients->count(),
        ]);

        foreach ($recipients->chunk(500) as $chunk) {
            FacebookBroadcastRecipient::insert($chunk->map(fn ($c) => [
                'facebook_broadcast_id' => $broadcast->id,
                'psid'            => (string) $c['psid'],
                'title'           => mb_substr((string) $c['title'], 0, 191),
                'conversation_id' => (int) $c['id'],
                'status'          => FacebookBroadcastRecipient::STATUS_PENDING,
                'created_at'      => now(),
                'updated_at'      => now(),
            ])->all());
        }

        return redirect('/facebook/broadcasts')
            ->with('success', 'Broadcast created with '.$recipients->count().' recipients. Press Start sending when ready.');
    }

    public function start(FacebookBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);
        if (! $broadcast->hasPending()) {
            return back()->with('error', 'Every recipient in this broadcast has already been handled.');
        }
        $page = $broadcast->page;
        if (! $page || ! $page->isLive()) {
            return back()->with('error', 'The Page this broadcast was built for is no longer connected.');
        }
        $broadcast->forceFill([
            'status'     => FacebookBroadcast::STATUS_SENDING,
            'started_at' => $broadcast->started_at ?: now(),
            'last_error' => null,
        ])->save();

        return back()->with('success', 'Sending started. Leave this page open until it finishes.');
    }

    public function pause(FacebookBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);
        $broadcast->forceFill(['status' => FacebookBroadcast::STATUS_DRAFT])->save();

        return back()->with('success', 'Paused. The remaining recipients are still queued.');
    }

    /** Re-queue FAILED rows (transient errors). Blocked rows are left alone. */
    public function retry(FacebookBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);
        $n = $broadcast->recipients()->where('status', FacebookBroadcastRecipient::STATUS_FAILED)
            ->update(['status' => FacebookBroadcastRecipient::STATUS_PENDING, 'error' => null]);
        if ($n === 0) {
            return back()->with('error', 'No failed recipients to retry.');
        }
        $broadcast->forceFill(['failed' => max(0, (int) $broadcast->failed - $n)])->save();

        return back()->with('success', "Re-queued {$n} recipient(s). Press Start sending.");
    }

    public function sendBatch(FacebookBroadcast $broadcast): JsonResponse
    {
        $this->authorizeBroadcast($broadcast);
        if ($broadcast->status !== FacebookBroadcast::STATUS_SENDING) {
            return response()->json(['ok' => false, 'error' => 'This broadcast is not sending.'], 422);
        }
        $page = $broadcast->page;
        if (! $page || ! $page->isLive()) {
            return $this->stop($broadcast, 'The Page this broadcast belongs to is no longer connected.');
        }

        $client  = new FacebookPageClient($page);
        $body    = (string) ($broadcast->body ?? '');
        $buttons = $this->fbButtons(is_array($broadcast->buttons) ? $broadcast->buttons : []);
        $cutoff  = now()->subHours(self::WINDOW_HOURS);

        $rows = $broadcast->recipients()->where('status', FacebookBroadcastRecipient::STATUS_PENDING)
            ->orderBy('id')->limit(self::BATCH)->get();

        foreach ($rows as $row) {
            // Re-check the 24h window per recipient — a batch can run minutes after
            // start, and the window closes on the customer's LAST inbound message.
            $lastIn = $row->conversation_id
                ? Conversation::whereKey($row->conversation_id)->value('last_inbound_at')
                : null;
            if (! $lastIn || Carbon::parse($lastIn)->lt($cutoff)) {
                $row->forceFill([
                    'status' => FacebookBroadcastRecipient::STATUS_BLOCKED,
                    'error'  => 'Outside the 24-hour messaging window.',
                ])->save();
                $broadcast->increment('blocked');
                continue;
            }

            $text = $this->personalise($body, $row);
            $res  = $buttons
                ? $client->sendButtonTemplate($row->psid, $text, $buttons)
                : $client->sendMessage($row->psid, $text);

            if ($res['ok'] ?? false) {
                $mid = (string) ($res['mid'] ?? '');
                $row->forceFill([
                    'status'              => FacebookBroadcastRecipient::STATUS_SENT,
                    'provider_message_id' => $mid,
                    'sent_at'             => now(),
                    'error'               => null,
                ])->save();
                $broadcast->increment('sent');
                $this->mirror($broadcast, $row, $text, $mid);
                continue;
            }

            $error = (string) ($res['error'] ?? 'Facebook rejected the send.');
            $gone  = FacebookBroadcastRecipient::isUnreachable($error);
            $row->forceFill([
                'status' => $gone ? FacebookBroadcastRecipient::STATUS_BLOCKED : FacebookBroadcastRecipient::STATUS_FAILED,
                'error'  => mb_substr($error, 0, 255),
            ])->save();
            $broadcast->increment($gone ? 'blocked' : 'failed');
        }

        $broadcast->refresh();
        $pending = $broadcast->recipients()->where('status', FacebookBroadcastRecipient::STATUS_PENDING)->count();
        if ($pending === 0) {
            $broadcast->forceFill(['status' => FacebookBroadcast::STATUS_DONE, 'finished_at' => now()])->save();
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

    public function destroy(FacebookBroadcast $broadcast): RedirectResponse
    {
        $this->authorizeBroadcast($broadcast);
        $broadcast->recipients()->delete();
        $broadcast->delete();

        return back()->with('success', 'Broadcast deleted.');
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /** Messenger DM threads for a page still inside the 24h window. */
    private function eligibleAudience(FacebookPage $page): Collection
    {
        $cutoff = now()->subHours(self::WINDOW_HOURS);

        return Conversation::query()
            ->where('workspace_id', $page->workspace_id)
            ->where('channel', 'facebook')
            ->where('raw_jid', 'like', 'fb:'.$page->page_id.':%')
            ->where('raw_jid', 'not like', 'fb:'.$page->page_id.':post:%') // exclude comment threads
            ->where('last_inbound_at', '>=', $cutoff)
            ->orderByDesc('last_inbound_at')
            ->get(['id', 'raw_jid', 'title', 'last_inbound_at'])
            ->map(function ($c) {
                $parts = explode(':', (string) $c->raw_jid); // fb:pageId:psid
                $psid  = $parts[2] ?? '';

                return $psid === '' ? null : [
                    'id'    => (int) $c->id,
                    'psid'  => $psid,
                    'title' => (string) ($c->title ?: $psid),
                ];
            })
            ->filter()
            ->values();
    }

    /** Map generic template buttons → Facebook Send-API button shape (max 3). */
    private function fbButtons(array $buttons): array
    {
        $out = [];
        foreach (array_slice($buttons, 0, 3) as $b) {
            $title = (string) ($b['text'] ?? $b['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $type = (string) ($b['type'] ?? 'quick_reply');
            if ($type === 'url' && ! empty($b['url'])) {
                $out[] = ['type' => 'web_url', 'title' => mb_substr($title, 0, 20), 'url' => (string) $b['url']];
            } elseif ($type === 'phone' && ! empty($b['phone'] ?? $b['phone_number'] ?? null)) {
                $out[] = ['type' => 'phone_number', 'title' => mb_substr($title, 0, 20), 'payload' => (string) ($b['phone'] ?? $b['phone_number'])];
            } else {
                $out[] = ['type' => 'postback', 'title' => mb_substr($title, 0, 20), 'payload' => 'tqr:'.$title];
            }
        }

        return $out;
    }

    private function personalise(string $body, FacebookBroadcastRecipient $row): string
    {
        return trim(str_replace(['{{name}}', '{{ name }}'], (string) ($row->title ?: ''), $body));
    }

    /** A sent broadcast belongs in the thread an operator would read it in. */
    private function mirror(FacebookBroadcast $broadcast, FacebookBroadcastRecipient $row, string $body, string $mid): void
    {
        if (! $row->conversation_id) {
            return;
        }
        try {
            InboxMessage::create([
                'conversation_id' => $row->conversation_id,
                'provider'        => 'facebook',
                'direction'       => 'out',
                'body'            => $body,
                'status'          => 'sent',
                'meta'            => array_filter([
                    'source'        => 'facebook_broadcast',
                    'broadcast_id'  => $broadcast->id,
                    'wa_message_id' => $mid ?: null,
                ]),
                'sent_at' => now(),
            ]);
            Conversation::whereKey($row->conversation_id)->update(['last_message_at' => now(), 'last_outbound_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('[FB-BROADCAST] mirror failed: '.$e->getMessage());
        }
    }

    private function stop(FacebookBroadcast $broadcast, string $why): JsonResponse
    {
        $broadcast->forceFill(['status' => FacebookBroadcast::STATUS_FAILED, 'last_error' => mb_substr($why, 0, 255)])->save();

        return response()->json(['ok' => false, 'error' => $why], 422);
    }

    private function authorizeBroadcast(FacebookBroadcast $broadcast): void
    {
        abort_unless((int) $broadcast->workspace_id === $this->workspaceId(), 404);
    }

    private function workspaceId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }
}
