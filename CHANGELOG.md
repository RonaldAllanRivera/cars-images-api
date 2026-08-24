# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`.github/workflows/ci-cd.yml`** — tests on every push and pull request (Pint, PHPUnit, `composer validate --strict`, `check-platform-reqs`, `composer audit`), and an automated SiteGround deploy gated on a green suite. The deploy puts the site in maintenance mode with a `trap ... EXIT` that restores it even if a migration fails, pins the SSH host key rather than disabling host verification, serialises runs with `concurrency`, and finishes with an HTTP 200 smoke check on `/admin/login` so a deploy that breaks the panel fails the run. It stays dormant until the repository variable `DEPLOY_ENABLED` is set to `true`. Setup and required secrets are documented in `DEPLOYMENT.md` §6.1.

### Planned

- Move bulk search and bulk download onto a real queue worker so long runs are not bound by the web request timeout (the `RunCarSearchJob`, `FetchWikimediaCarImagesForYearJob`, and `DownloadCarImagesJob` classes exist as scaffolding but are not dispatched yet).
- Persist downloaded images to the `cars` storage disk instead of streaming them straight to the browser.
- Explore AI-assisted filtering for ambiguous results, replacing the current keyword heuristic (see `PLAN.md`).

## [0.8.2] - 2026-08-24

Three data-integrity defects found by running the application rather than by
reading it. Each fix was written test-first, and each test was confirmed to
fail against the old behaviour before the fix landed.

### Fixed

- **A failed refresh no longer destroys the images it was replacing.** `CarImageSearchService::refreshSearch()` deleted the search's images *outside* the transaction that `runSearch()` opens, so when Wikimedia answered 429 the rollback restored nothing: every reviewed image was gone permanently (`car_images` has no soft deletes), and because the `status = running` update rolled back too, the row still read `completed`. Both entry points were affected — the **Refresh from Wikimedia** action and `EditCarSearch::afterSave()`, which fires on every save whether or not filters changed. The delete and the refetch now share one transaction, and a failure forces the search to `failed` before re-throwing.

- **The Results page no longer loses its scope after the first interaction.** `App\Filament\Pages\Results` resolved `searchId` with `request()->query()` inside the table query closure. Livewire's update requests carry no query string, so the scope vanished on the first paginate, sort, search, or bulk action and the table silently widened to every CSV-imported image in the database — including `DeleteBulkAction`, whose select-all plucks keys from the *filtered* query and therefore deleted unrelated imports. `searchId` is now a `#[Url]` component property, so it survives every round-trip and remains shareable in the address bar.

- **One Wikimedia file can now belong to several searches without theft.** Images were upserted on `(provider, provider_image_id)` alone — a global key — with `car_search_id` and `year` in the *update* payload, so a file returned by two searches was **moved** rather than copied: the later search took the row and relabelled its year, and the earlier search was left empty while still reporting `completed`. Observed on real data: re-importing a CSV gave three new queries nine images while the total image count never changed, and the previous import's three queries dropped to zero. The same collapse happened *within* a multi-year search, because the year-relaxed fallback returns an identical result set for every year of a sparsely-catalogued model, leaving one row labelled with whichever year ran last.

  Ownership is now part of the match key — `(car_search_id, year, provider, provider_image_id)` — and a new migration enforces it as a unique index. `updateOrCreate()` is a SELECT followed by an INSERT and is not atomic, so without the constraint two concurrent bulk-run queries could both miss a row and both insert it. Verified live: the scenario that previously moved 9 images now moves 0, and the image total grows instead of staying flat.

### Added

- `RefreshSearchTest`, `ResultsScopingTest`, `SharedImageOwnershipTest` — 10 tests covering the three defects above. The scoping tests deliberately pass `searchId` as component state rather than via `Livewire::withQueryParams()`, which pins the query string for a component's whole lifetime and is why the existing `ResultsPageTest` could not catch the bug.
- Migration `add_unique_owner_index_to_car_images_table`, enforcing one file per (search, year) at the database level.

## [0.8.1] - 2026-08-24

### Added

- An **edit-path** test for the `__all__` sentinel. The existing create-path test could not fail: `CreateCarSearch::handleRecordCreation()` carries its own `$normalize` closure that masks the loss of the three `dehydrateStateUsing()` callbacks. Deleting all three left the whole suite green. On the *edit* path those callbacks are the only guard, and nothing ever called `save()` on an edit form. The new test is now the single test of 114 that fails when the guard is removed (verified by mutation).

### Fixed

- **A search for one make no longer returns photographs of another.** Querying `Acura 2.2CL/3.0CL 1997` returned four Honda Accords: the model string normalizes to `CL`, and Wikimedia's full-text search matches the Accord's chassis code `CL3`. Eight of nine results for one CSV import were the wrong car. They were correctly flagged `make_confirmed = false`, but flagging is no help when the entire result set is wrong.

  `MakeRelevanceChecker::isOffMake()` now rejects an image when it names a known manufacturer other than the searched one and does not name the searched one, and `CarImageSearchService` drops those before they are ever stored. The rule is deliberately asymmetric:

  - searched make named anywhere → **keep** (this preserves badge engineering, e.g. a page titled `Honda Accord (Acura CL)`, which really is the searched car)
  - a different known make named → **reject**
  - no make named at all → **keep**, and leave `make_confirmed = false` for review, because absence of evidence is not evidence of a wrong car

  Matching is on whole words, so `Fordson` is not a Ford. The manufacturer list comes from the `car_makes` catalogue, with a built-in fallback so the filter still works on an unseeded database. Verified live: the same three imported queries went from 9 images (8 wrong) to 1 image — the genuine Acura CL-X.

  **Trade-off:** this favours precision over recall. Two of the three Acura queries now return nothing rather than returning Hondas. Improving recall for sparsely-catalogued models is separate work.

## [0.8.0] - 2026-08-24

Dependency upgrade to the current release of every major dependency, executed in
verified stages with a test gate between each. See
`docs/upgrades/2026-08-24-laravel-13-filament-5-upgrade.md`.

### Changed

- **Laravel 12.61 → 13.26.1.** The one high-impact item in the upgrade guide applied here: `VerifyCsrfToken` is renamed to `PreventRequestForgery` and now performs `Sec-Fetch-Site` origin verification. `AdminPanelProvider` registered the old class directly in the panel middleware stack; it now registers `PreventRequestForgery`. Verified over real HTTP, because Laravel's CSRF middleware short-circuits on `runningUnitTests()` and therefore cannot be exercised by the test suite.
- **Filament 4.11.6 → 5.7.6** and **Livewire 3.8 → 4.4.1**. The deprecated `->actions()` / `->bulkActions()` table builders were migrated to `->recordActions()` / `->toolbarActions()` **first, while still on Filament 4**, where both spellings work and the existing suite could verify the change. As a result the official `filament-v5` upgrade script found nothing left to rewrite.
- **PHPUnit 11 → 12.5.33**, **Tinker 2 → 3.0.2**, Pint 1.30.5, and every transitive dependency to its current release.
- `composer.json` now declares its real platform requirements: `php ^8.3` (the true floor, set by Laravel 13 and by Filament's `openspout` dependency) plus `ext-gd`, `ext-intl`, `ext-pdo`, `ext-zip`, and `ext-pdo_sqlite` for development. Previously it claimed `^8.2` and declared no extensions, so an incompatible host failed with a dependency-resolution error instead of a clear platform error.

### Fixed

- **All outstanding security advisories cleared.** Guzzle 7.10.5 → 8.0.2 (non-canonical host bypass; Laravel 13's `illuminate/http` permits the 8.x line), CommonMark 2.8.2 → 2.10.0 (four denial-of-service advisories), PSR-7 2.10.4 → 3.0.0 (pulled up by Guzzle 8), Symfony Mime → 7.4.17. Syncing `vendor/` to the lock also cleared 29 Filament advisories, including the `ImageColumn` XSS advisory — which matters here because the Results page renders `ImageColumn` from external Wikimedia URLs.
- **PHP limits in the Docker image contradicted the application's own settings** (new `docker/php/php.ini`). `upload_max_filesize` was 2 MB while the CSV upload form advertises 5 MB, so larger uploads were rejected by PHP before Laravel saw them. `memory_limit` was 128 MB against bulk ZIPs that GD-resize up to 100 images — this crashed the test suite outright once Filament 5 raised the baseline footprint. `max_execution_time` was below the app's own 50-second bulk-run cap, making that cap unreachable.
- **Deployment rewrite rule corrected for Livewire 4** (`DEPLOYMENT.md`). Livewire 4 serves every route from an `APP_KEY`-derived prefix (`/livewire-c3b9adb8/...`) rather than the fixed `/livewire/`. The documented SiteGround `.htaccess` rule hardcoded `/livewire/livewire.js` — the exact rule that exists to stop the Filament login breaking on shared hosting — and is now a pattern that matches both layouts. Confirmed empirically that the prefix changes with `APP_KEY`, so it can never be hardcoded.
- **`.env.example` was unusable.** Every value was an empty placeholder (`APP_ENV="" # Provide a value...`), breaking the documented setup path twice over: `php artisan key:generate` produced a corrupt key (Laravel's replacement pattern matched only the `APP_KEY=` prefix, leaving the stray `""` appended), and `DB_CONNECTION=""` resolved to an empty string rather than the config default, so `php artisan migrate` failed with `Database connection [] not configured`. Rewritten with working defaults and a placeholder contact address in place of a personal email.
- **Test suite no longer sleeps for 30 seconds.** `phpunit.xml` inherited the real `.env` retry backoff (5 retries, 2000 ms exponential base), so one failure-path test slept 2+4+8+16 seconds. Retry counts are now pinned for tests. Suite runtime went from 34.6s to ~4s.

### Added

- `TableActionsTest` — pins the registered record and bulk actions on all five tables that used the deprecated builders. `PanelSmokeTest` proves a page still renders; this proves it still has its buttons. Both were confirmed to fail under mutation before being relied on.
- `Http::preventStrayRequests()` in the base `TestCase`, so no test can silently make a real network call to Wikimedia.
- A fixed throwaway `APP_KEY` in `phpunit.xml`, so the suite no longer depends on an untracked `.env` and will not fail in CI with `MissingAppKeyException`. (An earlier draft of this entry also claimed the Docker image was missing `pdo_sqlite`; that was wrong — `php:8.3-apache` compiles it in statically. Only the *host* PHP used for ad-hoc runs lacks it.)
- `CsvUploadFlowTest` — drives a real CSV through the Filament upload form to `handleRecordCreation()` and on into persisted queries; the importer had unit coverage but the form seam had none.
- `PanelSmokeTest` (16 tests mounting every page in the panel) and `CarSearchFormTest` (the `__all__` sentinel round-trip, year-range normalization, completed-search reuse) — written before the upgrade as its safety net, taking the suite from 75 to 95 tests.
- `docs/upgrades/2026-08-24-laravel-13-filament-5-upgrade.md` — the staged upgrade design: compatibility matrix, per-stage gates, rollback procedure, and manual QA checklist.
- Tuned `docker/php/php.ini`, mounted by Docker Compose.

## [0.7.0] - 2026-06-02

### Added

- **Make-relevance flagging.** `MakeRelevanceChecker` records, per image, whether the searched make actually appears in the image's title, description, or categories. Stored on `car_images.make_confirmed` (new migration), surfaced on the Results page as a **Make match** badge with a matching filter.
- **Download Confirmed as ZIP** bulk action on the Results page, which narrows the selection to make-confirmed images before building the archive and warns instead of failing when the selection contains none.
- `CARS_DOWNLOAD_MAX_WIDTH` and `CARS_DOWNLOAD_JPEG_QUALITY` configuration for bulk-download image sizing.

### Changed

- **Bulk ZIP images are now resized on this server** (GD, default max width 1600px, re-encoded as JPEG) instead of being served at full Wikimedia resolution — typically an ~85% size reduction.
- Replaced the earlier Wikimedia-thumbnail-CDN approach with the server-side resize above: Wikimedia returns HTTP 400 for on-demand `/thumb/` generation from datacenter and shared-hosting IPs, so CDN thumbnails are not reachable from production. `WikimediaThumbnailUrlBuilder` was removed as a result.
- Bulk ZIP and CSV downloads are served directly from the Filament Results action as Livewire download responses, removing the dedicated download routes and controller indirection.
- Updated dependencies to the latest Laravel 12.x-compatible releases.

### Fixed

- Filament's compiled assets (`public/{css,js,fonts}/filament`) are no longer tracked in Git; they are regenerated by `php artisan filament:upgrade` on every `composer install` and caused pull conflicts on deploy.
- Removed a redundant, empty `.env-sample` that duplicated `.env.example`.

## [0.6.0] - 2026-05-21

### Added

- **CSV bulk search and download pipeline** — a three-page workflow under the **Cars** navigation group:
  - **Upload CSV** (`CsvImportResource`): parses `Make, Model, Year, Transmission`, deduplicates by `(Year, Make, Model)`, skips invalid rows, and rejects uploads above `CSV_IMPORT_MAX_COMBOS` rather than importing a partial set.
  - **Search Queries** (`SearchQueryResource`): review imported queries and run them one at a time or in capped bulk batches, with live polling and per-row status.
  - **Results** (`Results` page): browse harvested images and export them.
- **Bulk downloads**: `BatchZipBuilder` (renamed entries, duplicate suffixes, per-image failures skipped rather than aborting the archive) and `BatchCsvExporter` (manifest whose filenames match the ZIP exactly).
- `FilenameBuilder` service producing deterministic, filesystem-safe `YEAR MAKE MODEL.ext` names with rank and collision suffixes.
- **Wikimedia rate-limit etiquette and block handling**: descriptive contactable User-Agent, `maxlag=5`, 24-hour cache, a one-second pause between bulk queries, and exponential retry backoff. A 429/403/503 raises a typed `WikimediaBlockedException`, is written to the new `wikimedia_block_events` table, and pauses the bulk loop instead of hammering the API.
- `RunSearchQueryAction`, which wraps a single search run and guarantees the row lands on `completed` or `failed` (forcing the status past the rolled-back transaction on failure).
- New `csv_imports` and `wikimedia_block_events` tables plus a `csv_import_id` foreign key on `car_searches`.
- Dedicated `config/cars-images.php` for import caps, bulk-run pacing, and download limits.
- **Automated test suite** covering the importer, filename building, ZIP/CSV export, block handling, resource scoping, and the Results page actions.
- Docker: dev OPcache configuration to prevent stale cached bytecode.

### Changed

- **Model strings from CSVs are normalized before querying Wikimedia** (`ModelSearchTermNormalizer`): vehicle CSVs encode models as engine displacement plus trim (`2.2CL/3.0CL`), while Wikimedia catalogues the bare model (`CL`).
- **Transmission is excluded from the Wikimedia query for CSV-imported searches** — image pages never mention `Automatic 4-spd`, so including it returned zero results. It is retained as manifest metadata, and ad-hoc searches keep the old behaviour.
- **Year-relaxation fallback**: when a year-specific search returns nothing, the query is retried once without the year, so sparse models still return results.
- Filament resources are scoped correctly for the two workflows — `CarSearchResource` shows only ad-hoc searches, `SearchQueryResource` only CSV-imported ones.
- Single-image downloads use `FilenameBuilder` with a deterministic rank suffix instead of ad-hoc names.
- CSV uploads now also accept the `text/plain` MIME type, which is what some browsers send for `.csv` files.

### Fixed

- Non-image files (PDF, DjVu) returned by Wikimedia's `File:` namespace are filtered out by MIME type.
- Binary image fetches for the ZIP send the same descriptive User-Agent as the API calls; `upload.wikimedia.org` rejects requests without one.
- A ZIP where every image fetch failed no longer produces an unreadable zero-entry download — the action reports the failure instead.
- The bulk ZIP is capped at `CARS_BULK_DOWNLOAD_MAX_IMAGES` (default 100) because it is built synchronously inside one web request.
- The single-image download route now requires authentication.

## [0.5.0] - 2026-05-06

### Added

- **Local Docker development stack with SiteGround parity** — Apache + mod_php + MySQL 8, `file` cache/session and `sync` queue, so routing and `.htaccess` behaviour surface locally rather than after deployment.
- `Dockerfile` (php:8.3-apache), `docker-compose.yml` with a health-checked MySQL service and named volume, an Apache vhost with `AllowOverride All`, and a `.dockerignore`.
- Docker quickstart section in the README.

### Changed

- `.env.example` aligned to the `file`/`sync` drivers and the Docker database host; `APP_PORT` defaults to 80.
- Deployment guide corrected to use `CACHE_STORE`, the Laravel 12 key.

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

- Car Searches and Car Images UIs now treat missing model/color/transmission filters as **All** — tables render `All` instead of blank for `null` values, and the Car Search form hydrates **All ...** options when editing or viewing existing searches so dropdowns are never empty.
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
