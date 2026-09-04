# Pipeline Error Log — Design

**Date:** 2026-09-04
**Status:** Approved (pending implementation plan)
**Project:** cars-images-api (Laravel 12 + Filament 4)

## Goal

Give the operator one queryable place that answers **why** a CSV upload, a bulk
search run, or a bulk download failed — with enough technical detail to diagnose
it without reproducing the failure.

The audience is the operator debugging a broken run, not an end user reading a
friendly summary. Rows carry exception class, exception message, a stack-trace
excerpt, and a raw-detail payload (HTTP status, source URL, CSV line number,
response excerpt).

## What is wrong today

Failure is recorded across the pipeline as a **state**, never as a **reason**. A
state tells you something broke; it cannot tell you what to do about it.

| Site | What happens now | What is lost |
|---|---|---|
| [`ListSearchQueries.php:179`](../../../app/Filament/Resources/SearchQueryResource/Pages/ListSearchQueries.php) | `catch (Throwable) { $this->runFailed++; }` — no variable is even bound | **Everything.** A 500-query bulk run can report "37 failed" with zero diagnosis. |
| [`RunSearchQueryAction.php:41`](../../../app/Services/Search/RunSearchQueryAction.php) | marks the search `failed`, rethrows | The exception, once the loop above swallows it |
| [`BatchZipBuilder.php:59`](../../../app/Services/Downloads/BatchZipBuilder.php) | non-2xx → bare `continue` | A ZIP silently returns fewer images than requested; which images dropped, and why, is unrecorded |
| [`CarImageZipService.php:62`](../../../app/Services/Images/CarImageZipService.php) | non-2xx → bare `continue` | Same, on the per-search ZIP path |
| [`CreateCsvImport.php:48`](../../../app/Filament/Resources/CsvImportResource/Pages/CreateCsvImport.php) | `CsvImportException` → a toast | Nothing persisted; the toast dies with the page |
| `CsvQueryImporter` | returns `skippedInvalidRows` as an int | *Which* rows, *which* line numbers, *why* — the information needed to fix the CSV |

The app already has exactly one well-built error log:
[`wikimedia_block_events`](../../../database/migrations/2026_05_18_061125_create_wikimedia_block_events_table.php) —
a narrow table with `status_code`, `retry_after_seconds`, `response_excerpt`,
`occurred_at`, and nullable foreign keys to both the search and the import. Its
shape is right; it simply covers one failure mode. This design generalises that
shape rather than inventing a new one.

## Constraints

Inherited from `2026-05-18-csv-bulk-search-and-download-design.md` and
re-verified on 2026-09-04:

- **`QUEUE_CONNECTION=sync`, no queue worker, no cron.** Grepping for
  `dispatch(` finds only Livewire browser events. `app/Jobs/RunCarSearchJob.php`
  and `app/Jobs/DownloadCarImagesJob.php` are dead code — nothing dispatches
  them. They are therefore **not instrumented**; the live download failures live
  in the two ZIP builders instead.
- **No cron** means a scheduled prune would never fire. Pruning is manual: an
  artisan command plus a header action on the log page.
- **Bulk runs are chunked across Livewire requests**, so any per-run counter
  kept in memory resets between chunks. Caps must be enforced against the
  database, not against process state.
- **Volume.** A full CSV run is capped at `csv_import_max_combos` (1,000)
  queries × `csv_import_default_images_per_year` (5) images. Logging every
  individual failure is the agreed granularity, so one catastrophic run could
  write thousands of rows without a cap.

## Schema

New table `error_events`, modelled on `wikimedia_block_events`.
`public $timestamps = false` — an event has one time, the moment it happened,
not a created/updated pair.

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `context` | string, indexed | `csv_upload` \| `csv_row` \| `search_run` \| `image_download` \| `wikimedia_block` |
| `severity` | string | `error` \| `warning` |
| `message` | text | Human summary, e.g. `Toyota Corolla 2019 — search failed` |
| `exception_class` | string, nullable | |
| `exception_message` | text, nullable | |
| `trace_excerpt` | text, nullable | First ~15 frames, truncated |
| `details` | json, nullable | `http_status`, `url`, `row_number`, `raw_row`, `response_excerpt` |
| `car_search_id` | FK nullable, `nullOnDelete` | |
| `csv_import_id` | FK nullable, `nullOnDelete` | |
| `car_image_id` | FK nullable, `nullOnDelete` | |
| `occurred_at` | timestamp, `useCurrent`, indexed | |

Composite index on `(context, occurred_at)` for the log page's default filtered
ordering.

**Why `nullOnDelete` and not `cascadeOnDelete`.** Deleting a CSV import must not
cascade away the record of why it failed. The log outlives the thing it
describes; that is the point of a log.

## Writer

`App\Services\Logging\ErrorEventLogger`, a single entry point:

```php
public function record(
    string $context,
    Throwable|string $problem,
    array $links = [],      // car_search_id, csv_import_id, car_image_id
    array $details = [],    // http_status, url, row_number, raw_row, response_excerpt
    string $severity = 'error',
    ?string $message = null,
): void
```

Three responsibilities live here so no call site can get them wrong:

1. **Truncation.** `exception_message` capped at 2,000 characters,
   `trace_excerpt` at the first 15 frames, each `details` string value at 1,000
   characters. Raw bodies are excerpted, never stored whole — the same
   discipline `wikimedia_block_events.response_excerpt` already applies.
2. **The per-import cap.** Before writing a row carrying a `csv_import_id`, the
   logger counts existing rows for that import. Once
   `cars-images.error_log_max_events_per_import` (default 500) is reached it
   writes a single `Further errors suppressed for this import` row and returns.
   The count is a database query rather than an in-memory counter, because bulk
   runs are chunked across separate Livewire requests.
3. **Never throwing.** The whole body is wrapped in a `try`/`catch` that falls
   back to `Log::error()`. A broken log must not break a run — logging sits on
   the failure path, which is precisely where a second failure is least welcome.

## Instrumentation

Six live sites. Each records and then behaves exactly as it does today; no
control flow changes.

1. **[`RunSearchQueryAction.php:41`](../../../app/Services/Search/RunSearchQueryAction.php)**
   — generic `catch` → context `search_run`, linked to the search and its
   import. This is the narrowest point every admin path passes through, so the
   bulk loop and the single-row action are both covered by one edit. The bare
   `catch (Throwable)` in `ListSearchQueries` stays as it is: by the time it is
   reached the reason is already recorded.
2. **[`RunSearchQueryAction.php:28`](../../../app/Services/Search/RunSearchQueryAction.php)**
   — block `catch` → context `wikimedia_block`, mirroring the row alongside the
   existing `WikimediaBlockEvent::create()`. That table and every reader of it
   (the halt logic, the import view) stay untouched; the mirror exists only so
   the log page is a single place showing every failure.
3. **[`BatchZipBuilder.php:59`](../../../app/Services/Downloads/BatchZipBuilder.php)**
   — non-2xx before `continue` → context `image_download` with `http_status`,
   `url`, and `car_image_id`.
4. **[`CarImageZipService.php:62`](../../../app/Services/Images/CarImageZipService.php)**
   — the same, on the per-search ZIP path.
5. **[`CreateCsvImport.php:48`](../../../app/Filament/Resources/CsvImportResource/Pages/CreateCsvImport.php)**
   — `CsvImportException` → context `csv_upload`, persisted **before** the toast
   is sent and before `halt()`.
6. **`CsvQueryImporter`** — one `csv_row` row per rejected line, carrying
   `row_number`, `raw_row`, and the rejection reason.

Site 6 is the only one that changes an existing signature: the importer
currently keeps no per-row detail, only a count. `CsvImportResult` keeps
`skippedInvalidRows` unchanged so the existing success toast is unaffected.

**Explicitly not instrumented:** `RunCarSearchJob` and `DownloadCarImagesJob`.
Nothing dispatches them. Instrument them when a queue worker exists, not before.

## Log page

`App\Filament\Resources\ErrorEventResource` — read-only: no create page, no edit
page, `canCreate()` and `canEdit()` return `false`.

- **Navigation:** group `Logs`. Badge shows the count of rows in the last 24
  hours, `danger` colour, hidden at zero.
- **Table:** `occurred_at` (default sort, descending), `context` as a colour-coded
  badge, `message`, and a link to the related record (search, import, or image)
  where one is set.
- **Filters:** `context`, date range on `occurred_at`, and CSV import.
- **View page:** full `exception_class` / `exception_message`, the trace excerpt
  in a monospace block, and `details` pretty-printed as JSON.
- **Bulk delete stays enabled** so noise can be cleared by hand.
- **Header action `Prune old entries`** — deletes rows older than the retention
  window, with a confirmation modal stating how many rows will go.

## Pruning

`php artisan error-events:prune` deletes rows with
`occurred_at` older than `cars-images.error_log_retention_days` (default 30) and
reports the count deleted. It is callable by hand and correct if a scheduler
ever exists; it is **not** registered in `routes/console.php`, because there is
no cron to run it. The log page's header action calls the same code path, so
there is one implementation of the retention rule and one place it can be wrong.

## Configuration

Added to `config/cars-images.php`, in the established style — each value read
from `env()` with a comment explaining what it bounds:

| Key | Env | Default |
|---|---|---|
| `error_log_retention_days` | `ERROR_LOG_RETENTION_DAYS` | 30 |
| `error_log_max_events_per_import` | `ERROR_LOG_MAX_EVENTS_PER_IMPORT` | 500 |

## Testing

Feature tests, following the existing suite's conventions:

- `ErrorEventLogger` truncates an oversized exception message, trace, and detail
  value to the documented caps.
- `ErrorEventLogger` swallows a write failure and does not propagate it.
- The per-import cap writes exactly one suppression row and no more, across two
  separate calls simulating two Livewire chunks.
- Each of the six live sites writes a row with the right context and links, driven
  by a faked failing Wikimedia client (`Http::fake()`).
- A failing image fetch inside `BatchZipBuilder` still produces a ZIP of the
  remaining images **and** an `error_events` row — the control flow is unchanged.
- `error-events:prune` deletes rows past the window and keeps rows inside it.
- The Filament resource renders, filters by context, and refuses create/edit.

## Files

**New**

- `database/migrations/2026_09_04_XXXXXX_create_error_events_table.php`
- `app/Models/ErrorEvent.php`
- `app/Services/Logging/ErrorEventLogger.php`
- `app/Console/Commands/PruneErrorEvents.php`
- `app/Filament/Resources/ErrorEventResource.php`
- `app/Filament/Resources/ErrorEventResource/Pages/ListErrorEvents.php`
- `app/Filament/Resources/ErrorEventResource/Pages/ViewErrorEvent.php`
- `database/factories/ErrorEventFactory.php`
- `tests/Feature/ErrorEventLoggerTest.php`
- `tests/Feature/ErrorEventLogPageTest.php`
- `tests/Feature/PruneErrorEventsTest.php`

**Modified**

- `app/Services/Search/RunSearchQueryAction.php`
- `app/Services/Downloads/BatchZipBuilder.php`
- `app/Services/Images/CarImageZipService.php`
- `app/Filament/Resources/CsvImportResource/Pages/CreateCsvImport.php`
- `app/Services/Imports/CsvQueryImporter.php`
- `config/cars-images.php`
- `.env.example`

## Out of scope

- **A global `withExceptions` hook** in `bootstrap/app.php` capturing every
  unhandled exception app-wide. That makes this a general application error log
  rather than a pipeline log — a different feature with a different retention
  profile.
- **Absorbing `wikimedia_block_events`.** Its readers work; migrating them buys
  tidiness at the cost of touching the bulk-run halt logic.
- **Instrumenting the two queued jobs.** Dead code until a worker exists.
- **Alerting.** The log is pull, not push.
