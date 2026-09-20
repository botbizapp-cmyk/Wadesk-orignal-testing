<?php

namespace App\Services\Telegram;

use App\Models\SystemSetting;
use App\Models\TelegramAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Laravel's side of the MTProto account bridge. The protocol work lives in Node
 * (node/services/telegramAccountService.js) because MTProto needs a long-lived
 * socket a PHP request cannot hold. Only used to log in a Telegram USER account
 * and drive @BotFather (Telegram has no API to create a bot).
 *
 * NOTHING is logged from a request body — these carry login codes, 2FA
 * passwords and session strings. X-Node-Token is attached explicitly.
 */
class TelegramAccountBridge
{
    private const TIMEOUT = 45;

    public function sendCode(string $phone): array
    {
        return $this->call('send-code', ['phone' => $phone]);
    }

    public function signIn(string $loginId, string $code, string $password = ''): array
    {
        return $this->call('sign-in', ['loginId' => $loginId, 'code' => $code, 'password' => $password]);
    }

    public function qrStart(string $loginId = ''): array
    {
        return $this->call('qr-start', ['loginId' => $loginId]);
    }

    public function qrPoll(string $loginId, string $password = ''): array
    {
        return $this->call('qr-poll', ['loginId' => $loginId, 'password' => $password]);
    }

    public function status(TelegramAccount $account): array
    {
        $result = $this->call('status', ['accountId' => $account->id, 'session' => (string) $account->session]);
        $account->forceFill([
            'last_checked_at' => now(),
            'last_error'      => ($result['ok'] ?? false) ? null : mb_substr((string) ($result['error'] ?? ''), 0, 255),
        ])->saveQuietly();

        return $result;
    }

    public function logOut(TelegramAccount $account): array
    {
        $result = $this->call('log-out', ['accountId' => $account->id, 'session' => (string) $account->session]);
        $account->delete();

        return $result;
    }

    public function createBot(TelegramAccount $account, string $name, string $username): array
    {
        return $this->call('create-bot', [
            'accountId' => $account->id, 'session' => (string) $account->session,
            'name' => $name, 'username' => $username,
        ], 90);
    }

    public function checkUsername(TelegramAccount $account, string $username): array
    {
        return $this->call('check-username', [
            'accountId' => $account->id, 'session' => (string) $account->session, 'username' => $username,
        ]);
    }

    /**
     * "Service URL not configured" message, role-aware so we never leak an
     * internal admin path (or the product name) to an end user. Operators who
     * can actually fix it (admins) still get the actionable location, worded
     * brand-neutrally ("Channel Settings" — the current page name); everyone
     * else on the workspace portal gets a clean, support-only message.
     */
    private function serviceUnavailableError(): string
    {
        $user = auth()->user();
        if ($user && $user->isAdmin()) {
            return __('The messaging service URL is not configured — set it under Admin → Channel Settings.');
        }
        return __('Telegram setup is currently unavailable. Please contact support for assistance.');
    }

    /** POST to the Node bridge, turning every failure into a readable array. */
    private function call(string $path, array $payload, ?int $timeout = null): array
    {
        $base = rtrim((string) (SystemSetting::get('baileys_server_url', '') ?: env('SERVER_URL', '')), '/');
        if ($base === '') {
            return ['ok' => false, 'error' => $this->serviceUnavailableError()];
        }

        // MTProto api_id/hash come from Admin → Channel Settings (falling back to
        // the Node process env). Passed per request so the admin never edits node/.env,
        // and so a blank config surfaces as a readable message instead of a crash.
        $apiId   = trim((string) (SystemSetting::get('telegram_api_id', '') ?: env('TELEGRAM_API_ID', '')));
        $apiHash = trim((string) (SystemSetting::get('telegram_api_hash', '') ?: env('TELEGRAM_API_HASH', '')));
        if ($apiId !== '') {
            $payload['apiId'] = $apiId;
        }
        if ($apiHash !== '') {
            $payload['apiHash'] = $apiHash;
        }

        try {
            $res = Http::withHeaders(['X-Node-Token' => node_token()])
                ->timeout($timeout ?? self::TIMEOUT)->acceptJson()
                ->post($base.'/api/telegram-account/'.$path, $payload);
            $body = $res->json();
            if (! is_array($body)) {
                Log::warning('[TG-ACCOUNT] non-JSON from Node', ['path' => $path, 'status' => $res->status()]);

                return ['ok' => false, 'error' => __('The Node service returned an unreadable response.')];
            }
            if ($res->status() === 401) {
                return ['ok' => false, 'error' => __('The Node service rejected our token — check NODE_WEBHOOK_TOKEN matches on both sides.')];
            }

            return $body;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['ok' => false, 'error' => __('Could not reach the Node service at :url — is it running?', ['url' => $base])];
        } catch (\Throwable $e) {
            Log::error('[TG-ACCOUNT] bridge call failed', ['path' => $path]);

            return ['ok' => false, 'error' => __('The Telegram account request failed.')];
        }
    }
}
