<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | The default is env-driven, but the admin "Realtime" settings page can
    | override this at runtime (AppServiceProvider::boot) — so once an admin
    | pastes their Pusher keys, the platform flips from `log` to `pusher`
    | without touching .env or clearing config cache.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [

        'pusher' => [
            // Our OWN Pusher driver (App\Broadcasting\PusherHttpBroadcaster),
            // registered in AppServiceProvider. It talks to Pusher over plain
            // signed HTTP, so it needs NO pusher/pusher-php-server package — the
            // whole reason self-hosted clients hit "Class Pusher\Pusher not
            // found". Same Pusher keys, same client-side (Echo + pusher-js);
            // only the server trigger/auth is reimplemented.
            'driver' => 'pusher-http',
            'key'    => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster'   => env('PUSHER_APP_CLUSTER', 'mt1'),
                'host'      => env('PUSHER_HOST') ?: 'api-' . env('PUSHER_APP_CLUSTER', 'mt1') . '.pusher.com',
                'port'      => env('PUSHER_PORT', 443),
                'scheme'    => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS'    => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'reverb' => [
            'driver' => 'reverb',
            'key'    => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host'   => env('REVERB_HOST'),
                'port'   => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
