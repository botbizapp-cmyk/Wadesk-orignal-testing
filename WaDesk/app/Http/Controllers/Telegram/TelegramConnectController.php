<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Models\TelegramBot;
use App\Services\PlanLimitGuard;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Telegram bot connect + manage. A workspace pastes a @BotFather token; we
 * validate it (getMe), store it encrypted, and register the per-bot webhook.
 * Telegram is Bot API (plain HTTPS) — no OAuth/review. Mirrors the FB/TikTok
 * connect controllers in shape.
 */
class TelegramConnectController extends Controller
{
    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    private function planOk(): bool
    {
        $ws = Auth::user()?->currentWorkspace;

        return $ws ? (bool) PlanLimitGuard::hasFeature($ws, 'access_telegram') : false;
    }

    /** Manage page — connected bots + connect form + (advanced) MTProto account. */
    public function index(): View
    {
        $wsId = $this->wsId();
        $bots = TelegramBot::allForWorkspace($wsId);
        $accounts = \App\Models\TelegramAccount::allForWorkspace($wsId);

        return view('user.telegram.index', compact('bots', 'accounts'));
    }

    /** Connect a bot from a pasted @BotFather token. */
    public function connect(Request $request): RedirectResponse
    {
        if (! $this->planOk()) {
            return back()->withErrors(['bot_token' => __('Your plan does not include Telegram.')]);
        }
        $data = $request->validate([
            'bot_token' => ['required', 'string', 'regex:/^\d{6,}:[A-Za-z0-9_-]{30,}$/'],
        ], [
            'bot_token.regex' => __('That does not look like a BotFather token. It should read like 123456789:AAH… — copy the whole line.'),
        ]);

        $res = $this->adoptToken(trim($data['bot_token']));
        if (! $res['ok']) {
            return back()->withErrors(['bot_token' => $res['error']]);
        }

        return back()->with('status', __('Telegram connected — @').$res['bot']->bot_username);
    }

    /**
     * Validate a token, store the bot, register its webhook. Re-pasting a token
     * updates the SAME bot (matched on Telegram's own bot id, reusing its
     * routing token). Returns ['ok'=>bool, 'bot'?=>TelegramBot, 'error'?=>string].
     */
    public function adoptToken(string $token): array
    {
        $client = new TelegramClient($token);
        $me = $client->getMe();
        if (empty($me['ok'])) {
            return ['ok' => false, 'error' => __('Telegram rejected that token: ').($me['error'] ?? '')];
        }

        $wsId = $this->wsId();
        if ($wsId <= 0) {
            return ['ok' => false, 'error' => __('No active workspace — switch to one first.')];
        }

        $botId = (string) data_get($me, 'result.id', '');
        $bot = TelegramBot::where('workspace_id', $wsId)->where('bot_id', $botId)->first()
            ?? new TelegramBot(['workspace_id' => $wsId]);

        [$webhookToken, $secretToken] = TelegramBot::freshTokens();
        $bot->fill([
            'connected_by'  => Auth::id(),
            'bot_token'     => $token,
            'bot_id'        => $botId,
            'bot_username'  => (string) data_get($me, 'result.username', ''),
            'bot_name'      => (string) data_get($me, 'result.first_name', ''),
            'webhook_token' => $bot->webhook_token ?: $webhookToken,
            'secret_token'  => $secretToken,
            'active'        => true,
            'connected_at'  => now(),
            'last_error'    => null,
        ]);
        $bot->save();

        // Register AFTER the row exists — the URL carries the routing token.
        $hook = $client->setWebhook($bot->webhookUrl(), $secretToken);
        if (empty($hook['ok'])) {
            $bot->forceFill(['last_error' => mb_substr((string) ($hook['error'] ?? ''), 0, 255)])->save();

            return ['ok' => false, 'error' => __('Saved the bot, but Telegram refused the webhook: ')
                .($hook['error'] ?? '')
                .__(' — the URL must be public HTTPS. On a local machine, use a tunnel (ngrok / Cloudflare Tunnel).')];
        }

        Log::info('[TELEGRAM] connected', ['workspace' => $wsId, 'bot' => $bot->bot_username]);

        return ['ok' => true, 'bot' => $bot];
    }

    /** Re-register the webhook for a bot ("Fix inbound"). */
    public function retry(int $bot): RedirectResponse
    {
        $b = TelegramBot::where('workspace_id', $this->wsId())->whereKey($bot)->first();
        if (! $b) {
            return back()->withErrors(['telegram' => __('Bot not found.')]);
        }
        $hook = (new TelegramClient((string) $b->bot_token))->setWebhook($b->webhookUrl(), (string) $b->secret_token);
        if (! empty($hook['ok'])) {
            $b->forceFill(['active' => true, 'last_error' => null])->save();

            return back()->with('status', __('Telegram webhook re-registered.'));
        }
        $b->forceFill(['last_error' => mb_substr((string) ($hook['error'] ?? ''), 0, 255)])->save();

        return back()->withErrors(['telegram' => __('Re-register failed: ').($hook['error'] ?? '')]);
    }

    /**
     * Save the bot's payment provider token (from @BotFather → /mybots →
     * Payments). Enables the Payment flow node. Stored encrypted; only
     * overwritten when a new value is submitted (blank keeps the existing one).
     */
    public function savePayments(int $bot, Request $request): RedirectResponse
    {
        $b = TelegramBot::where('workspace_id', $this->wsId())->whereKey($bot)->first();
        if (! $b) {
            return back()->withErrors(['telegram' => __('Bot not found.')]);
        }
        $data = $request->validate([
            'payment_provider_token' => ['nullable', 'string', 'max:191'],
            'payment_provider'       => ['nullable', 'string', 'max:32'],
        ]);
        $fill = ['payment_provider' => trim((string) ($data['payment_provider'] ?? '')) ?: null];
        if ($request->filled('payment_provider_token')) {
            $fill['payment_provider_token'] = trim((string) $data['payment_provider_token']);
        } elseif ($request->boolean('clear_payment_token')) {
            $fill['payment_provider_token'] = null;
        }
        $b->forceFill($fill)->save();

        return back()->with('status', __('Telegram payment settings saved.'));
    }

    /** Pause / resume delivery for a bot. */
    public function toggle(int $bot): RedirectResponse
    {
        $b = TelegramBot::where('workspace_id', $this->wsId())->whereKey($bot)->first();
        if ($b) {
            $b->forceFill(['active' => ! $b->active])->save();
        }

        return back()->with('status', __('Updated.'));
    }

    /** Disconnect: delete the Telegram webhook, then remove the bot. */
    public function destroy(int $bot): RedirectResponse
    {
        $b = TelegramBot::where('workspace_id', $this->wsId())->whereKey($bot)->first();
        if (! $b) {
            return back()->withErrors(['telegram' => __('Bot not found.')]);
        }
        try { (new TelegramClient((string) $b->bot_token))->deleteWebhook(); } catch (\Throwable $e) {}
        $b->delete();

        return back()->with('status', __('Telegram bot disconnected.'));
    }
}
