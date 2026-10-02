<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'health'],

    'allowed_methods' => ['*'],

    // Never `*` (this API supports credentials), and never dev origins in
    // production — the allowlist must contain only real frontend domains.
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
        fn ($origin) => $origin !== ''
            && $origin !== '*'
            && ! (env('APP_ENV', 'production') === 'production'
                && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin) === 1)
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
