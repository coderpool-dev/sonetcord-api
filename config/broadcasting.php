<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    // A fresh checkout must boot before optional Reverb credentials are configured.
    'default' => env('BROADCAST_CONNECTION', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                // Исходящий broadcast PHP->Reverb идёт ВНУТРИ сервера: ходим напрямую на
                // localhost:6001, а не на публичный REVERB_HOST из окружения.
                // Иначе сервер резолвит собственный домен через внешний DNS и cURL виснет
                // (cURL error 28: Resolving timed out) -> event() бросает BroadcastException
                // -> каждый ICE-кандидат отдаёт 500. Клиентский REVERB_HOST/PORT (через
                // VITE_*) остаётся публичным и не затрагивается.
                'host' => env('REVERB_SERVER_BROADCAST_HOST', '127.0.0.1'),
                'port' => env('REVERB_SERVER_BROADCAST_PORT', env('REVERB_SERVER_PORT', 6001)),
                'scheme' => env('REVERB_SERVER_BROADCAST_SCHEME', 'http'),
                'useTLS' => env('REVERB_SERVER_BROADCAST_SCHEME', 'http') === 'https',
            ],
            'client_options' => [
                // Reverb на том же сервере отвечает за миллисекунды. Если он завис, запрос пользователя
                // ждёт не дольше 3 с, иначе зависание Reverb занимало бы воркеры php-fpm целиком.
                'connect_timeout' => 1,
                'timeout' => 3,
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
