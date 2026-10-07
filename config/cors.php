<?php

return [

    'paths' => ['api/*', 'broadcasting/auth'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'https://sonetcord.ru,https://www.sonetcord.ru,http://localhost:3000')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Upload-Offset', 'Range'],

    'exposed_headers' => ['Content-Length', 'Content-Range', 'Accept-Ranges'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
