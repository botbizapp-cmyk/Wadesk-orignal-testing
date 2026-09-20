<?php

namespace App\Http\Controllers\Telegram;

use App\Models\TelegramAccount;
use App\Services\Telegram\TelegramAccountBridge;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * Log a Telegram USER account in, and create bots with it.
 *
 * Why this exists: Telegram has no API that creates a bot. Only @BotFather can,
 * and only a real user account can message @BotFather. So making a bot from the
 * dashboard means holding a user login.
 *
 * That login is far more powerful than a bot token — it can read every private
 * chat on the account. Consequences that shape this controller:
 *
 *  - the session is never rendered, never echoed, never logged
 *  - the login code and 2FA password are not held in the Laravel session
 *  - a created bot is handed to TelegramAdminController::adoptToken(), so it goes
 *    through the same getMe + setWebhook path as a pasted token
 *
 * Only the in-progress loginId is kept server-side between the two steps, and it
 * is an opaque handle, not a credential.
 */
class TelegramAccountController extends Controller
{
    /** Session key holding the in-flight login handle. */
    private const LOGIN_KEY = 'telegram.account.login_id';

    public function __construct(private TelegramAccountBridge $bridge)
    {
    }

    /**
     * The active workspace.
     *
     * `current_workspace_id` — the column the rest of this app switches on. An
     * earlier version read `workspace_id`, which does not carry the ACTIVE
     * workspace, so every action here failed with "No active workspace" while the
     * same page listed two connected bots perfectly well. Must stay identical to
     * TelegramAdminController::workspaceId(), or a bot created here would be
     * filed against a different workspace than the one on screen.
     */
    private function workspaceId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    /** The workspace's account, or null. */
    private function account(): ?TelegramAccount
    {
        return TelegramAccount::forWorkspace($this->workspaceId());
    }

    /** Step 1 — ask Telegram to send a login code. */
    public function sendCode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:24', 'regex:/^\+?\d{6,15}$/'],
        ], [
            'phone.regex' => __('Enter the number with its country code, e.g. +919876543210.'),
        ]);

        if ($this->workspaceId() <= 0) {
            return back()->withErrors(['phone' => __('No active workspace — switch to one first.')]);
        }

        $result = $this->bridge->sendCode($data['phone']);

        if (! ($result['ok'] ?? false)) {
            return back()->withErrors(['phone' => (string) ($result['error'] ?? __('Could not start the login.'))])
                ->withInput();
        }

        Session::put(self::LOGIN_KEY, (string) $result['loginId']);
        // Kept out of the session: only used to word the next screen.
        Session::put('telegram.account.phone', $data['phone']);
        Session::put('telegram.account.sent_to', (string) ($result['sentTo'] ?? 'unknown'));

        Log::info('[TG-ACCOUNT] login code requested', ['workspace' => $this->workspaceId()]);

        return back()->with('success', ($result['sentTo'] ?? '') === 'telegram_app'
            ? __('Telegram sent a code to your Telegram app — check your other devices.')
            : __('Telegram sent you a login code.'));
    }

    /**
     * Step 2 — submit the code, and the 2FA password if asked for.
     *
     * Neither value is stored. On a `needPassword` reply the operator is shown the
     * password field and re-enters the code with it, because the Node side needs
     * both in one call and holding the code server-side would mean storing a
     * live credential to save one keystroke.
     */
    public function signIn(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'     => ['required', 'string', 'max:16'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        $loginId = (string) Session::get(self::LOGIN_KEY, '');
        if ($loginId === '') {
            return back()->withErrors(['code' => __('That login expired. Start again.')]);
        }

        $result = $this->bridge->signIn($loginId, $data['code'], (string) ($data['password'] ?? ''));

        if (! ($result['ok'] ?? false)) {
            // Not a failure — the code was right and a second factor is needed.
            if ($result['needPassword'] ?? false) {
                Session::put('telegram.account.need_password', true);

                return back()->withErrors(['password' => (string) ($result['error'] ?? __('Enter your Telegram password.'))]);
            }

            return back()->withErrors(['code' => (string) ($result['error'] ?? __('Could not sign in.'))]);
        }

        $user = (array) ($result['user'] ?? []);

        $account = TelegramAccount::updateOrCreate(
            ['workspace_id' => $this->workspaceId()],
            [
                'connected_by' => Auth::id(),
                'session'      => (string) ($result['session'] ?? ''),
                'username'     => (string) ($user['username'] ?? ''),
                'first_name'   => (string) ($user['firstName'] ?? ''),
                'phone'        => (string) ($user['phone'] ?? Session::get('telegram.account.phone', '')),
                'last_error'   => null,
                'connected_at' => now(),
            ]
        );

        Session::forget([self::LOGIN_KEY, 'telegram.account.phone', 'telegram.account.need_password', 'telegram.account.sent_to']);

        // Logs the workspace and the handle, never the session.
        Log::info('[TG-ACCOUNT] connected', [
            'workspace' => $this->workspaceId(), 'account' => $account->label(),
        ]);

        return back()->with('success', __('Telegram account connected — :who', ['who' => $account->label()]));
    }

    /**
     * QR login — the scan-to-link path.
     *
     * Returns JSON rather than redirecting: the page polls this, and a redirect
     * per poll would reload the QR out from under the operator mid-scan.
     */
    public function qrStart(Request $request)
    {
        if ($this->workspaceId() <= 0) {
            return response()->json(['ok' => false, 'error' => __('No active workspace — switch to one first.')], 422);
        }

        // Reuse the in-flight login when refreshing an expired code, so the
        // token stays bound to the connection that issued it.
        $result = $this->bridge->qrStart((string) Session::get(self::LOGIN_KEY, ''));

        // THE SCAN CAN LAND HERE, not only on a poll. The code refreshes every
        // ~30s, so a confirmation that arrives between two polls comes back on
        // this call instead — carrying the session. An earlier version stored
        // only the loginId and dropped it, so scanning at the wrong moment
        // signed you in at Telegram and saved nothing here: the page reloaded to
        // "not connected" with no error anywhere.
        if (($result['status'] ?? '') === 'signed_in') {
            return $this->storeAccount($result);
        }

        if ($result['ok'] ?? false) {
            Session::put(self::LOGIN_KEY, (string) ($result['loginId'] ?? ''));
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Poll: scanned yet? On success the account is stored exactly as the phone
     * path stores it, so everything downstream is identical.
     */
    public function qrPoll(Request $request)
    {
        $loginId = (string) Session::get(self::LOGIN_KEY, '');
        if ($loginId === '') {
            return response()->json(['ok' => false, 'error' => __('That login expired. Start again.')], 422);
        }

        $result = $this->bridge->qrPoll($loginId, (string) $request->input('password', ''));

        // Still waiting for the scan — not an error, just not done.
        if (($result['status'] ?? '') === 'waiting') {
            return response()->json(['ok' => true, 'status' => 'waiting']);
        }

        if (! ($result['ok'] ?? false)) {
            return response()->json($result, 422);
        }

        return $this->storeAccount($result);
    }

    /**
     * Persist a completed QR sign-in.
     *
     * Shared by qrStart and qrPoll because EITHER can be the call that receives
     * the confirmation — which one depends purely on whether the operator tapped
     * "Confirm" during a poll tick or a code refresh. Having only one of them
     * save was the bug that made a successful scan produce no account.
     */
    private function storeAccount(array $result)
    {
        $user = (array) ($result['user'] ?? []);

        if (trim((string) ($result['session'] ?? '')) === '') {
            return response()->json(['ok' => false, 'error' => __('Telegram returned no session. Try again.')], 422);
        }

        // Keyed on the PERSON, not the workspace. Keyed on the workspace this
        // would overwrite whoever connected first the moment a second person
        // scanned — several accounts would be impossible and the loss silent.
        // Re-scanning as the same person still replaces that one dead session,
        // which is what the workspace key was there for.
        $account = TelegramAccount::updateOrCreate(
            ['workspace_id' => $this->workspaceId(), 'tg_user_id' => (string) ($user['id'] ?? '')],
            [
                'connected_by' => Auth::id(),
                'session'      => (string) $result['session'],
                'tg_user_id'   => (string) ($user['id'] ?? ''),
                'username'     => (string) ($user['username'] ?? ''),
                'first_name'   => (string) ($user['firstName'] ?? ''),
                'phone'        => (string) ($user['phone'] ?? ''),
                'last_error'   => null,
                'connected_at' => now(),
            ]
        );

        Session::forget([self::LOGIN_KEY, 'telegram.account.phone', 'telegram.account.need_password', 'telegram.account.sent_to']);

        Log::info('[TG-ACCOUNT] connected by QR', [
            'workspace' => $this->workspaceId(), 'account' => $account->label(),
        ]);

        return response()->json(['ok' => true, 'status' => 'signed_in', 'label' => $account->label()]);
    }

    /** Abandon a half-finished login. */
    public function cancel(): RedirectResponse
    {
        Session::forget([self::LOGIN_KEY, 'telegram.account.phone', 'telegram.account.need_password', 'telegram.account.sent_to']);

        return back()->with('success', __('Login cancelled.'));
    }

    /** Confirm the stored session still works. */
    public function check(): RedirectResponse
    {
        $account = $this->account();
        if (! $account) {
            return back()->withErrors(['account' => __('No Telegram account is connected.')]);
        }

        $result = $this->bridge->status($account);

        return ($result['ok'] ?? false)
            ? back()->with('success', __('Session is live — :who', ['who' => $account->fresh()->label()]))
            : back()->withErrors(['account' => (string) ($result['error'] ?? __('The session is no longer valid.'))]);
    }

    /** Log out at Telegram and forget the session here. */
    public function disconnect(Request $request, $account = null): RedirectResponse
    {
        // Scoped by workspace: an id from the URL or a form must never reach
        // another tenant's session, the most sensitive row in the schema.
        $account = TelegramAccount::scopedFind(
            $this->workspaceId(),
            $account ?? $request->input('account_id')
        );
        if (! $account) {
            return back()->withErrors(['account' => __('No Telegram account is connected.')]);
        }

        $who    = $account->label();
        $result = $this->bridge->logOut($account);

        Log::info('[TG-ACCOUNT] disconnected', ['workspace' => $this->workspaceId()]);

        // The row is gone either way — logOut() clears it even when Telegram
        // refuses, so the operator is told to check their phone rather than being
        // left believing a session we can no longer reach is still revocable.
        return ($result['ok'] ?? false)
            ? back()->with('success', __('Logged out of :who.', ['who' => $who]))
            : back()->with('success', __('Disconnected :who here, but Telegram refused the logout — also remove it under Telegram → Settings → Devices.', ['who' => $who]));
    }

    /**
     * Create a bot through @BotFather, then adopt it like a pasted token.
     *
     * The token is deliberately NOT shown to the operator. It goes straight into
     * the bots table; putting it on screen would be one more place a full bot
     * credential exists for no benefit — the app already has it.
     */
    public function createBot(Request $request, TelegramConnectController $admin): RedirectResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:64'],
            // Telegram's own rules, checked here so a rejection is worded by us
            // rather than scraped out of @BotFather's prose.
            'username' => ['required', 'string', 'min:5', 'max:32', 'regex:/^[A-Za-z0-9_]+$/', 'regex:/bot$/i'],
        ], [
            'username.regex' => __('The username may use letters, numbers and underscores only, and must end in "bot" — e.g. my_orders_bot.'),
        ]);

        // Which account drives @BotFather is named on the form, so the bot's
        // owner is a choice rather than whichever row sorted first.
        $account = TelegramAccount::scopedFind($this->workspaceId(), $request->input('account_id'));
        if (! $account || ! $account->hasSession()) {
            return back()->withErrors(['name' => __('Connect a Telegram account first.')]);
        }

        $result = $this->bridge->createBot($account, $data['name'], $data['username']);

        if (! ($result['ok'] ?? false)) {
            $field = ($result['retryUsername'] ?? false) ? 'username' : 'name';

            return back()->withErrors([$field => (string) ($result['error'] ?? __('@BotFather refused.'))])
                ->withInput();
        }

        // Same path a pasted token takes: getMe proves it, setWebhook makes it
        // able to receive. A bot created but not registered would look connected
        // and be deaf.
        $adopted = $admin->adoptToken((string) $result['token']);

        if (! $adopted['ok']) {
            // The bot DOES exist on Telegram now — /newbot succeeded. Saying so
            // matters: the operator must not create a second one with a different
            // username thinking the first failed.
            return back()->withErrors(['name' => __('@:handle was created on Telegram, but connecting it here failed: :why Use "Connect a bot" and paste its token from @BotFather.', [
                'handle' => (string) $result['username'],
                'why'    => $adopted['error'],
            ])]);
        }

        Log::info('[TG-ACCOUNT] bot created via BotFather', [
            'workspace' => $this->workspaceId(), 'bot' => (string) $result['username'],
        ]);

        return back()->with('success', __('Created and connected @:handle.', ['handle' => (string) $result['username']]));
    }

    /** Is a bot username free? Answered by Telegram, not guessed. */
    public function checkUsername(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:32'],
        ]);

        $account = $this->account();
        if (! $account || ! $account->hasSession()) {
            return response()->json(['ok' => false, 'error' => __('Connect a Telegram account first.')], 422);
        }

        $result = $this->bridge->checkUsername($account, $data['username']);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }
}
