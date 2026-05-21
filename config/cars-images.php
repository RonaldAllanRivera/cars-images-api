<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CSV Import
    |--------------------------------------------------------------------------
    */

    'csv_import_max_combos' => env('CSV_IMPORT_MAX_COMBOS', 1000),

    'csv_import_default_images_per_year' => env('CSV_IMPORT_DEFAULT_IMAGES_PER_YEAR', 5),

    /*
    |--------------------------------------------------------------------------
    | Bulk run pacing
    |--------------------------------------------------------------------------
    */

    'bulk_run_max_queries_per_chunk' => env('CARS_BULK_RUN_MAX_QUERIES', 50),

    'bulk_run_max_seconds_per_chunk' => env('CARS_BULK_RUN_MAX_SECONDS', 50),

    'bulk_run_sleep_seconds_between_queries' => env('CARS_BULK_RUN_SLEEP_SECONDS', 1),

    /*
    |--------------------------------------------------------------------------
    | Bulk download image sizing
    |--------------------------------------------------------------------------
    |
    | The bulk-download ZIP uses each image's pre-generated Wikimedia
    | thumbnail (~1280px wide, ~85-90% smaller than the original) rather
    | than the full-resolution file. There is no width setting because
    | Wikimedia returns HTTP 400 for arbitrary-width thumbnail URLs — only
    | widths it has already generated (via the API's iiurlwidth) are served.
    |
    | Future option (not implemented): a configurable download width would
    | require batched MediaWiki API calls (iiurlwidth) at download time.
    |
    */
];
