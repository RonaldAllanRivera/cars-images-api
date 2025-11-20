# PLAN: Cars Images API – Wikimedia + Laravel Filament

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

7. **Optional HTTP API**
   - Expose internal endpoints (e.g. `/api/car-images/search`) for integration with other internal tools.

---

## 3. Wikimedia Integration Design

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
  - `searchCars(string $make, ?string $model, int $year, ?string $color, bool $transparent, int $limit = 10): Collection`.
  - Internal helpers to:
    - Build search query string.
    - Map API response to a normalized DTO (title, URLs, size, license, attribution).
- Use Laravel HTTP client (`Http::baseUrl(...)`) with:
  - Reasonable timeouts.
  - Retries with backoff on 5xx / network errors.
  - Logging on errors and key fields on success.

### 3.3 Caching

- Cache results per **(make, model, year, color, transparent)** combination.
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

This structure lets you:
- Track who ran which search.
- See images across searches.
- Reuse or re-download specific images.

---

## 5. Search Flow (FROM YEAR / TO YEAR)

1. User opens **Filament page** "Car Image Search".
2. User fills in:
   - Make (required)
   - Model (optional)
   - FROM YEAR (required)
   - TO YEAR (required)
   - Color (optional)
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
   - Check cache for `(make, model, year, color, transparent)`.
   - If cached, hydrate `CarImage` records from cache.
   - Otherwise, call `WikimediaClient::searchCars(...)` with `limit = images_per_year`.
   - Normalize and upsert `CarImage` records.
   - Optionally, schedule `DownloadCarImagesJob` if auto-download is enabled.

---

## 6. Download Flow

1. From Filament tables, user can select individual or multiple images.
2. Provide a **bulk action** "Download selected".
3. The action dispatches `DownloadCarImagesJob` (for one or many image IDs).
4. `DownloadCarImagesJob`:
   - Uses `Storage::disk('cars')` (configured in `config/filesystems.php`).
   - Downloads the image from `source_url`.
   - Saves as a deterministic path, e.g. `make/model/year/{hash}.jpg`.
   - Updates `download_status` and `download_path`.
   - Handles HTTP errors, timeouts, and disk failures with retries.
5. Optionally add another bulk action:
   - "Export URLs" to CSV with columns: make, model, year, license, attribution, source URL, local path.

---

## 7. Filament Admin Design

### 7.1 Resources / Pages

1. **CarSearchResource**
   - Table columns: make, model, from_year, to_year, color, transparent, status, requested_by, created_at.
   - Actions:
     - View details: show parameters and related `CarImage` records.
     - Re-run search (dispatch `RunCarSearchJob` again).

2. **CarImageResource**
   - Table columns:
     - Thumbnail (using Filament image column).
     - Make, model, year.
     - Color, transparent flag.
     - Provider, license.
     - Download status (badge), created_at.
   - Filters:
     - By make, model, year range.
     - By color.
     - By download status.
   - Bulk actions:
     - Download selected.
     - Export selected.

3. **Custom Filament Page: "Car Image Search"**
   - A form-driven page (outside the standard resource CRUD) optimised for triggering new searches.
   - Fields mirror the search parameters.
   - On submit, create `CarSearch` and dispatch `RunCarSearchJob`.
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
