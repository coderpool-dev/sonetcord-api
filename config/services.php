<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'webrtc' => [
        // Собственный TURN/STUN (coturn). Хост без схемы и порта.
        'turn_host' => env('TURN_HOST'),
        'turn_username' => env('TURN_USERNAME'),
        'turn_credential' => env('TURN_CREDENTIAL'),
        // Общий секрет coturn (use-auth-secret): с ним каждый получает временные логин и пароль,
        // а статические TURN_USERNAME/TURN_CREDENTIAL не используются.
        'turn_secret' => env('TURN_SECRET'),
        'turn_credential_ttl' => (int) env('TURN_CREDENTIAL_TTL', 86400),
        'turn_tls_host' => env('TURN_TLS_HOST'),
        'turn_tls_port' => env('TURN_TLS_PORT'),

    ],

    'yandex_music' => [
        'client_id' => env('YANDEX_MUSIC_CLIENT_ID'),
        'client_secret' => env('YANDEX_MUSIC_CLIENT_SECRET'),
    ],

    // Web Push (VAPID). Ключи: `php artisan push:vapid` → VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY в .env.
    // Без ключей пуши просто не отправляются (PushNotificationService::isConfigured).
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'https://sonetcord.ru'),
    ],

    // Голосовые каналы серверов через LiveKit (SFU). Пусто — клиенты работают по-старому, P2P.
    'livekit' => [
        // Публичный адрес сигналинга для браузера (wss://…).
        'url' => env('LIVEKIT_URL'),
        // Адрес API для бэка (обычно тот же сервер: http://127.0.0.1:7880).
        'api_host' => env('LIVEKIT_API_HOST', 'http://127.0.0.1:7880'),
        'api_key' => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),
    ],

];
