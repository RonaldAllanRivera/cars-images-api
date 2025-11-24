# Chat Summary – Cars Images API (Laravel 12 + Filament 4)

This file summarizes the key decisions and changes discussed so far so you can continue the work from another machine.

## High-level goals

- Internal tool to fetch and manage **high-resolution car images** from **Wikimedia Commons**.
- Built with **Laravel 12** + **Filament 4** admin panel.
- Search by **make**, **model**, **year range**, **color**, **transmission**, and **transparent background**.
- Cache results in MySQL and avoid unnecessary Wikimedia calls.
- Provide an admin UI to search, review images, and (later) download/export.

## Core implementation status

### Backend & data model

- Migrations for:
  - `car_searches` with: make, model, from_year, to_year, color, transmission, transparent_background, images_per_year, status, requested_by, timestamps.
  - `car_images` with: car_search_id, make, model, year, color, transmission, transparent_background, provider/page IDs, title, description, source_url, thumbnail_url, size, license, attribution, download_status, download_path, metadata, timestamps.
  - Additional migration to widen `car_images.title`, `source_url`, and `thumbnail_url` columns to `TEXT` to support long Wikimedia URLs.
- Models:
  - `CarSearch` fillable attributes include `transmission`, relationships to `CarImage` and `User`.
  - `CarImage` belongs to `CarSearch` and stores normalized metadata.

### Wikimedia integration

- `App\Services\Images\WikimediaClient`:
  - Uses `config/images.php` and `.env` for base URL, timeouts, retries, cache TTL, and user agent.
  - Public method:
    - `searchCars(string $make, ?string $model, int $year, ?string $color, ?string $transmission, bool $transparent, int $limit = 10): Collection`.
  - Builds queries like `"{make} {model} {year} car"` plus optional color and transmission.
  - Uses Laravel HTTP client with retries and timeouts.
  - Caches responses per `(make, model, year, color, transmission, transparent)` combination.
  - Maps MediaWiki pages to a normalized image array used by `CarImageSearchService`.
  - Filters results to prefer cars:
    - Drops images whose title/description/categories obviously reference flowers/plants/etc.
    - Prefers images mentioning car/vehicle-related keywords.

- `App\Services\Images\CarImageSearchService`:
  - Creates new `CarSearch` records.
  - Normalizes year ranges: if `fromYear > toYear` they are swapped.
  - `findExistingCompletedSearch()` checks for an identical completed search and reuses it (avoids extra Wikimedia calls).
  - `runSearch()` loops over each year and calls `fetchAndStoreForYear()` which delegates to `WikimediaClient` and persists `CarImage` records.

### Jobs & queues

- Jobs implemented:
  - `RunCarSearchJob` – coordinates an entire search.
  - `FetchWikimediaCarImagesForYearJob` – per-year fetch using `WikimediaClient` and creating `CarImage` records.
  - `DownloadCarImagesJob` – designed to download image files to the `cars` storage disk (to be wired to UI bulk actions later).
- Current local setup:
  - `QUEUE_CONNECTION=sync`.
  - For UX and debugging, searches are currently executed synchronously from the Filament Create page (jobs callable later for real async processing).

## Filament admin UI

### Resources

- `CarSearchResource`:
  - Navigation group: **Cars**, label: **Car Image Searches**.
  - **Form** uses Filament 4 Schemas API:
    - `make`: `Select`, **required**, options provided by `getMakeOptions()`, default `Toyota`, `.live()`, and resets `model` when make changes.
    - `model`: `Select`, options from `getModelOptionsForMake($make)`, nullable, searchable.
      - Popular makes: Toyota, Honda, Tesla, Ford, BMW, Mercedes-Benz, Nissan, Hyundai, Kia, Volkswagen.
      - Each make has a set of common models (Corolla, Civic, Model 3, Mustang, etc.).
    - `from_year` / `to_year`: numeric, defaults 2018–2022, year bounds are validated; range is normalized in the service rather than via a strict `gte` validation rule.
    - `color`: `Select` with popular colors (red, white, black, blue, silver, grey, green, yellow), default `red`.
    - `transmission`: `Select` (Automatic, Manual, CVT), default `Automatic`.
    - `transparent_background`: `Toggle`, default `false`.
    - `images_per_year`: numeric, default 10, min 1, max 50.
  - **Table**:
    - Columns for make, model, from_year/to_year, status (badge with colors), created_at.
    - Filter by `status`.
    - Default pagination: **100 rows per page** with options `[10, 25, 50, 100]`.
    - View action opens a detail page; relation manager lists associated `CarImage` records.
  - **Pages**:
    - `ListCarSearches` – index with a header **Create** action so users can open the real search form.
    - `CreateCarSearch` – on submit:
      - Checks for an existing completed identical search via `CarImageSearchService::findExistingCompletedSearch()`.
      - If found, redirects to that search instead of calling Wikimedia again.
      - Otherwise creates a new `CarSearch` and runs the search **synchronously** via `CarImageSearchService::runSearch()`.
    - `ViewCarSearch` – shows search parameters and related images.

- `CarImageResource`:
  - Navigation group: **Cars**, label: **Car Images**.
  - **Table**:
    - Thumbnail image column for `thumbnail_url`.
    - Text columns for make, model, year, color, license, download_status.
    - Default pagination: **100 rows per page** with options `[10, 25, 50, 100]`.
  - Intended future features: filters, bulk download/export.

## Validation and UX decisions

- Year range:
  - Frontend no longer uses a strict `gte:from_year` rule on `to_year` (which was misbehaving).
  - Backend normalizes the range so that `from_year <= to_year` regardless of input order.
- Dynamic UI:
  - `make` and `model` are wired using Filament 4 `Get`/`Set` utilities from the Schemas API.
  - Changing `make` clears `model` and reloads model options.
- Result quality:
  - A simple content filter in `WikimediaClient` avoids obvious non-car results (flowers/plants/garden, etc.).

## Documentation state

- `PLAN.md`:
  - Describes architecture, data model, search/download flows, Filament UI, configuration, testing, and phased implementation.
  - Status section records that:
    - Phases 1–2 are complete, including transmission support and basic content filtering.
    - Phase 3 jobs are implemented but not yet run asynchronously in production.
    - Phase 4 UI is implemented with dynamic selectors and 100-row pagination.
    - Phases 5–6 (download/export, hardening) are planned but not finished.

- `README.md`:
  - Project overview, tech stack, and Laragon-focused setup instructions.
  - Usage section explains:
    - How to run a car image search from the Filament Create page.
    - Dynamic make/model behaviour.
    - Range normalization, filters, and where images appear in the UI.
    - How caching and reuse of completed searches works.

- `CHANGELOG.md`:
  - `0.1.0` – initial setup, core schema, Wikimedia client, and basic Filament resources.
  - `0.2.0` – URL column widening, transmission support, new jobs, dynamic make/model UI, synchronous search, car-only filtering, and 100-row pagination.

## Next steps (planned but not yet done)

- Wire jobs and queues for real asynchronous processing in non-local environments.
- Implement bulk **Download selected** and **Export selected** actions in Filament.
- Add rate limiting, richer logging/metrics, and automated tests.
- Possibly add a "Refresh from Wikimedia" action on a search to re-run it with updated filters and overwrite cached images.
