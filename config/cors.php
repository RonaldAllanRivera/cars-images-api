<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | Comma-separated in CORS_ALLOWED_ORIGINS. Empty when CORS_ALLOWED_ORIGINS is unset: the mobile
    | app's web build runs on its own origin (Netlify), and that is the only
    | browser that should be talking to api/*. The native build sends no
    | Origin header and is unaffected.
    */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Authorization makes every request non-simple, so each one preflights;
    // caching the answer for an hour halves the web build's requests.
    'max_age' => 3600,

    'supports_credentials' => false,

];
