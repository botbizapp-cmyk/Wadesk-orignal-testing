<?php

namespace App\Services\WeChat;

/**
 * WeChat webhook crypto + XML helpers.
 *
 * Signature (VERIFIED against the official access guide): sort [token, timestamp,
 * nonce] alphabetically, concatenate, SHA1, compare to the `signature` query
 * param. The URL-validation GET returns `echostr` on a match. AES "safe mode"
 * (EncodingAESKey + msg_signature + <Encrypt>) is Phase 2 — Phase 1 runs the
 * Plaintext / Compatible modes (no body encryption).
 */
class WeChatCrypto
{
    /** True when sha1(sort(token,timestamp,nonce)) equals the given signature. */
    public static function signatureMatches(string $token, string $signature, string $timestamp, string $nonce): bool
    {
        if ($token === '' || $signature === '') {
            return false;
        }
        $arr = [$token, $timestamp, $nonce];
        sort($arr, SORT_STRING);

        return hash_equals(sha1(implode('', $arr)), $signature);
    }

    /**
     * URL-validation handshake: returns the raw echostr to echo back when the
     * signature is valid, or '' to reject (401). Called on the GET request WeChat
     * fires when the tenant saves the server URL.
     */
    public static function verifyUrl(string $token, string $signature, string $timestamp, string $nonce, string $echostr): string
    {
        return self::signatureMatches($token, $signature, $timestamp, $nonce) ? $echostr : '';
    }

    /**
     * Parse a WeChat inbound XML packet into a flat array (CDATA unwrapped).
     * XXE-safe: no network, no external entities (off by default on PHP 8).
     * Returns [] on any parse failure (never throws).
     */
    public static function xmlToArray(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        try {
            $xml = @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            if ($xml === false) {
                return [];
            }
            $arr = json_decode(json_encode($xml), true);

            return is_array($arr) ? $arr : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Safe-mode signature: sha1(sort(token, timestamp, nonce, Encrypt)). WeChat
     * puts this in the `msg_signature` query param when message encryption is on.
     */
    public static function msgSignatureMatches(string $token, string $msgSignature, string $timestamp, string $nonce, string $encrypt): bool
    {
        if ($token === '' || $msgSignature === '') {
            return false;
        }
        $arr = [$token, $timestamp, $nonce, $encrypt];
        sort($arr, SORT_STRING);

        return hash_equals(sha1(implode('', $arr)), $msgSignature);
    }

    /**
     * Decrypt a safe-mode <Encrypt> body (AES-256-CBC, EncodingAESKey). Returns
     * the inner plaintext XML, or null on any failure. The decrypted block is
     * [16 random bytes][4-byte big-endian msg length][msg][appId]; we verify the
     * trailing appId matches when one is provided. Never throws.
     */
    public static function decryptSafeMode(string $encodingAesKey, string $encrypt, string $expectAppId = ''): ?string
    {
        try {
            if (strlen($encodingAesKey) !== 43) {
                return null;
            }
            $aesKey = base64_decode($encodingAesKey.'=', true);   // 32 bytes
            if ($aesKey === false || strlen($aesKey) !== 32) {
                return null;
            }
            $iv     = substr($aesKey, 0, 16);
            $cipher = base64_decode($encrypt, true);
            if ($cipher === false) {
                return null;
            }
            $plain = openssl_decrypt($cipher, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
            if ($plain === false || $plain === '') {
                return null;
            }
            // Strip PKCS7 padding (1..32).
            $pad = ord(substr($plain, -1));
            if ($pad < 1 || $pad > 32) {
                return null;
            }
            $plain = substr($plain, 0, -$pad);

            $content = substr($plain, 16);                        // drop 16 random bytes
            $lenBytes = substr($content, 0, 4);
            if (strlen($lenBytes) < 4) {
                return null;
            }
            $msgLen = unpack('N', $lenBytes)[1] ?? 0;
            $xml    = substr($content, 4, $msgLen);
            $appId  = substr($content, 4 + $msgLen);

            if ($expectAppId !== '' && $appId !== '' && ! hash_equals($expectAppId, $appId)) {
                return null;
            }

            return $xml !== '' ? $xml : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Build a passive text-reply XML (the ≤5s webhook-response path). Optional
     * fast-path for instant canned replies; the default reply path is the async
     * customer-service send. $to = the user's OpenID, $from = the OA's wx_id.
     */
    public static function textReplyXml(string $to, string $from, string $content): string
    {
        $c = htmlspecialchars($content, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<xml>'
            .'<ToUserName><![CDATA['.$to.']]></ToUserName>'
            .'<FromUserName><![CDATA['.$from.']]></FromUserName>'
            .'<CreateTime>'.time().'</CreateTime>'
            .'<MsgType><![CDATA[text]]></MsgType>'
            .'<Content><![CDATA['.$c.']]></Content>'
            .'</xml>';
    }
}
