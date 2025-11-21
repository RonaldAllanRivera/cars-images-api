# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

- Implement image download job and bulk download/export actions in Filament.
- Add rate limiting, detailed logging/metrics, and automated tests.

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
