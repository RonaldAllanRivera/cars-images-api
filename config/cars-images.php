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
    | Maximum width (in pixels) for images placed in the bulk-download ZIP.
    | Images are downloaded at full resolution from Wikimedia and then
    | resized DOWN to this width on our own server (GD), re-encoded as JPEG.
    |
    | Why server-side resizing instead of Wikimedia's thumbnail CDN:
    | Wikimedia blocks on-demand thumbnail generation from datacenter /
    | shared-hosting IPs (every /thumb/ URL returns HTTP 400 from the
    | SiteGround server), so we cannot rely on its thumbnails in production.
    | Resizing locally works regardless of host.
    |
    | Common choices:
    |   1280  - typical web content width, smallest files
    |   1600  - default; good size/quality balance
    |   1920  - full-HD, sharper but larger
    |
    | Future options to consider (not implemented):
    |   - WebP output for further savings (GD WebP support is available)
    |   - a per-download width chosen in the UI
    |   - applying the same resize to the single-image download
    |
    */
    'download_max_width' => env('CARS_DOWNLOAD_MAX_WIDTH', 1600),

    'download_jpeg_quality' => env('CARS_DOWNLOAD_JPEG_QUALITY', 82),
];
