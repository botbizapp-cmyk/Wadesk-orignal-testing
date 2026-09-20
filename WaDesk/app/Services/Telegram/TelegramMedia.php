<?php

namespace App\Services\Telegram;

/**
 * Small media helpers the Telegram flow runner needs — ported from the source
 * TelegramDriver's statics. Uploading local bytes is preferred over asking
 * Telegram to fetch a URL, because a dev tunnel that answers with an
 * interstitial page makes the URL form fail in a way that names the wrong culprit.
 */
class TelegramMedia
{
    /** Our raw_jid prefix. Chat id is the last colon-segment of 'tg:<botId>:<chatId>'. */
    public const JID_PREFIX = 'tg:';

    /**
     * If a media path resolves to a file on our own disk, return [bytes, filename]
     * so it can be uploaded. Returns [null, ''] for genuinely remote media.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function readLocalMedia(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            return [null, ''];
        }
        // An absolute URL may still be OUR file — reduce to the path and let the disk decide.
        if (preg_match('#^https?://#i', $path)) {
            $path = (string) (parse_url($path, PHP_URL_PATH) ?: '');
            if ($path === '') {
                return [null, ''];
            }
        }
        $rel = ltrim(rawurldecode($path), '/');
        if (str_starts_with($rel, 'storage/')) {
            $rel = substr($rel, 8);
        }
        if (str_contains($rel, '..')) {
            return [null, ''];
        }
        try {
            $disk = media_storage();
            if ($disk->exists($rel)) {
                $bytes = $disk->get($rel);
                if (is_string($bytes) && $bytes !== '') {
                    return [$bytes, basename($rel)];
                }
            }
        } catch (\Throwable $e) {
            // fall back to the URL form
        }

        return [null, ''];
    }

    /** Absolutise a media path against the CURRENT request host (survives tunnels). */
    public static function publicMediaUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $built = media_url($path);
        try {
            $host = request()?->getSchemeAndHttpHost();
            if ($host) {
                $builtPath = parse_url($built, PHP_URL_PATH) ?: '/storage/'.ltrim($path, '/');

                return rtrim($host, '/').$builtPath;
            }
        } catch (\Throwable $e) {
            // no request context
        }

        return $built;
    }

    /** Extract the Telegram chat id from our 'tg:<botId>:<chatId>' raw_jid. */
    public static function chatIdFromJid(string $rawJid): string
    {
        // chat ids never contain a colon; the last segment is always the chat id
        // (works for both 'tg:<bot>:<chat>' and legacy 'tg:<chat>').
        $pos = strrpos($rawJid, ':');

        return $pos === false ? $rawJid : substr($rawJid, $pos + 1);
    }
}
