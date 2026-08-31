<?php

return [
    'wikimedia' => [
        'base_url' => env('WIKIMEDIA_BASE_URL', 'https://commons.wikimedia.org/w/api.php'),
        'timeout' => env('WIKIMEDIA_TIMEOUT', 10),
        'retry_times' => env('WIKIMEDIA_RETRY_TIMES', 3),
        'retry_sleep_ms' => env('WIKIMEDIA_RETRY_SLEEP_MS', 200),
        'user_agent' => env('WIKIMEDIA_USER_AGENT', 'CarsImagesApi/1.0 (Laravel)'),
        'cache_ttl' => env('WIKIMEDIA_CACHE_TTL', 3600),
        'maxlag' => env('WIKIMEDIA_MAXLAG', 5),

        // A resolved category never stops existing, so hits are cached
        // forever. Misses expire, because Commons categories are created over
        // time and a model without one today may have one later.
        'category_miss_ttl_days' => env('WIKIMEDIA_CATEGORY_MISS_TTL_DAYS', 30),

        // Guards a synchronous request against a pathologically large tree.
        'category_max_files' => env('WIKIMEDIA_CATEGORY_MAX_FILES', 500),
        'category_page_size' => env('WIKIMEDIA_CATEGORY_PAGE_SIZE', 200),
    ],
];
