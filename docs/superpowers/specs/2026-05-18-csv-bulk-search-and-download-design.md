# CSV Bulk Search and Download — Design

**Date:** 2026-05-18
**Status:** Approved (pending implementation plan)
**Project:** cars-images-api (Laravel 12 + Filament 4)

## Goal

Add a three-page workflow for bulk searching and downloading Wikimedia car images driven by CSV input. The admin uploads a CSV of `(Year, Make, Model, Transmission)` rows, reviews the imported search queries, manually runs them one-by-one or in small bulk batches, then browses results and downloads images individually or as a ZIP with consistent `"YEAR MAKE MODEL"` filenames. A CSV manifest export accompanies the ZIP for verification and re-import.

This feature is intended for an admin user to harvest reference images for thousands of vehicle records over time, while staying within Wikimedia's bulk-reader etiquette.

## Constraints driving the design

The shape of this feature is constrained by:

- **SiteGround shared hosting** — no Redis, no persistent queue worker, no background daemons. The Docker dev stack (already implemented, see `2026-05-06-docker-dev-stack-siteground-parity-design.md`) intentionally mirrors this.
- **No cron requirement** — admin explicitly does not want to set up `schedule:run` cron jobs.
- **No AI** — admin reviews images manually; no OpenAI Vision or other ML validation.
- **Wikimedia blocking risk** — bulk reads can trigger rate-limiting (HTTP 429/503). The design must surface blocks immediately, not silently retry.
- **Manual pacing** — admin explicitly wants to control when searches run; nothing auto-fires on upload.

These constraints rule out queue-driven or scheduled execution. All processing happens **in the foreground**, triggered by an explicit admin click in Filament.

## User flow

```
1. Upload CSV → rows stored as pending car_searches; no Wikimedia calls
2. Search Queries → admin selects rows, clicks "Run" (single or bulk)
                  → loader shows progress; Wikimedia calls happen inline
                  → results saved as car_images
3. Results → admin browses images, downloads per-image or bulk ZIP/CSV
```

The three pages are distinct Filament navigation items under the existing **Cars** group. The mental separation is intentional: **import → run → harvest**.

## Database schema

### New table: `csv_imports`

Tracks each CSV upload as a parent record so the admin can see import history and filter queries by source.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `original_filename` | string | as uploaded |
| `total_rows` | unsigned int | total CSV rows parsed |
| `unique_combos` | unsigned int | distinct `(Year, Make, Model)` after dedup |
| `duplicates_skipped` | unsigned int | `total_rows - unique_combos` |
| `imported_by` | FK → users | cascade on delete |
| `created_at` / `updated_at` | timestamps | |

### New table: `wikimedia_block_events`

Captures every block-like response (429/403/503) for visibility. Admin reviews this from a relation manager on `CsvImport` and on the Search Queries page.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `car_search_id` | FK → car_searches, nullable | the query that was running when blocked |
| `csv_import_id` | FK → csv_imports, nullable | the parent import |
| `status_code` | smallint | 429 / 403 / 503 / etc |
| `retry_after_seconds` | unsigned int, nullable | from `Retry-After` header if present |
| `response_excerpt` | text | first 1KB of response body |
| `occurred_at` | timestamp | |

### Schema change: `car_searches`

Add nullable `csv_import_id` FK (constrained, nullOnDelete). All existing logic that touches `car_searches` continues to work for non-imported searches (the existing single-search flow in `CarSearchResource` is untouched).

For CSV-imported queries:
- `from_year` and `to_year` are both set to the CSV row's `Year`
- `transmission` is copied from the CSV row (informational; not used in Wikimedia query by default)
- `color` is null
- `transparent_background` is false
- `images_per_year` defaults to 5 (configurable per import)
- `status` starts as `pending`
- `requested_by` = the importing user
- `csv_import_id` is set

## Page 1 — Upload CSV

**Filament resource:** `App\Filament\Resources\CsvImportResource` (under the **Cars** nav group).

### Create page (the upload form)

- `FileUpload` component, accepts `.csv` files up to 5 MB.
- On submit:
  1. Parse the CSV using PHP's native `fgetcsv` in a streaming loop (no `array_map` over the full file — memory safe).
  2. Validate required columns: `Make`, `Model`, `Year`. `Transmission` is optional.
  3. Validate year range per row (1900 ≤ year ≤ current year + 1). Invalid rows are skipped and counted in the result toast.
  4. Deduplicate by `(Year, Make, Model)` — `Transmission` is *not* used for dedup (the search doesn't filter by it for CSV imports).
  5. Enforce **`CSV_IMPORT_MAX_COMBOS`** (default 1,000) on the deduplicated count. If exceeded, reject the upload with a clear error pointing the admin at splitting the CSV externally.
  6. Bulk-insert one `car_searches` row per unique combo (status `pending`, see schema notes above) with `csv_import_id` set.
  7. Create the `csv_imports` parent record with the counts.
  8. Redirect to the import's View page with a success toast: "1,000 queries imported (500 duplicates skipped)".

### List page

Standard Filament table:

| Column | Detail |
|---|---|
| `original_filename` | searchable |
| `imported_by` | the User who uploaded |
| `unique_combos` | numeric |
| `pending / done / failed` counts | derived (computed per row) |
| `created_at` | sortable, default sort desc |

Per-row actions: `View`, `Delete` (cascades to `car_searches` — explicit confirmation modal).

### View page

Read-only summary plus a `RelationManager` for the import's queries (read-only listing; running happens from Page 2). Includes a "Go to Search Queries (filtered)" button that links to Page 2 with `?csv_import=N` query param applied.

## Page 2 — Search Queries

**Filament resource:** `App\Filament\Resources\SearchQueryResource` (under the **Cars** nav group).

This resource scopes `CarSearch` to CSV-imported rows only (`csv_import_id IS NOT NULL`). The existing `CarSearchResource` continues to handle the manual single-search flow for non-imported searches; the two resources do not conflict because they show disjoint sets of `car_searches` rows.

### List page

| Column | Detail |
|---|---|
| `from_year` (displayed as "Year") | sortable |
| `make` | searchable, filterable |
| `model` | searchable |
| `csv_import_id` | filter dropdown (lists CSV import filenames) |
| `status` | badge: `pending` (gray) / `running` (yellow) / `done` (green) / `failed` (red) |
| `images_count` | derived: `images()->count()` |

**Filters at top:** by CSV import, by status, by make, by year range.

**Auto-poll:** the table is configured with `->poll('3s')` so status and counts refresh while the admin runs queries.

### Single-query Run action

Per-row `Action::make('run')` button, visible when `status` is `pending` or `failed`.

On click:
1. Filament button shows its built-in loading spinner.
2. Server-side, the action calls a synchronous service method `RunSearchQueryAction` that:
   - Marks the query `running`.
   - Calls `CarImageSearchService::runSearch($carSearch)` — which delegates to the existing `WikimediaClient`.
   - Catches `WikimediaBlockedException` → marks `failed` and logs to `wikimedia_block_events`.
   - On success → marks `done`, persists images via the existing `CarImage` model.
3. Button returns; row refreshes with the new status and image count.

Typical wall-clock time per query: ~1–3 seconds (single API call + image metadata fetches, all cached after first run).

### Bulk Run action

`BulkAction::make('runSelected')` with confirmation modal.

On confirm:
1. The action iterates the selected `CarSearch` rows sequentially in the foreground.
2. Between queries, `sleep(1)` to throttle Wikimedia.
3. A live progress callback updates the action's progress bar UI: "Running 3 of 10…".
4. If any query throws `WikimediaBlockedException`:
   - The bulk loop stops immediately.
   - The remaining unprocessed queries stay `pending`.
   - The action returns with an error notification: "Paused — Wikimedia returned HTTP 429 at <time>".
   - A `wikimedia_block_events` row is written.
5. Hard time cap: the bulk action stops at 50 sequential queries OR 50 wall-clock seconds, whichever first, to stay under PHP's `max_execution_time` on shared hosting.

After the action returns, the page polls and updates; admin clicks "Run Selected" again to continue with the remaining queries.

### Retry action

Visible when `status == 'failed'`. Same machinery as Run but resets the status first.

## Page 3 — Results

**Filament page (custom):** `App\Filament\Pages\Results` (under the **Cars** nav group; not a Resource because it's a custom view over `car_images`).

### Layout

- Top filter row: Source CSV (dropdown), Search query (year/make/model autocomplete), Year (numeric range), Make (autocomplete).
- Sticky action bar with selection count and two buttons: `[Download Selected as ZIP]`, `[Export Selected as CSV]`.
- Grid of thumbnail cards, paginated (50 per page default, configurable).

Each card shows:
- Thumbnail (~200px wide).
- Caption: the generated filename (e.g. "1997 Toyota RAV4").
- Checkbox for bulk selection.
- Three per-image actions: `[Preview]` (modal), `[Download]` (single-file, renamed), `[Delete]`.

The Preview modal reuses the existing infrastructure from `CarImageResource`.

### Single-image download

Click `[Download]` → server returns the image binary with `Content-Disposition: attachment; filename="1997 Toyota RAV4.jpg"`. The filename is generated at request time via the `FilenameBuilder` service.

### Bulk download as ZIP

Click `[Download Selected as ZIP]` → server streams a ZIP via `ZipStream-PHP` (or Symfony's `BinaryFileResponse` over a temp zip — implementation-detail decision in the plan).

Inside the ZIP, each file is named using the `FilenameBuilder` with full duplicate-resolution: see "Filename rules" below.

### Bulk export as CSV manifest

Click `[Export Selected as CSV]` → server streams a CSV via `StreamedResponse`:

```csv
Year,Make,Model,Transmission,Filename,SourceUrl,SearchId,ImageId
1997,Toyota,RAV4,Automatic 4-spd,1997 Toyota RAV4.jpg,https://upload.wikimedia.org/...,142,891
1997,Toyota,RAV4,Automatic 4-spd,1997 Toyota RAV4 2.jpg,https://upload.wikimedia.org/...,142,892
2015,Mitsubishi,Mirage,Manual 5-spd,2015 Mitsubishi Mirage.jpg,https://upload.wikimedia.org/...,143,895
```

The `Filename` column matches exactly what would be inside a ZIP of the same selection — so ZIP and CSV are paired exports.

## Filename rules

A single `FilenameBuilder` service generates all filenames. Used by:
- Single download (`Content-Disposition` header)
- Bulk ZIP (each entry inside the archive)
- CSV manifest (the `Filename` column)

### Algorithm

1. **Base string:** `sprintf('%d %s %s', $year, $make, $model)` → e.g. `"1997 Toyota RAV4"`.
2. **Sanitize:** replace `/ \ : * ? " < > |` with ` - ` (single hyphen with surrounding spaces).
3. **Collapse whitespace:** multiple spaces → single space; trim.
4. **Length cap:** `mb_substr($base, 0, 200)`.
5. **Extension:** preserve from the source URL — `.jpg`, `.png`, `.webp`. Default to `.jpg` if the URL has none.
6. **Duplicate resolution:** at export time (per ZIP or per CSV — not stored in DB):
   - Maintain an in-memory set of used names.
   - First image gets `"{base}.{ext}"`.
   - Each subsequent collision gets `"{base} 2.{ext}"`, `"{base} 3.{ext}"`, … incrementing.
   - For single-image downloads, the system passes the image's per-search rank as the duplicate counter so a deterministic name is returned regardless of selection order. (Rank computed as `car_search.images()->orderBy('id')->pluck('id')->search($image->id) + 1`.)

### Example outputs

| Input | Output |
|---|---|
| Year=1997, Make=Toyota, Model=RAV4 | `1997 Toyota RAV4.jpg` |
| Same combo, second image | `1997 Toyota RAV4 2.jpg` |
| Year=1998, Make=Acura, Model=2.2CL/3.0CL | `1998 Acura 2.2CL - 3.0CL.jpg` |
| Year=2015, Make=Mitsubishi, Model=Mirage | `2015 Mitsubishi Mirage.jpg` |

## Wikimedia etiquette safeguards

These are baked into `WikimediaClient` and the Search Queries flow:

1. **Honest User-Agent.** Update `WIKIMEDIA_USER_AGENT` in `.env.example` to include a contact URL and email: `"CarsImagesApi/1.0 (https://cars-search.artworkwebsite.com; jaeron.rivera@gmail.com)"`.
2. **`maxlag=5` parameter** on every API call. Standard Wikimedia cooperation signal.
3. **1-second throttle** between sequential queries in bulk-run actions (`sleep(1)`).
4. **Exponential backoff** on transient errors via Laravel's `Http::retry()`. Bump `WIKIMEDIA_RETRY_TIMES` to 5 and `WIKIMEDIA_RETRY_SLEEP_MS` to 2000 with exponential growth.
5. **Auto-pause on block.** Any 429/403/503 throws `WikimediaBlockedException`. The exception:
   - Is caught by `RunSearchQueryAction` (single) and the bulk-run loop.
   - Stops the bulk loop immediately (no more queries fire).
   - Writes a `wikimedia_block_events` row including `status_code`, `retry_after_seconds`, and a 1KB excerpt of the response body.
   - Marks the failing `car_search` as `failed`. The block event row carries the diagnostic detail; admin retries from the Search Queries page when ready.
6. **24-hour cache TTL.** Bump `WIKIMEDIA_CACHE_TTL` from 3600 to 86400. Re-running an identical query hits the cache; no API call.
7. **CSV upload row cap** (`CSV_IMPORT_MAX_COMBOS`, default 1,000) prevents accidental ingest of the 47k-row master CSV in one go.

## What gets built

### Migrations
- `create_csv_imports_table`
- `create_wikimedia_block_events_table`
- `add_csv_import_id_to_car_searches_table` — nullable FK with nullOnDelete

### Models
- `App\Models\CsvImport` — hasMany `CarSearch` (via `csv_import_id`), belongsTo `User` (`imported_by`)
- `App\Models\WikimediaBlockEvent` — belongsTo `CarSearch`, belongsTo `CsvImport`
- `App\Models\CarSearch` — add `csv_import_id` to `$fillable` and `belongsTo` relation, plus add `blockEvents()` hasMany

### Services
- `App\Services\Imports\CsvQueryImporter` — parses CSV, deduplicates, bulk-inserts queries
- `App\Services\Search\RunSearchQueryAction` — runs one query inline, handles success/failure/block
- `App\Services\Downloads\FilenameBuilder` — the algorithm above
- `App\Services\Downloads\BatchZipBuilder` — streams ZIP using `FilenameBuilder`
- `App\Services\Downloads\BatchCsvExporter` — streams CSV manifest
- `App\Services\Images\WikimediaClient` — modified: honest UA, `maxlag=5`, throws `WikimediaBlockedException` on 429/403/503

### Exceptions
- `App\Exceptions\WikimediaBlockedException` — carries status_code, retry_after_seconds, response_excerpt

### Filament resources / pages
- `App\Filament\Resources\CsvImportResource` + `Pages\CreateCsvImport`, `ListCsvImports`, `ViewCsvImport`
- `App\Filament\Resources\SearchQueryResource` + `Pages\ListSearchQueries` (no Create/Edit — imports are read-only here)
- `App\Filament\Pages\Results` (custom page, not a Resource)

### HTTP routes / controllers
- `App\Http\Controllers\CarImageBulkDownloadController` — endpoints for ZIP and CSV exports

### Config / env
- `.env` and `.env.example`: add `CSV_IMPORT_MAX_COMBOS=1000`, update `WIKIMEDIA_USER_AGENT`, bump `WIKIMEDIA_RETRY_TIMES`, `WIKIMEDIA_RETRY_SLEEP_MS`, `WIKIMEDIA_CACHE_TTL`
- `config/cars-images.php` (new) — central config for `csv_import_max_combos`, `default_images_per_year`, `bulk_run_max_per_chunk`, `bulk_run_sleep_ms`

### Documentation
- `README.md`: add a "CSV Bulk Search" section walking through the three pages
- `DEPLOYMENT.md`: no change (no queue or cron added)

## Out of scope (confirmed)

- ❌ Queue worker, cron, scheduled jobs (admin explicitly does not want this)
- ❌ OpenAI / GPT-4 Vision / any AI validation (admin reviews manually)
- ❌ Auto-running queries on CSV upload (upload imports only)
- ❌ Production Docker image, deployment automation
- ❌ Browser-side splitting of large CSVs (admin splits externally in Excel/Sheets)

## Acceptance criteria

1. Admin can upload a CSV with the sample headers (`Make,Model,Year,Transmission`). Upload deduplicates by `(Year, Make, Model)` and reports counts in a toast.
2. Uploading a CSV with > 1,000 unique combos rejects with an actionable error.
3. After upload, the imported queries appear in the **Search Queries** page filtered by their `csv_import_id`.
4. Clicking `[Run]` on a single pending query shows a spinner, calls Wikimedia inline, and updates the row status without a full page reload.
5. Selecting N pending queries and clicking `[Run Selected]` runs them sequentially with a visible progress bar; stops at 50 queries or 50 seconds.
6. Simulating a 429 from Wikimedia (e.g. by mocking the client in a feature test) causes:
   - The bulk run to stop immediately.
   - A `wikimedia_block_events` row to be written with the correct status_code and excerpt.
   - The failing query to be marked `failed`.
   - A user-visible error notification.
7. Completed queries link to the **Results** page filtered to that query's images.
8. Clicking `[Download]` on a single image returns the binary with `Content-Disposition: attachment; filename="YEAR MAKE MODEL.jpg"` (sanitized correctly for models containing `/`).
9. Selecting multiple images and clicking `[Download Selected as ZIP]` streams a ZIP whose internal filenames match the documented algorithm, including duplicate suffixing.
10. Selecting the same images and clicking `[Export Selected as CSV]` streams a CSV whose `Filename` column matches the ZIP entries byte-for-byte.
11. The existing single-search flow in `CarSearchResource` continues to work unchanged (regression check).
12. No queue worker, no cron entry, and no scheduled task is required for the feature to function.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Admin uploads the full 47k-row master CSV at once | `CSV_IMPORT_MAX_COMBOS` blocks it with a clear error directing them to split externally |
| Bulk run on shared hosting times out mid-batch | Hard cap of 50 queries or 50 seconds per click; remaining queries stay `pending` for the next click |
| Wikimedia silently rate-limits without 429 (degraded responses) | `maxlag=5` parameter tells the server to return errors instead — we'd catch those as transient and back off |
| Admin loses track of which queries belong to which CSV | Every query has `csv_import_id`; filters on Search Queries and Results pages key off it; relation managers show parent→child links |
| Filename collisions across batches | Dedup happens per-export (per ZIP or per CSV), so collisions are handled deterministically by counter suffix |
| Existing `CarSearchResource` confusing for admins now that there are two search flows | `SearchQueryResource` is scoped to `csv_import_id IS NOT NULL`; the existing resource stays for ad-hoc single searches with year ranges, color, transmission filters — a different use case |
