<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A self-contained Pusher broadcaster that needs NO pusher/pusher-php-server
 * package.
 *
 * WHY: the WaDesk updater copies code but never runs `composer install`, so
 * self-hosted clients never get the Pusher SDK — every page then 500s with
 * "Class Pusher\Pusher not found" once real-time is switched on. Pusher's server
 * side is just a signed HTTP call, so we reimplement exactly the two things
 * Laravel needs — triggering events and authorizing private/presence channels —
 * using the framework's own HTTP client + hash_hmac. Nothing to install, works
 * on any shared host.
 *
 * The client side (Laravel Echo + pusher-js, loaded via the JS build) is
 * unchanged — it connects straight to Pusher's socket with the app key.
 */
class PusherHttpBroadcaster extends Broadcaster
{
    // Provides normalizeChannelName() + isGuardedChannel() — used by auth()
    // and validAuthenticationResponse(). The base Broadcaster does NOT define
    // these (only Laravel's own PusherBroadcaster pulls them in via this trait),
    // so without it every /broadcasting/auth call fatals with
    // "Call to undefined method ...::normalizeChannelName()".
    use UsePusherChannelConventions;

    private string $key;
    private string $secret;
    private string $appId;
    private string $host;
    private string $scheme;
    private int $port;

    public function __construct(array $config)
    {
        $opts = (array) ($config['options'] ?? []);
        $cluster = (string) ($opts['cluster'] ?? 'mt1');

        $this->key    = (string) ($config['key'] ?? '');
        $this->secret = (string) ($config['secret'] ?? '');
        $this->appId  = (string) ($config['app_id'] ?? '');
        $this->host   = (string) ($opts['host'] ?? ('api-' . $cluster . '.pusher.com'));
        $this->scheme = (string) ($opts['scheme'] ?? 'https');
        $this->port   = (int) ($opts['port'] ?? ($this->scheme === 'https' ? 443 : 80));
    }

    /** Authorize an incoming /broadcasting/auth request against the channel callbacks. */
    public function auth($request)
    {
        Log::info('[RT-TRACE] auth request', [
            'channel'   => $request->channel_name,
            'socket_id' => $request->socket_id,
            'user_id'   => optional($request->user())->id,
            'guarded'   => $this->isGuardedChannel($request->channel_name),
        ]);
        $channelName = $this->normalizeChannelName($request->channel_name);

        if (empty($request->channel_name) ||
            ($this->isGuardedChannel($request->channel_name) &&
             ! $this->retrieveUser($request, $channelName))) {
            throw new AccessDeniedHttpException;
        }

        return parent::verifyUserCanAccessChannel($request, $channelName);
    }

    /**
     * Build the signed auth payload pusher-js expects back.
     * Private:  { auth: "key:HMAC(socket_id:channel)" }
     * Presence: { auth: "key:HMAC(socket_id:channel:channel_data)", channel_data }
     */
    public function validAuthenticationResponse($request, $result)
    {
        $socketId = (string) $request->socket_id;
        $channel  = (string) $request->channel_name;

        if (str_starts_with($channel, 'private')) {
            $sig = $this->socketSignature($socketId . ':' . $channel);
            return ['auth' => $this->key . ':' . $sig];
        }

        // Presence channel — sign socket_id:channel:channel_data.
        $channelName = $this->normalizeChannelName($channel);
        $user = $this->retrieveUser($request, $channelName);
        $channelData = json_encode([
            'user_id'   => (string) ($user?->getAuthIdentifier() ?? ''),
            'user_info' => $result,
        ]);
        $sig = $this->socketSignature($socketId . ':' . $channel . ':' . $channelData);

        return ['auth' => $this->key . ':' . $sig, 'channel_data' => $channelData];
    }

    /** Fire an event to one or more channels via Pusher's REST trigger endpoint. */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        if ($this->key === '' || $this->secret === '' || $this->appId === '') {
            Log::warning('[RT-TRACE] broadcast SKIPPED — Pusher not configured (key/secret/app_id empty)', [
                'event'      => $event,
                'has_key'    => $this->key !== '',
                'has_secret' => $this->secret !== '',
                'has_app_id' => $this->appId !== '',
            ]);
            return; // real-time not configured — silently no-op, never crash a send
        }

        Log::info('[RT-TRACE] broadcast → Pusher', [
            'event'          => $event,
            'channels'       => $this->formatChannels($channels),
            'host'           => $this->host,
            'app_id'         => $this->appId,
            'exclude_socket' => $payload['socket'] ?? null,   // set by toOthers(); null = sent to everyone
            'data'           => $payload,                      // FULL event payload delivered to the app/browser
        ]);

        $body = [
            'name'     => $event,
            'channels' => $this->formatChannels($channels),
            'data'     => json_encode($payload),
        ];
        // Exclude the originating socket if the caller passed one (toOthers()).
        if (! empty($payload['socket'])) {
            $body['socket_id'] = (string) $payload['socket'];
            unset($payload['socket']);
            $body['data'] = json_encode($payload);
        }

        $bodyJson = json_encode($body);
        $path     = '/apps/' . $this->appId . '/events';

        $params = [
            'auth_key'       => $this->key,
            'auth_timestamp' => (string) now()->timestamp,
            'auth_version'   => '1.0',
            'body_md5'       => md5($bodyJson),
        ];
        ksort($params);
        $query = http_build_query($params);

        $toSign    = 'POST' . "\n" . $path . "\n" . $query;
        $signature = hash_hmac('sha256', $toSign, $this->secret);

        $url = $this->scheme . '://' . $this->host . ':' . $this->port . $path
             . '?' . $query . '&auth_signature=' . $signature;

        try {
            $resp = Http::withHeaders(['Content-Type' => 'application/json'])
                ->timeout(8)
                ->withBody($bodyJson, 'application/json')
                ->post($url);

            if (! $resp->successful()) {
                Log::warning('[RT-TRACE] [PUSHER-HTTP] trigger FAILED', [
                    'status'    => $resp->status(),
                    'body'      => mb_substr($resp->body(), 0, 300),
                    'event'     => $event,
                    'sent_body' => mb_substr($bodyJson, 0, 800),   // exactly what we POSTed to Pusher
                    'url'       => $this->scheme . '://' . $this->host . $path,
                ]);
            } else {
                Log::info('[RT-TRACE] [PUSHER-HTTP] trigger OK', [
                    'status'    => $resp->status(),
                    'event'     => $event,
                    'resp_body' => mb_substr($resp->body(), 0, 300),   // Pusher's ack (e.g. {} or event_id)
                    'sent_body' => mb_substr($bodyJson, 0, 800),       // FULL body delivered to Pusher
                ]);
            }
        } catch (\Throwable $e) {
            // A real-time push must NEVER break the underlying action (a message
            // send, a status update). Log and move on.
            Log::warning('[PUSHER-HTTP] trigger exception', ['error' => $e->getMessage(), 'event' => $event]);
        }
    }

    private function socketSignature(string $stringToSign): string
    {
        return hash_hmac('sha256', $stringToSign, $this->secret);
    }
}
