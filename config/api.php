<?php

return [

    'rate_limits' => [
        'api' => (int) env('RATE_LIMIT_API', 60),
    ],

    'max_payload_kb' => (int) env('API_MAX_PAYLOAD_KB', 64),

    'docs_enabled' => (bool) env('API_DOCS_ENABLED', false),

    /*
    | Vida en minutos de los tokens que emite `php artisan api:token`.
    */
    'token_ttl_minutes' => (int) env('API_TOKEN_TTL_MINUTES', 480),

    /*
    | Secretos de los tokens demo que crea UserSeeder (nunca en producción).
    | El valor que envía el cliente es "{id del token}|{secreto}"; ids 1 y 2 en una base recién sembrada.
    */
    'demo_tokens' => [
        'admin' => env('DEMO_ADMIN_TOKEN'),
        'viewer' => env('DEMO_VIEWER_TOKEN'),
    ],

];
