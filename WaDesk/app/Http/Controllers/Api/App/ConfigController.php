<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile-app bootstrap config. The app calls this once after login to learn how
 * to connect real-time + whether push is available — so nobody has to hand the
 * app dev the Pusher key/cluster manually. Exposes only PUBLIC values (the
 * Pusher app KEY is public by design; the SECRET is never sent).
 */
class ConfigController extends Controller
{
    /** GET /api/app/config */
    public function show(Request $request): JsonResponse
    {
        $driver = (string) config('broadcasting.default', 'log');           // 'pusher' | 'reverb' | 'log'
        $conn   = (array)  config("broadcasting.connections.{$driver}", []);
        $opts   = (array)  ($conn['options'] ?? []);
        $realtimeOn = in_array($driver, ['pusher', 'reverb'], true) && !empty($conn['key']);

        \Illuminate\Support\Facades\Log::info('[RT-TRACE] /api/app/config requested', [
            'user_id'          => optional($request->user())->id,
            'broadcast_driver' => $driver,
            'realtime_enabled' => $realtimeOn,
            'has_key'          => !empty($conn['key']),
            'cluster'          => (string) ($opts['cluster'] ?? ''),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                // How the Flutter pusher_channels_flutter client should connect.
                'realtime' => [
                    'enabled'       => $realtimeOn,
                    'driver'        => $realtimeOn ? $driver : null,
                    'key'           => $realtimeOn ? (string) ($conn['key'] ?? '') : null, // PUBLIC app key
                    'cluster'       => (string) ($opts['cluster'] ?? ''),
                    'host'          => (string) ($opts['host'] ?? ''),
                    'port'          => (int) ($opts['port'] ?? 443),
                    'scheme'        => (string) ($opts['scheme'] ?? 'https'),
                    'auth_endpoint' => url('/api/app/broadcasting/auth'),
                ],
                // Whether inbound FCM push is configured (admin pasted the JSON).
                'push' => [
                    'fcm_enabled' => app(\App\Services\Push\FcmService::class)->enabled(),
                ],
                // The channel + event contract, so the client never hardcodes it.
                'channels' => [
                    'workspace_inbox' => 'private-workspace.{workspaceId}.inbox',
                    'conversation'    => 'private-conversation.{conversationId}',
                    'event'           => 'message.received',
                ],
            ],
        ]);
    }
}
