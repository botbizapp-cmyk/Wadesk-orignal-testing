<?php

namespace App\Http\Controllers\Line;

use App\Http\Controllers\Controller;
use App\Models\LineChannel;
use App\Services\Line\LineClient;
use App\Services\PlanLimitGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * LINE channel connect + manage. A workspace pastes a Channel access token +
 * Channel secret; we validate via GET /v2/bot/info, store both encrypted, and
 * register the per-channel webhook (PUT /v2/bot/channel/webhook/endpoint).
 * Mirrors TelegramConnectController in shape.
 */
class LineConnectController extends Controller
{
    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    private function planOk(): bool
    {
        $ws = Auth::user()?->currentWorkspace;

        return $ws ? (bool) PlanLimitGuard::hasFeature($ws, 'access_line') : false;
    }

    /** Manage page — connected LINE channels + connect form. */
    public function index(): View
    {
        $channels = LineChannel::allForWorkspace($this->wsId());

        return view('user.line.index', compact('channels'));
    }

    /** Connect a channel from a pasted access token + channel secret. */
    public function connect(Request $request): RedirectResponse
    {
        if (! $this->planOk()) {
            return back()->withErrors(['channel_access_token' => __('Your plan does not include LINE.')]);
        }
        $data = $request->validate([
            'channel_access_token' => ['required', 'string', 'min:20', 'max:1000'],
            'channel_secret'       => ['required', 'string', 'min:16', 'max:255'],
        ]);

        $res = $this->adoptToken(trim($data['channel_access_token']), trim($data['channel_secret']));
        if (! $res['ok']) {
            return back()->withErrors(['channel_access_token' => $res['error']]);
        }

        return back()->with('status', __('LINE connected — ').$res['channel']->display_name);
    }

    /**
     * Validate the token, store the channel, register its webhook. Re-pasting the
     * same OA's credentials updates the SAME row (matched on the LINE basic id),
     * reusing its routing token.
     */
    public function adoptToken(string $accessToken, string $channelSecret): array
    {
        $client = new LineClient($accessToken);
        $info = $client->botInfo();
        if (empty($info['ok'])) {
            return ['ok' => false, 'error' => __('LINE rejected that channel access token: ').($info['error'] ?? '')];
        }

        $wsId = $this->wsId();
        if ($wsId <= 0) {
            return ['ok' => false, 'error' => __('No active workspace — switch to one first.')];
        }

        $basicId = (string) data_get($info, 'data.basicId', '');
        $userId  = (string) data_get($info, 'data.userId', '');
        $channel = ($basicId !== '' ? LineChannel::where('workspace_id', $wsId)->where('basic_id', $basicId)->first() : null)
            ?? new LineChannel(['workspace_id' => $wsId]);

        $channel->fill([
            'connected_by'         => Auth::id(),
            'channel_access_token' => $accessToken,
            'channel_secret'       => $channelSecret,
            'line_channel_id'      => $userId,
            'basic_id'             => $basicId,
            'display_name'         => (string) data_get($info, 'data.displayName', '') ?: 'LINE',
            'picture_url'          => (string) data_get($info, 'data.pictureUrl', ''),
            'webhook_token'        => $channel->webhook_token ?: LineChannel::freshWebhookToken(),
            'active'               => true,
            'connected_at'         => now(),
            'last_error'           => null,
        ]);
        $channel->save();

        // Register AFTER the row exists — the URL carries the routing token.
        $hook = $client->setWebhookEndpoint($channel->webhookUrl());
        if (empty($hook['ok'])) {
            $channel->forceFill(['last_error' => mb_substr((string) ($hook['error'] ?? ''), 0, 255)])->save();

            return ['ok' => false, 'error' => __('Saved the channel, but LINE refused the webhook: ')
                .($hook['error'] ?? '')
                .__(' — the URL must be public HTTPS. On a local machine, use a tunnel (ngrok / Cloudflare Tunnel).')];
        }

        Log::info('[LINE] connected', ['workspace' => $wsId, 'channel' => $channel->display_name]);

        return ['ok' => true, 'channel' => $channel];
    }

    /** Re-register the webhook for a saved channel. */
    public function retry(LineChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($channel);
        $hook = (new LineClient((string) $channel->activeAccessToken()))->setWebhookEndpoint($channel->webhookUrl());
        $channel->forceFill(['last_error' => empty($hook['ok']) ? mb_substr((string) ($hook['error'] ?? ''), 0, 255) : null])->save();

        return back()->with(empty($hook['ok']) ? 'error' : 'status',
            empty($hook['ok']) ? __('LINE refused the webhook: ').($hook['error'] ?? '') : __('Webhook re-registered.'));
    }

    /**
     * Enable auto-rotating v2.1 tokens for a channel. The operator pastes the RSA
     * private key + JWK `kid` of the Assertion Signing Key registered in the LINE
     * console; we store both (key encrypted) and mint the first token immediately
     * so the setup is verified before they leave the page. Posting empty fields
     * clears rotation and reverts to the long-lived token.
     */
    public function rotation(Request $request, LineChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($channel);
        $data = $request->validate([
            'assertion_kid'         => ['nullable', 'string', 'max:128'],
            'assertion_private_key' => ['nullable', 'string', 'max:8000'],
        ]);

        $kid = trim((string) ($data['assertion_kid'] ?? ''));
        $key = trim((string) ($data['assertion_private_key'] ?? ''));

        // Clear → back to the pasted long-lived token.
        if ($kid === '' || $key === '') {
            $channel->forceFill([
                'assertion_kid'             => null,
                'assertion_private_key'     => null,
                'rotating_token'            => null,
                'rotating_token_key_id'     => null,
                'rotating_token_expires_at' => null,
            ])->save();

            return back()->with('status', __('Token rotation disabled — using the long-lived token.'));
        }

        if (trim((string) $channel->line_channel_id) === '') {
            return back()->with('error', __('This channel has no numeric channel id yet — reconnect it first.'));
        }

        $channel->forceFill(['assertion_kid' => $kid, 'assertion_private_key' => $key])->save();

        $token = $channel->rotateToken();
        if ($token === '') {
            return back()->with('error', __('Could not mint a v2.1 token: ').($channel->last_error ?: __('check the key + kid.')));
        }

        return back()->with('status', __('Token rotation enabled — a fresh v2.1 token was issued and will auto-renew.'));
    }

    public function toggle(LineChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($channel);
        $channel->forceFill(['active' => ! $channel->active])->save();

        return back()->with('status', $channel->active ? __('LINE channel enabled.') : __('LINE channel paused.'));
    }

    public function destroy(LineChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($channel);
        $channel->delete();

        return back()->with('status', __('LINE channel removed.'));
    }

    /** Guard: the channel must belong to the acting user's current workspace. */
    private function authorizeChannel(LineChannel $channel): void
    {
        abort_unless((int) $channel->workspace_id === $this->wsId(), 403);
    }
}
