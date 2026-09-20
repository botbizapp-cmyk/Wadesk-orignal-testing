<?php

namespace App\Support;

/**
 * Envato licence material — item id and author token.
 *
 * WHY THIS IS NOT A CONFIG VALUE
 * ------------------------------
 * The token used to be resolved inside `config/license.php`, which meant it was
 * decrypted at config-load time and sat in `config('version.envato.token')`.
 * That is fine until someone runs `php artisan config:cache` — the standard
 * production step — because Laravel var_export()s the fully-resolved config
 * array to `bootstrap/cache/config.php`. The DECRYPTED token would be written
 * to disk in plaintext, defeating the encryption entirely.
 *
 * So the token is resolved HERE, on demand, at the moment of verification only.
 * It never enters the config array and therefore can never be baked into a
 * cached config file. Only `item_id` stays in config — that is public.
 *
 * HONEST LIMIT: the decrypt key ships alongside the ciphertext, so this is
 * obfuscation, not secrecy. Anyone reading the source can still recover the
 * token. The only real fix is to stop shipping it and verify through a licence
 * endpoint you host.
 */
final class Licence
{
    /** Must match the passphrase the ciphertext in config/license.json was made with. */
    private const PASSPHRASE = 'wd::lic::v1::9f3a7c21e8b44d06';

    private const FALLBACK_ITEM_ID = '63755235';

    /** Decrypted author token, or '' when unavailable/tampered. Never cached to disk. */
    public static function token(): string
    {
        return self::read()['token'];
    }

    /** CodeCanyon item id. Not secret — also exposed via config('version.envato.item_id'). */
    public static function itemId(): string
    {
        return self::read()['item_id'];
    }

    /** True when a usable token is present. */
    public static function configured(): bool
    {
        return self::token() !== '';
    }

    /**
     * Read + verify + decrypt config/license.json.
     *
     * Memoised per-process only (a static), so it costs one decrypt per request
     * at most and is never persisted anywhere.
     *
     * @return array{item_id:string, token:string}
     */
    private static function read(): array
    {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }

        $blank = ['item_id' => self::FALLBACK_ITEM_ID, 'token' => ''];

        $file = base_path('config/license.json');
        if (! is_file($file)) {
            return $memo = $blank;
        }

        $json = json_decode((string) file_get_contents($file), true);
        if (! is_array($json)) {
            return $memo = $blank;
        }

        $itemId = (string) ($json['item_id'] ?? self::FALLBACK_ITEM_ID);
        $data   = base64_decode((string) ($json['data'] ?? ''), true);
        $mac    = base64_decode((string) ($json['mac'] ?? ''), true);

        if ($data === false || $mac === false || strlen($data) <= 16) {
            return $memo = ['item_id' => $itemId, 'token' => ''];
        }

        $key = hash('sha256', self::PASSPHRASE, true);
        $iv  = substr($data, 0, 16);
        $ct  = substr($data, 16);

        // Verify BEFORE decrypting. The MAC is bound to the item id, so a blob
        // lifted onto another product fails closed rather than half-working.
        $expect = hash_hmac('sha256', $iv . $ct . $itemId, $key, true);
        if (! hash_equals($expect, $mac)) {
            return $memo = ['item_id' => $itemId, 'token' => ''];
        }

        $token = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $memo = [
            'item_id' => $itemId,
            'token'   => $token === false ? '' : $token,
        ];
    }
}
