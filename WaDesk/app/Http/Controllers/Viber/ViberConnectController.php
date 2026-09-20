<?php

namespace App\Http\Controllers\Viber;

use App\Http\Controllers\Controller;
use App\Models\ViberChannel;
use App\Services\PlanLimitGuard;
use App\Services\Viber\ViberClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Viber Public Account connect + manage. A workspace pastes its bot auth token;
 * we validate it via get_account_info (which also gives us the bot name/uri/icon),
 * store it encrypted, and auto-register our per-channel webhook via set_webhook
 * (Viber needs a valid CA HTTPS URL). Mirrors LineConnectController.
 */
class ViberConnectController extends Controller
{
    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    private function planOk(): bool
    {
        $ws = Auth::user()?->currentWorkspace;

        return $ws ? (bool) PlanLimitGuard::hasFeature($ws, 'access_viber') : false;
    }

    public function index(): View
    {
        $channels = ViberChannel::allForWorkspace($this->wsId());

        return view('user.viber.index', compact('channels'));
    }

    /** Connect a channel from a pasted auth token. */
    public function connect(Request $request): RedirectResponse
    {
        if (! $this->planOk()) {
            return back()->withErrors(['auth_token' => __('Your plan does not include Viber.')]);
        }
        $data = $request->validate([
            'auth_token' => ['required', 'string', 'min:20', 'max:255'],
        ]);

        $token  = trim($data['auth_token']);
        $client = new ViberClient($token);

        // Validate the token + pull the bot identity.
        $info = $client->getAccountInfo();
        if (! ($info['ok'] ?? false)) {
            return back()->withErrors(['auth_token' => __('Viber rejected that auth token: ').($info['error'] ?? '')])->withInput();
        }

        $wsId = $this->wsId();
        if ($wsId <= 0) {
            return back()->withErrors(['auth_token' => __('No active workspace — switch to one first.')]);
        }

        $viberId = (string) data_get($info, 'data.id', '');
        $channel = ($viberId !== '' ? ViberChannel::where('workspace_id', $wsId)->where('viber_id', $viberId)->first() : null)
            ?? new ViberChannel(['workspace_id' => $wsId]);

        $channel->fill([
            'connected_by'  => Auth::id(),
            'auth_token'    => $token,
            'viber_id'      => $viberId,
            'bot_name'      => (string) data_get($info, 'data.name', '') ?: 'Viber',
            'bot_uri'       => (string) data_get($info, 'data.uri', ''),
            'bot_avatar'    => (string) data_get($info, 'data.icon', ''),
            'webhook_token' => $channel->webhook_token ?: ViberChannel::freshWebhookToken(),
            'active'        => true,
            'connected_at'  => now(),
            'last_error'    => null,
        ]);
        $channel->save();

        // Register our webhook with Viber (needs the row's routing token in the URL).
        $hook = (new ViberClient($token, $channel->senderObject()))->setWebhook($channel->webhookUrl());
        if (! ($hook['ok'] ?? false)) {
            $channel->forceFill(['last_error' => mb_substr((string) ($hook['error'] ?? ''), 0, 255)])->save();

            return back()->withErrors(['auth_token' => __('Saved the channel, but Viber refused the webhook: ')
                .($hook['error'] ?? '')
                .__(' — the URL must be public HTTPS with a valid CA certificate (a local/IP address is rejected).')]);
        }

        Log::info('[VIBER] connected', ['workspace' => $wsId, 'channel' => $channel->id]);

        return back()->with('status', __('Viber connected — ').$channel->bot_name);
    }

    /** Re-register the webhook for a saved channel. */
    public function retry(ViberChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($channel);
        $hook = (new ViberClient((string) $channel->auth_token, $channel->senderObject()))->setWebhook($channel->webhookUrl());
        $channel->forceFill(['last_error' => empty($hook['ok']) ? mb_substr((string) ($hook['error'] ?? ''), 0, 255) : null])->save();

        return back()->with(empty($hook['ok']) ? 'error' : 'status',
            empty($hook['ok']) ? __('Viber refused the webhook: ').($hook['error'] ?? '') : __('Webhook re-registered.'));
    }

    public function toggle(ViberChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($channel);
        $channel->forceFill(['active' => ! $channel->active])->save();

        return back()->with('status', $channel->active ? __('Viber channel enabled.') : __('Viber channel paused.'));
    }

    public function destroy(ViberChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($channel);
        // Best-effort: remove the webhook from Viber so it stops pushing.
        try {
            (new ViberClient((string) $channel->auth_token))->removeWebhook();
        } catch (\Throwable $e) { /* best effort */ }
        $channel->delete();

        return back()->with('status', __('Viber channel removed.'));
    }

    private function authorizeChannel(ViberChannel $channel): void
    {
        abort_unless((int) $channel->workspace_id === $this->wsId(), 403);
    }
}
