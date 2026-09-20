<?php

namespace App\Http\Controllers\WeChat;

use App\Http\Controllers\Controller;
use App\Models\WeChatChannel;
use App\Services\PlanLimitGuard;
use App\Services\WeChat\WeChatClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * WeChat Official Account connect + manage. A workspace pastes AppID + AppSecret
 * + Token (+ optional EncodingAESKey); we validate the credentials by fetching an
 * access_token, store them encrypted, and hand back the webhook URL + Token for
 * the tenant to paste into the OA admin's Server Config (WeChat has no API to set
 * the webhook — it's manual, unlike LINE). The OA's wx_id is filled on first
 * inbound. Mirrors LineConnectController in shape.
 */
class WeChatConnectController extends Controller
{
    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    private function planOk(): bool
    {
        $ws = Auth::user()?->currentWorkspace;

        return $ws ? (bool) PlanLimitGuard::hasFeature($ws, 'access_wechat') : false;
    }

    public function index(): View
    {
        $channels = WeChatChannel::allForWorkspace($this->wsId());

        return view('user.wechat.index', compact('channels'));
    }

    /** Connect a channel from pasted AppID + AppSecret + Token (+ AES key). */
    public function connect(Request $request): RedirectResponse
    {
        if (! $this->planOk()) {
            return back()->withErrors(['app_id' => __('Your plan does not include WeChat.')]);
        }
        $data = $request->validate([
            'app_id'           => ['required', 'string', 'max:64'],
            'app_secret'       => ['required', 'string', 'max:128'],
            'verify_token'     => ['required', 'string', 'max:128'],
            'encoding_aes_key' => ['nullable', 'string', 'size:43'],
            'enc_mode'         => ['nullable', 'in:plain,compat,safe'],
            'account_name'     => ['nullable', 'string', 'max:191'],
        ]);

        // Validate the credentials by fetching an access_token.
        $tok = WeChatClient::fetchAccessToken(trim($data['app_id']), trim($data['app_secret']));
        if (! ($tok['ok'] ?? false)) {
            return back()->withErrors(['app_secret' => __('WeChat rejected those credentials: ').($tok['error'] ?? '')])->withInput();
        }

        $wsId = $this->wsId();
        if ($wsId <= 0) {
            return back()->withErrors(['app_id' => __('No active workspace — switch to one first.')]);
        }

        // Re-pasting the same OA (matched on app_id) updates the SAME row, reusing
        // its routing token so the webhook URL the tenant registered stays valid.
        $channel = WeChatChannel::where('workspace_id', $wsId)->where('app_id', trim($data['app_id']))->first()
            ?? new WeChatChannel(['workspace_id' => $wsId]);

        $channel->fill([
            'connected_by'        => Auth::id(),
            'app_id'              => trim($data['app_id']),
            'app_secret'          => trim($data['app_secret']),
            'verify_token'        => trim($data['verify_token']),
            'encoding_aes_key'    => trim((string) ($data['encoding_aes_key'] ?? '')) ?: null,
            'enc_mode'            => $data['enc_mode'] ?? 'compat',
            'account_name'        => trim((string) ($data['account_name'] ?? '')) ?: 'WeChat',
            'webhook_token'       => $channel->webhook_token ?: WeChatChannel::freshWebhookToken(),
            'cached_access_token' => (string) $tok['access_token'],
            'token_expires_at'    => now()->addSeconds(max(60, (int) ($tok['expires_in'] ?? 7200))),
            'active'              => true,
            'connected_at'        => now(),
            'last_error'          => null,
        ]);
        $channel->save();

        Log::info('[WECHAT] connected', ['workspace' => $wsId, 'channel' => $channel->id]);

        return back()->with('status', __('WeChat connected — now paste the webhook URL + Token below into your WeChat Official Account → Settings → Server Config, and enable it.'));
    }

    /** Re-validate a saved channel's credentials (refresh the token). */
    public function retry(WeChatChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($channel);
        $token = $channel->refreshAccessToken();
        $ok = $token !== '';
        $channel->forceFill(['last_error' => $ok ? null : ($channel->last_error ?: 'token refresh failed')])->save();

        return back()->with($ok ? 'status' : 'error',
            $ok ? __('WeChat credentials re-validated.') : __('WeChat refused the credentials: ').$channel->last_error);
    }

    public function toggle(WeChatChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($channel);
        $channel->forceFill(['active' => ! $channel->active])->save();

        return back()->with('status', $channel->active ? __('WeChat channel enabled.') : __('WeChat channel paused.'));
    }

    public function destroy(WeChatChannel $channel): RedirectResponse
    {
        $this->authorizeChannel($channel);
        $channel->delete();

        return back()->with('status', __('WeChat channel removed.'));
    }

    private function authorizeChannel(WeChatChannel $channel): void
    {
        abort_unless((int) $channel->workspace_id === $this->wsId(), 403);
    }
}
