# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

- Implement image download job and bulk download/export actions in Filament.
- Add rate limiting, detailed logging/metrics, and automated tests.

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
