<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | Reverb in docker-compose and in production; `null` in the test suite, so
    | that a test asserting on a broadcast asserts on the event rather than on
    | a websocket connection it would have to stand up first.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null".
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Only the connections this repository ships configured for. Pusher, Ably
    | and Redis all work unchanged — copy their blocks out of a fresh Laravel
    | install if you would rather not run a websocket server yourself.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle options for the HTTP call that publishes an event.
            ],
        ],

        // Writes each broadcast to the log instead of sending it. Useful when
        // you want to see what the kitchen display would have received.
        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
