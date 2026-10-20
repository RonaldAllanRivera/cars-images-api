# PLAN: Cars Images API – Wikimedia + Laravel Filament

_Sections 1–11 are the original design from November 2025, with notes where the code has since diverged. Section 12 is the current status, last checked against the code on 2026-10-20._

## 1. Objectives

- Build an internal system for your employer that fetches **high-resolution car images** from **Wikimedia**.
- Allow users to **search by make, model, and year range (FROM YEAR / TO YEAR)**.
- For each year in the range, send a **separate API request** and retrieve **10 images per call**.
- Let users **filter by color** and **request transparent background images** where possible.
- Provide a **Filament admin UI** to search, preview, and **download images**.
- Store metadata and downloaded files so they can be reused without repeatedly hitting Wikimedia.

---

## 2. High-Level Architecture

### 2.1 Components

1. **Wikimedia API Client**
   - A dedicated service class wrapping Wikimedia (MediaWiki) HTTP calls.
   - Responsible for building search queries (make, model, year, color, transparent flag), handling paging, and extracting image URLs and metadata.

2. **Car Image Search Service**
   - Orchestrates multi-year searches: loops over each year between FROM and TO, dispatches per-year jobs, aggregates results.
   - Applies global settings like images-per-year (10) and max total images.

3. **Jobs & Queues**
   - `FetchWikimediaCarImagesForYearJob` – one job per year, calls the Wikimedia client and persists results.
   - `DownloadCarImagesJob` – downloads selected image URLs to local/remote storage.
   - Use Laravel queue (`database` or `redis`) to avoid blocking the Filament UI.

4. **Persistence Layer (Database)**
   - Tables to track **search requests**, **images**, and **downloads**.
   - Caching of queries (per make/model/year/color/transparent) to reduce API calls.

5. **Storage**
   - Use Laravel `Storage` for downloaded images.
   - Local disk in development, S3 or similar for production.

6. **Filament Admin Panel**
   - Custom Filament pages and/or resources for:
     - Submitting searches.
     - Viewing search history.
     - Reviewing images with filters and bulk actions (e.g. Download selected).
   - The CSV bulk pipeline (Upload CSV → Search Queries → Results), the pipeline error log and the health widgets were added after this plan was written; see [README → Usage](README.md#usage).

7. **JSON API (`/api/v1`)** – shipped in 0.10.0 (2026-09-09)
   - A versioned API that lets clients other than the panel run searches, browse runs and images, record review verdicts, upload and run CSV imports, request signed export links, and read pipeline health.
   - Sanctum bearer tokens with scoped abilities, and a throttle named on every route. Searches run inside the request, so the API's caps are tighter than the panel's.
   - Details: [README → JSON API](README.md#json-api).

8. **Mobile client (`mobile/`)** – shipped in 0.10.0 (2026-09-09)
   - An Expo (React Native, TypeScript) app with Search, Library, Pipeline, Review and Health tabs, built against `/api/v1`. Its Zod schemas are tested against JSON fixtures that the PHP suite generates, so the API and the app cannot drift apart silently.
   - Published as a static web build to Netlify from GitHub Actions; an Android APK is planned.
   - Details: [README → Mobile client](README.md#mobile-client).

> **As built (2026-10-20):** items 1–3 describe the original design. Retrieval now reads Commons categories instead of building search queries (see the note at the top of section 3), and the jobs in item 3 are never dispatched: all work runs synchronously inside web requests, in bounded chunks (section 12, Phase 3).

---

## 3. Wikimedia Integration Design

> **Superseded (2026-08-31).** Full-text search was replaced by Commons category retrieval. `searchCars()` and the query-string and cache-key scheme below no longer exist, and color, transmission and the transparent flag no longer affect what is fetched. Caching uses Laravel's `file` store rather than Redis, plus a `commons_category_lookups` table. For current behaviour see section 12, Phase 2; the design is in [`docs/superpowers/specs/2026-08-31-commons-category-retrieval-design.md`](docs/superpowers/specs/2026-08-31-commons-category-retrieval-design.md).

### 3.1 API Strategy

- Use Wikimedia Commons / MediaWiki API endpoints (e.g. `action=query` with `generator=search`) to search images.
- Build search query strings like:
  - `"{make} {model} {year} car"` (e.g. `"Toyota Corolla 2020 car"`).
  - Append color term if specified (e.g. `"red"`).
  - For transparent background, prefer categories/tags like `"PNG"` or `"transparent background"` where available.

### 3.2 Service Class

Create a dedicated service, e.g. `App\Services\Images\WikimediaClient`:

- Config-driven base URL and timeouts (set in `config/services.php` or `config/images.php`).
- Methods:
  - `searchCars(string $make, ?string $model, int $year, ?string $color, ?string $transmission, bool $transparent, int $limit = 10): Collection`.
  - Internal helpers to:
    - Build search query string.
    - Map API response to a normalized DTO (title, URLs, size, license, attribution).
- Use Laravel HTTP client (`Http::baseUrl(...)`) with:
  - Reasonable timeouts.
  - Retries with backoff on 5xx / network errors.
  - Logging on errors and key fields on success.

### 3.3 Caching

- Cache results per **(make, model, year, color, transmission, transparent)** combination.
- Store both in-memory cache (Redis) and DB-level records:
  - Reduces repeated calls when users search for the same data.
  - Facilitate reusing already-fetched images for other searches.

---

## 4. Data Model

Design schema using migrations and Eloquent models. Tentative tables:

### 4.1 `car_searches` table

- `id`
- `make` (string)
- `model` (nullable string)
- `from_year` (integer)
- `to_year` (integer)
- `color` (nullable string)
- `transmission` (nullable string)
- `transparent_background` (boolean)
- `images_per_year` (integer, default 10)
- `status` (enum or string: `pending`, `running`, `completed`, `failed`)
- `requested_by` (foreign key to `users`)
- `created_at`, `updated_at`

Purpose:
- Track each user-initiated search from Filament.
- Allow reviewing search parameters and status later.

### 4.2 `car_images` table

- `id`
- `car_search_id` (nullable FK) – the search that produced this image.
- `make`, `model`, `year` – denormalized for quick filtering.
- `color` (nullable)
- `transmission` (nullable)
- `transparent_background` (boolean)
- `provider` (string, e.g. `wikimedia`)
- `provider_image_id` / `page_id` (string/int)
- `title` (string)
- `description` (text, nullable)
- `source_url` (string) – original Wikimedia image URL.
- `thumbnail_url` (string, nullable)
- `width`, `height` (integers, nullable)
- `license` (string, nullable)
- `attribution` (text, nullable)
- `download_status` (enum/string: `not_downloaded`, `queued`, `downloading`, `downloaded`, `failed`)
- `download_path` (nullable string) – storage path if downloaded.
- `metadata` (json, nullable) – raw provider metadata for debugging.
- Timestamps.

### 4.3 Relationships

- `CarSearch` hasMany `CarImage`.
- `CarImage` belongsTo `CarSearch`.
- `User` hasMany `CarSearch`.
 - `CarMake` hasMany `CarModel`.
 - `CarModel` belongsTo `CarMake`.

This structure lets you:
- Track who ran which search.
- See images across searches.
- Reuse or re-download specific images.

### 4.4 `car_makes` table

- `id`
- `name` (unique string) – canonical name of the make (e.g. `Toyota`, `Ford`).
- Timestamps.

### 4.5 `car_models` table

- `id`
- `car_make_id` (FK to `car_makes`).
- `name` (string) – model name (e.g. `Corolla`, `Explorer`).
- Unique constraint on (`car_make_id`, `name`).
- Timestamps.

---

## 5. Search Flow (FROM YEAR / TO YEAR)

> **As built:** steps 3–5 never shipped as written. The search runs synchronously in the request that submits the form, and `RunCarSearchJob` and `FetchWikimediaCarImagesForYearJob` are never dispatched. Step 5's retrieval and cache key were replaced by category reads (see the note at the top of section 3). Because the category is resolved from the model, a search with no model returns no images. See section 12, Phases 2–3.

1. User opens **Filament page** "Car Image Search".
2. User fills in:
   - Make (required)
   - Model (optional)
   - FROM YEAR (required)
   - TO YEAR (required)
   - Color (optional)
   - Transmission (optional)
   - Transparent background (boolean)
   - Images per year (default 10)
3. On submit:
   - Validate input (year range, make, etc.).
   - Create a `CarSearch` record with `status = pending`.
   - Dispatch a job `RunCarSearchJob` with this `CarSearch` ID.
4. `RunCarSearchJob`:
   - Update `status` to `running`.
   - Loop `year` from `from_year` to `to_year`:
     - Dispatch `FetchWikimediaCarImagesForYearJob` for each year.
   - Option 1: Wait until all year-jobs complete using job chaining/batching and then mark `CarSearch` as `completed`.
   - Option 2: Mark `CarSearch` as `running` and let year-jobs individually update status when done; `completed` when all are done.
5. `FetchWikimediaCarImagesForYearJob`:
   - Check cache for `(make, model, year, color, transmission, transparent)`.
   - If cached, hydrate `CarImage` records from cache.
   - Otherwise, call `WikimediaClient::searchCars(...)` with `limit = images_per_year`.
   - Normalize and upsert `CarImage` records.
   - Optionally, schedule `DownloadCarImagesJob` if auto-download is enabled.

---

## 6. Download Flow

1. From Filament tables, user can select individual or multiple images.
2. Provide a **bulk action** "Download selected" that streams a ZIP file of the selected images directly to the admin's browser:
   - Prefer already-downloaded files on the `cars` disk when `download_path` is set.
   - Otherwise fetch each image from `source_url` using the configured Wikimedia user agent.
   - Update `download_status` to `downloaded` for successfully included images.
3. Keep `DownloadCarImagesJob` available for future background workflows that download images to the `cars` disk without immediately sending them to the browser:
   - Uses `Storage::disk('cars')` (configured in `config/filesystems.php`).
   - Downloads the image from `source_url`.
   - Saves as a deterministic path, e.g. `make/model/year/{hash}.jpg`.
   - Updates `download_status` and `download_path`.
   - Handles HTTP errors, timeouts, and disk failures with retries.
4. Optionally add another bulk action:
   - "Export URLs" to CSV with columns: make, model, year, license, attribution, source URL, local path.

---

## 7. Filament Admin Design

### 7.1 Resources / Pages

1. **CarSearchResource**
   - Table columns: make, model, color, transmission, from_year, to_year, status, created_at. When model, color, or transmission are `null` (no filter), the UI displays them as `All` so it is obvious that the search is not restricted on that field.
   - Actions:
     - Edit search: update parameters and, on save, clear existing images and re-run the search with the new filters.
     - View details: show parameters and related `CarImage` records.
     - Refresh from Wikimedia: delete existing images for the search, clear cached Wikimedia responses for its years, and re-run the search with the latest filters.

2. **CarImageResource**
   - Table columns:
     - Thumbnail (using Filament image column).
     - Make, model, year.
     - Color, transmission.
     - Provider, license.
     - Download status (badge), created_at.
   - Filters:
     - By make, model, year range.
     - By color.
     - By transmission.
     - By download status.
   - Bulk actions:
     - Delete selected images.
     - Download selected (streams selected images as a ZIP archive to the admin's browser).
     - Export selected. *(Not on this resource: CSV export shipped on the Results page instead; see section 12, Phase 5.)*
   - Row actions:
     - Preview image in a modal (larger thumbnail with metadata and source link).
     - Download single image immediately via an internal download endpoint that streams the provider image to the browser.

3. **CarMakeResource**
   - Admin resource for managing car makes and their models.
   - Uses a simple form with a make name and a repeater field to add multiple models under that make.
   - Once populated, this becomes the primary source for make/model suggestions in the Car Image Search form.

4. **UserResource**
   - Admin resource for managing Filament users (admin accounts).
   - Simple form with name, unique email, and password. Password is required on create and optional on edit; when changed, it is hashed automatically via the model cast.

5. **Custom Filament Page: "Car Image Search"**
   - A form-driven page (outside the standard resource CRUD) optimised for triggering new searches.
   - Fields mirror the search parameters.
   - On submit, create `CarSearch` and dispatch `RunCarSearchJob`. *(As built: the `CarSearch` Create page is this form, and it runs the search synchronously. No job is dispatched.)*
   - Show a panel with recent searches and their status.

### 7.2 Authorization

- Restrict the entire panel to authenticated staff (default Filament auth).
- Optionally add a role/permission system:
  - Only admins can trigger new searches or downloads.
  - Read-only users can only browse images.

---

## 8. Configuration & Environment

- Add config entries to `config/services.php` or a dedicated `config/images.php`:
  - `wikimedia.base_url`
  - `wikimedia.timeout`
  - `wikimedia.max_images_per_year` (default 10)
  - `wikimedia.user_agent` (to identify your app politely).
- `.env` keys:
  - `WIKIMEDIA_BASE_URL=https://commons.wikimedia.org/w/api.php`
  - Timeouts, rate-limit tuning if needed.
- Storage:
  - Define a `cars` disk in `config/filesystems.php` with environment-based root/bucket.

---

## 9. Rate Limiting, Error Handling, and Observability

- Implement a simple **rate limiter** per provider using Laravel's `RateLimiter` facade or cache-based counters.
- Apply **retry with backoff** (e.g. 3 attempts, exponential delay) for network issues.
- Log:
  - Executed Wikimedia queries (without flooding logs).
  - Errors from the provider (HTTP code, message, query parameters).
- Add monitoring:
  - Filament dashboard widgets for number of images fetched, number of downloads, failed jobs.

---

## 10. Testing Strategy

1. **Unit tests**
   - Test `WikimediaClient` query building and response mapping.
   - Use `Http::fake()` to simulate Wikimedia API responses.

2. **Feature tests**
   - Test Filament search page flow (form submission creates `CarSearch`, dispatches jobs).
   - Test that `car_images` records are created from fake API responses.
   - Test download job writes files and updates `download_status` / `download_path`.

3. **Integration tests (optional)**
   - A small number of tests that hit the real Wikimedia API (guarded by env flag) to verify assumptions about the API.

---

## 11. Implementation Phases

The original six phases, kept as written. Their status, and the work that shipped outside them, is in section 12.

1. **Phase 1 – Foundation**
   - Ensure Laravel / Filament setup is complete.
   - Add DB migrations and Eloquent models for `CarSearch` and `CarImage`.
   - Configure `cars` filesystem disk.

2. **Phase 2 – Wikimedia Client & Services**
   - Implement `WikimediaClient` with query building and response normalization.
   - Implement `CarImageSearchService` coordinating multi-year searches.

3. **Phase 3 – Jobs & Queues**
   - Implement jobs: `RunCarSearchJob`, `FetchWikimediaCarImagesForYearJob`, `DownloadCarImagesJob`.
   - Configure queue worker and test with small ranges.

4. **Phase 4 – Filament Admin UI**
   - Create `CarSearchResource`, `CarImageResource`.
   - Create custom "Car Image Search" page with form and status view.

5. **Phase 5 – Download & Export**
   - Implement bulk download and export actions in Filament.
   - Ensure files are accessible to users with proper links / signed URLs.

6. **Phase 6 – Hardening**
   - Add validations, rate limiting, detailed logging, and error screens in Filament.
   - Write and run tests, prepare documentation for employers on how to use the system.

---

## 12. Current Implementation Status

_Checked against the code on 2026-10-20, at `main` `699d513`. Versions come from `composer.lock` and `mobile/package-lock.json`; test counts come from running both suites._

**Stack:** Laravel 13.36, Filament 5.10 (Livewire 4.4), Sanctum 4.3 and PHPUnit 12.5, on PHP 8.3+. The mobile app uses Expo SDK 57, React Native 0.86 and React 19.2. The upgrade from Laravel 12 / Filament 4 is recorded in [`docs/upgrades/2026-08-24-laravel-13-filament-5-upgrade.md`](docs/upgrades/2026-08-24-laravel-13-filament-5-upgrade.md).

- **Phase 1 – Foundation**
  - Complete. The `car_searches` and `car_images` tables and models exist, and the `cars` disk is configured (local driver, `storage/app/public/cars`).
  - The schema has outgrown section 4. It adds the `csv_imports`, `wikimedia_block_events`, `error_events`, `commons_category_lookups` and `personal_access_tokens` tables, `csv_import_id` and `commons_category` on searches, and `make_confirmed`, `year_confirmed` and `review_status` on images.

- **Phase 2 – Wikimedia Client & Services**
  - Complete, but retrieval no longer works the way section 3 describes. Since 2026-08-31 a search reads a Commons category instead of running a full-text search ([spec](docs/superpowers/specs/2026-08-31-commons-category-retrieval-design.md)):
    - `CommonsCategoryLocator` resolves make + model to one category through `CommonsCategoryResolver`, which normalizes the model string with `ModelSearchTermNormalizer` first. The answer is stored in `commons_category_lookups`, and a failed lookup is retried after 30 days.
    - `WikimediaClient::filesInCategory()` pages through the category (at most 500 files, with the year passed as a relevance hint), drops anything whose MIME type is not `image/*`, and caches the listing per category and year.
    - `CarImageSearchService` keeps only files whose title names the searched year (`ModelYearMatcher`), takes `images_per_year` of them, and upserts them keyed on `(car_search_id, year, provider, provider_image_id)`. `MakeRelevanceChecker::isConfirmed()` fills `make_confirmed`.
  - Color, transmission and the transparent flag are still stored on the search but no longer reach Wikimedia. A search with no model resolves no category and returns no images.
  - Removed along with full-text search (commit `4b0996b`): query building, the `isCarImage()` keyword filter, off-make rejection, and the retry-without-year fallback.
  - Still in place: `findExistingCompletedSearch()` reuses an identical completed search. Requests send a contactable User-Agent and `maxlag`, retry with exponential backoff, and raise `WikimediaBlockedException` on 429, 403 or 503.

- **Phase 3 – Jobs & Queues**
  - Not wired. `RunCarSearchJob`, `FetchWikimediaCarImagesForYearJob` and `DownloadCarImagesJob` exist in `app/Jobs/`, but nothing dispatches them and no test covers them. `RunCarSearchJob` would call `runSearch()` directly rather than fan out one job per year as section 5 planned.
  - Production is shared hosting with `QUEUE_CONNECTION=sync` and no worker or cron, so all work runs inside web requests and is bounded by caps in `config/cars-images.php`:
    - Ad-hoc searches run synchronously on create, on edit, and from **Refresh from Wikimedia**.
    - A CSV bulk run is started by a click on **Search Queries** and then driven by Livewire polling. Each poll runs one chunk of at most 50 queries or about 10 seconds, pausing 1 second between queries. `POST /api/v1/searches/run-chunk` runs the same chunk for the mobile app.
    - `POST /api/v1/searches` runs inline, capped at a 3-year span and 5 images per year.
  - Moving this work onto a queue worker is on the roadmap.

- **Phase 4 – Filament Admin UI**
  - Complete, and extended beyond section 7.
  - `CarSearchResource` handles ad-hoc searches. Its Create page is the "Car Image Search" form, with:
    - linked make and model selects
    - a year range, normalized if entered in reverse
    - **All ...** options for model, color and transmission, stored as `null` and shown as `All` in both tables
    - a transparent flag and an images-per-year field
  - Submitting the form reuses an identical completed search or runs a new one synchronously. Editing a search re-runs it with the new filters. The View page's **Refresh from Wikimedia** clears that search's cached category listings, then deletes and refetches its images in one transaction.
  - `CarImageResource` and a search's images relation manager offer thumbnails, filters, a preview modal, single download, **Download selected** as a ZIP, and delete. Row actions sit in one dropdown on every image table.
  - `CarMakeResource` maintains the makes and models behind the search dropdowns, and `UserResource` manages admin accounts.
  - The CSV pipeline adds `CsvImportResource` (Upload CSV), `SearchQueryResource` (imported searches, with a Coverage filter and Run / Run Selected / Run all pending) and the `Results` page (coverage panel, make/year/review badges, exports). See [README → CSV bulk pipeline](README.md#csv-bulk-pipeline).
  - `ErrorEventResource` shows the pipeline error log. Four landing-page widgets show pipeline health, search throughput, errors by context and the latest failures.
  - Car Searches and Car Images tables default to 100 rows per page.

- **Phase 5 – Download & Export**
  - Mostly complete, except that nothing is saved to disk yet.
  - Single image: `GET /car-images/{carImage}/download` (authenticated) streams the provider image and marks it `downloaded`.
  - **Download selected** on Car Images and on a search's images builds a ZIP through `CarImageZipService`.
  - The Results page offers **Download Selected as ZIP**, **Download Confirmed as ZIP** and **Export Selected as CSV**. ZIP images are resized locally with GD (1600 px wide, JPEG quality 82) and given deterministic filenames, at most 100 per archive. The CSV's `Filename` column matches the ZIP entries.
  - Signed URLs exist for the API only. `POST /api/v1/exports` mints a signed link to `/exports/download` that expires after 5 minutes, and a ZIP link works only once. Panel downloads stream to the logged-in browser and need no signed link.
  - Not done: nothing writes to the `cars` disk. Only `DownloadCarImagesJob` sets `download_path`, and it is never dispatched, so `CarImageZipService`'s use-the-saved-copy branch never runs in practice. The CSV's columns (Year, Make, Model, Transmission, Filename, SourceUrl, SearchId, ImageId) also differ from the license / attribution / local-path set that section 6 proposed.

- **Phase 6 – Hardening**
  - Largely complete. The gaps: there are no roles, and requests to Wikimedia are paced rather than rate-limited.
  - Validation: every API entry point has a Form Request. CSV uploads are checked for the required columns, plausible years, file size (5 MB), unique queries (1,000) and projected image downloads (5,000).
  - Rate limiting:
    - Every `/api/v1` route names its own throttle: 5/min for login; 10/min for search, run-chunk, import and export; 60/min for the other writes; 120/min for reads.
    - Toward Wikimedia there is no `RateLimiter` as section 9 proposed. Instead, a 1-second pause between bulk queries, `maxlag`, retries with backoff and response caching keep the request rate polite. A block halts the bulk run and is recorded in `wikimedia_block_events`.
  - Logging and errors: `ErrorEventLogger` writes `error_events`, kept for 30 days and capped at 500 per import. Old events are pruned by hand with `error-events:prune`, because there is no cron. The Error Events resource and the health widgets display them, and a Wikimedia block raises a persistent notification. There are no custom error pages.
  - Authorization: any authenticated user can use the panel, because the three policies allow every authenticated user. The role split in section 7.2 has not been built. API tokens carry scoped Sanctum abilities: `search:read`, `search:write`, `search:run`, `review:write`, `imports:read`, `imports:write`, `exports:read` and `errors:read`.
  - Tests: 363 PHPUnit tests in 57 files (7 unit, 50 feature) and 182 Jest tests in 31 suites, all passing on 2026-10-20. `ContractFixturesTest` generates the JSON fixtures in `mobile/src/api/__fixtures__/` that the app's Zod schemas are tested against, so renaming an API field fails both suites.
  - CI/CD:
    - [`ci-cd.yml`](.github/workflows/ci-cd.yml) validates `composer.json`, checks platform requirements, and runs Pint, PHPUnit and `composer audit`. When those pass on `main`, it deploys to SiteGround; pushes that touch only docs skip the deploy.
    - [`mobile.yml`](.github/workflows/mobile.yml) typechecks, lints, runs Jest, exports the web build, checks the Netlify rewrites, and publishes to Netlify from `main`.
  - Documentation: `README.md`, `DEPLOYMENT.md`, [`docs/netlify-deploy.md`](docs/netlify-deploy.md), and a spec and plan for each feature under [`docs/superpowers/`](docs/superpowers/).

- **Shipped outside the original phases**
  - The CSV bulk pipeline: import a vehicle list, run its searches in chunks, review the results and export them.
  - A Docker stack that mirrors the SiteGround host.
  - The `/api/v1` JSON API and the Expo app (0.10.0, 2026-09-09), since extended with CSV imports, bulk runs, signed exports, the make catalogue and health series. See section 2.
  - Continuous deployment to SiteGround and Netlify.
  - A human review verdict (`review_status`), recorded alongside the machine's `make_confirmed` and `year_confirmed`.

- **Still open** (matches [README → Roadmap](README.md#roadmap))
  - Run bulk search and bulk download on a queue worker, which would lift the synchronous caps.
  - Save downloads and exports to the `cars` disk.
  - Build the Android APK from a tagged release.
  - AI-assisted filtering (section 13).

---

## 13. Optional AI-based filtering (future)

> **Note (2026-10-20):** `WikimediaClient::isCarImage()` and its keyword filter were removed on 2026-08-31 along with full-text search, because reading by category made them unnecessary. What remains is a MIME check, the exact-year title match and the `make_confirmed` flag. The pattern below predates that change.

- **When OpenAI might make sense**
  - Consider integrating OpenAI (or a similar model) only if:
    - You still get many wrong images even after improving keyword filters.
    - Or you need very high precision (for example: "front 3/4 view, no people, white background").

- **Pattern for using AI in `WikimediaClient::isCarImage()`**
  - First run cheap keyword-based filters as the primary decision mechanism.
  - For "ambiguous" cases, optionally send **title + description + categories** to an AI model to classify as **car / not car**.
  - Cache AI decisions aggressively (for example, per `provider_image_id`) to avoid paying repeatedly for the same image.
  - Do not hard-fail the entire search if the AI provider is down; fall back to heuristic-only filtering.

- **Best practices if you add OpenAI**
  - Put the API key in `.env` (for example `OPENAI_API_KEY`), never in source code.
  - Wrap calls in a dedicated service class, for example `App\Services\Images\ImageClassifier`.
  - Add timeouts, retries, and logging around AI calls.
  - Only send text metadata (title, description, categories) unless you explicitly need vision models, which are slower and more expensive.

