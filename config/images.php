<?php

return [
    'wikimedia' => [
        'base_url' => env('WIKIMEDIA_BASE_URL', 'https://commons.wikimedia.org/w/api.php'),
        'timeout' => env('WIKIMEDIA_TIMEOUT', 10),
        'retry_times' => env('WIKIMEDIA_RETRY_TIMES', 3),
        'retry_sleep_ms' => env('WIKIMEDIA_RETRY_SLEEP_MS', 200),
        'user_agent' => env('WIKIMEDIA_USER_AGENT', 'CarsImagesApi/1.0 (Laravel)'),
        'cache_ttl' => env('WIKIMEDIA_CACHE_TTL', 3600),
    ],
];
