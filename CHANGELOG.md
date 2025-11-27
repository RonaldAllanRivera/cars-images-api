# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Planned

- Implement image download job wiring and bulk download/export actions in Filament.
- Add rate limiting, detailed logging/metrics, and automated tests.

## [0.4.2] - 2025-11-27

### Added

- `DEPLOYMENT.md` with a detailed SiteGround deployment guide for the `cars-search.artworkwebsite.com` subdomain, including recommended directory layout, cloning from GitHub, environment configuration, and troubleshooting.

### Changed

- Documented SiteGround-specific `.htaccess` options for serving Laravel from `public/`, including an explicit rewrite rule for `/livewire/livewire.js` to avoid 404/403 errors that break the Filament login on shared hosting.
- Updated the `User` model to implement `FilamentUser` and define `canAccessPanel()`, ensuring authenticated users can access the Filament admin panel in production instead of seeing `403 | FORBIDDEN` after login.

## [0.4.1] - 2025-11-25

### Added

- Ability to "search again" by editing an existing Car Search in Filament; after saving updated filters, previous images are cleared and the Wikimedia search is re-run with the new parameters.
 - `CarMakeResource` admin pages for managing car makes and their models in one place, backed by new `car_makes` and `car_models` tables.
 - `CarMakeSeeder` to pre-populate the catalog with common makes and models used by the Car Image Search dropdowns.
 - `UserResource` admin pages for listing, creating, and editing Filament users (admin accounts) with name, unique email, and password.

### Changed

- Car Searches and Car Images UIs now treat missing model/color/transmission filters as **All** – tables render `All` instead of blank for `null` values, and the Car Search form hydrates **All ...** options when editing or viewing existing searches so dropdowns are never empty.
 - `CarSearchResource` now prefers make/model options from the `car_makes` / `car_models` tables (falling back to static arrays when empty) so the search form uses the curated catalog.
 - The `0001_01_01_000005_alter_car_images_url_columns` migration `down()` method is now a no-op, avoiding data truncation errors when rolling back or refreshing migrations with long Wikimedia URLs stored in `title`, `source_url`, or `thumbnail_url`.

## [0.4.0] - 2025-11-24

### Added

- Bulk **Download selected** ZIP action for car images (global Car Images listing and per-search Images relation) that streams the selected images as a single ZIP archive to the admin's browser.

### Changed

- Bulk ZIP download filenames now include the image ID so multiple images with the same make/model/year do not overwrite each other inside the archive.

## [0.3.0] - 2025-11-24

### Added

- "All models", "All colors", and "All transmissions" options on the Car Image Search form so users can easily widen or narrow searches.
- Per-row and bulk **Delete** actions for car images in Filament, both on the global **Car Images** listing and on each search's **Images** relation.
- **Refresh from Wikimedia** header action on the Car Search view page that deletes existing images for the search, clears cached Wikimedia responses for its years, and re-runs the search with the latest filtering rules.
- Documentation for optional AI-based filtering of ambiguous results, and for environment configuration via `.env.example`.
- Image preview modal for car images (clickable thumbnails and Preview action) with larger 400px image, source URL, and title, plus a Download button that streams the image via an internal download endpoint using the configured Wikimedia user agent.
- `download_status` badges for car images with distinct colors (`downloaded`/success, `downloading`/warning, `failed`/danger, default/gray) and 1s polling on the Car Images table and per-search Images relations so download status updates automatically after a successful download.

### Changed

- `WikimediaClient` car-image filter expanded to drop obviously non-car academic/journal pages (e.g. psychology / neuroscience articles) in addition to plant/flower content, based on title, description, and category metadata.
- `PLAN.md` and `README.md` updated to describe the refreshed admin UI (delete actions, Refresh from Wikimedia, All-* options) and the improved filtering and future AI plan.

## [0.2.0] - 2025-11-21

### Added

- Migration to widen `car_images.title`, `source_url`, and `thumbnail_url` columns to `TEXT` to support very long Wikimedia URLs and titles.
- `transmission` column on `car_searches` and corresponding updates to the `CarSearch` model and search service.
- Jobs `FetchWikimediaCarImagesForYearJob` and `DownloadCarImagesJob` as building blocks for future background processing.
- Dynamic **make/model** select fields on the Car Image Search form, with popular makes and models and automatic model options based on the selected make.

### Changed

- Car Image Search form now runs `CarImageSearchService::runSearch()` synchronously from the Filament Create page instead of dispatching a job, simplifying local development.
- Car Image Search form extended with color and transmission filters, year range normalization, and sensible default values.
- `WikimediaClient` search query now includes transmission when provided and filters out obvious non-car images (e.g. flowers / plants) based on title, description, and category metadata.
- Car Searches and Car Images tables now default to **100 rows per page**, with configurable pagination options.

## [0.1.0] - 2025-11-21

### Added

- Initial Laravel 12 project setup with Filament 4 admin panel.
- Database schema and models for `CarSearch` and `CarImage`.
- `cars` filesystem disk for future downloaded images.
- `WikimediaClient` service for MediaWiki image search with caching and normalization.
- `CarImageSearchService` coordinating multi-year car searches and persisting results.
- DB-backed reuse logic for identical completed searches to avoid unnecessary Wikimedia calls.
- `RunCarSearchJob` (currently executed synchronously in local env) to encapsulate search execution.
- Filament resources:
  - `CarSearchResource` with search form, status tracking, and per-search images relation.
  - `CarImageResource` to browse all cached images.
- Configuration for Wikimedia integration in `config/images.php` and corresponding `.env` variables.
