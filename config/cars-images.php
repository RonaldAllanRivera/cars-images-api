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
    | Max size of the uploaded CSV file, in kilobytes. Kept here rather than
    | inline on the form so the upload rule and the note shown to the admin
    | read the same number and cannot drift apart.
    */
    'csv_import_max_upload_kb' => env('CSV_IMPORT_MAX_UPLOAD_KB', 5120),

    /*
    | Ceiling on the Wikimedia API image downloads a single CSV may commit us
    | to: unique queries x csv_import_default_images_per_year.
    |
    | Why this exists as its own cap. The combo cap above bounds the number of
    | QUERIES; it says nothing about the number of IMAGES those queries fetch.
    | At the defaults, 1,000 queries x 5 images = 5,000 downloads, which the
    | run then paces at bulk_run_sleep_seconds_between_queries (~17 min of
    | continuous running) and which leave the app only via bulk_download_max_images
    | per ZIP (~50 separate downloads). Raising csv_import_default_images_per_year
    | multiplies all three at once, and without this cap that increase would be
    | discovered part-way through a long run rather than at upload time.
    |
    | The default is deliberately the product of the two caps above it, so
    | out of the box this rejects nothing the combo cap would have allowed.
    | Raise it only alongside the pacing and download limits it depends on.
    */
    'csv_import_max_projected_images' => env('CSV_IMPORT_MAX_PROJECTED_IMAGES', 5000),

    /*
    |--------------------------------------------------------------------------
    | Bulk run pacing
    |--------------------------------------------------------------------------
    */

    'bulk_run_max_queries_per_chunk' => env('CARS_BULK_RUN_MAX_QUERIES', 50),

    'bulk_run_max_seconds_per_chunk' => env('CARS_BULK_RUN_MAX_SECONDS', 50),

    /*
     | Wall-clock budget for one auto-driven chunk. Much shorter than the
     | manual chunk above: the browser polls for the next one immediately, so
     | a short chunk costs nothing but makes the progress bar and the estimate
     | move often enough to read as live rather than stuck.
     */
    'bulk_run_auto_chunk_seconds' => env('CARS_BULK_RUN_AUTO_CHUNK_SECONDS', 10),

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

    /*
    | Max images per bulk ZIP download. The ZIP is built synchronously in a
    | single web request (fetch + resize each image), so on shared hosting a
    | large selection would exceed the web request timeout. Selections above
    | this are rejected with a "select fewer" notice rather than timing out.
    | Raise it only if the host allows long-running web requests.
    */
    'bulk_download_max_images' => env('CARS_BULK_DOWNLOAD_MAX_IMAGES', 100),
];
