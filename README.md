# Cars Images API

A Laravel 13 + Filament 5 application that searches, filters, reviews, and bulk-exports car photography from **Wikimedia Commons** — one vehicle at a time, or thousands of rows from a CSV.

A versioned **`/api/v1` JSON API** and an **Expo (React Native) client** put the same pipeline on a phone and in the browser: run a search, browse runs and the image library, run a CSV import's searches, work a review queue, and read pipeline health without opening the admin panel. On a phone you can also upload a CSV and export the library.

![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13.x-FF2D20?logo=laravel&logoColor=white)
![Filament](https://img.shields.io/badge/Filament-5.x-FDAE4B)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)
![Expo](https://img.shields.io/badge/Expo-SDK%2057-000020?logo=expo&logoColor=white)
![React Native](https://img.shields.io/badge/React%20Native-0.86-61DAFB?logo=react&logoColor=black)
[![CI / Deploy](https://github.com/RonaldAllanRivera/cars-images-api/actions/workflows/ci-cd.yml/badge.svg)](https://github.com/RonaldAllanRivera/cars-images-api/actions/workflows/ci-cd.yml)
[![Mobile CI](https://github.com/RonaldAllanRivera/cars-images-api/actions/workflows/mobile.yml/badge.svg)](https://github.com/RonaldAllanRivera/cars-images-api/actions/workflows/mobile.yml)
![Tests](https://img.shields.io/badge/tests-363%20PHP%20%2B%20182%20mobile-informational)

---

## Contents

- [Overview](#overview)
- [How it works](#how-it-works)
- [Features](#features)
- [JSON API](#json-api)
- [Mobile client](#mobile-client)
- [Engineering notes](#engineering-notes)
- [Tech stack](#tech-stack)
- [Getting started with Docker](#getting-started-with-docker)
- [Getting started without Docker](#getting-started-without-docker)
- [Configuration](#configuration)
- [Usage](#usage)
- [Testing](#testing)
- [Deployment](#deployment)
- [Project structure](#project-structure)
- [Roadmap](#roadmap)
- [License](#license)

---

## Overview

Sourcing usable photos for a large vehicle catalogue is tedious: every make/model/year needs its own search, Wikimedia's full-text index is inconsistent, results are frequently mis-filed or not cars at all, and the originals are far too large to ship to a website.

This application turns that into a reviewable pipeline:

1. **Import** a vehicle list (CSV) or run a single ad-hoc search.
2. **Harvest** images from Wikimedia Commons at a rate the API is happy with.
3. **Review** what came back, with off-target results flagged rather than silently trusted.
4. **Export** the approved set as a web-optimized ZIP plus a matching CSV manifest.

Every stage is inspectable in the admin panel, and nothing is thrown away without a human deciding. Since the JSON API landed, the same review work can be done from the Expo client on a phone.

---

## How it works

```mermaid
flowchart TB
    subgraph Panel["Filament 5 admin panel"]
        direction LR
        P1["Upload CSV"]
        P2["Search Queries"]
        P4["Car Image Searches (ad-hoc)"]
        P3["Results"]
    end

    subgraph Services["Application services"]
        direction TB
        IMP["CsvQueryImporter<br/>parse - dedupe - cap"]
        ACT["RunSearchQueryAction<br/>status + block logging"]
        SVC["CarImageSearchService<br/>exact-year selection"]
        LOC["CommonsCategoryLocator<br/>make + model to category"]
        WMC["WikimediaClient<br/>category files - cache"]
        EXP["BatchZipBuilder + ImageResizer<br/>BatchCsvExporter + FilenameBuilder"]
    end

    subgraph DB["MySQL"]
        direction LR
        T3[("csv_imports")]
        T1[("car_searches")]
        T2[("car_images")]
        T4[("wikimedia_block_events")]
        T5[("commons_category_lookups")]
    end

    WM(["Wikimedia Commons API"])
    DL{{"ZIP + CSV manifest"}}

    P1 --> IMP
    IMP --> T3
    IMP --> T1
    P2 --> ACT
    P4 --> SVC
    ACT --> SVC
    SVC --> LOC
    LOC --> T5
    LOC --> WMC
    SVC --> WMC
    WMC --> WM
    WM --> WMC
    SVC --> T2
    ACT -. "429 / 403 / 503" .-> T4
    T2 --> P3
    P3 --> EXP
    EXP --> DL
```

The two entry points share one engine. An **ad-hoc search** (`car_searches` with a null `csv_import_id`) and a **CSV-imported query** (`csv_import_id` set) are the same row type, scoped apart at the resource level, and both flow through `CarImageSearchService`.

---

## Features

### Search and harvesting

- **Search by Commons category, not by keyword.** Make and model resolve to the Wikimedia Commons category that holds that model's photographs. For each year in the range, the search keeps only files whose title names that year. One category read serves every year of a search.
- **Linked make/model dropdowns** driven by a curated catalogue. A model is required to find a category, so a search left on `All models` completes with no images.
- **Color, transmission and transparent background are recorded, not searched.** They are stored on the search (transmission is carried into the CSV manifest) but do not narrow what is fetched.
- **Result caching and reuse.** A resolved category is stored in `commons_category_lookups` and kept; a failed lookup is retried after 30 days. Category listings are cached for an hour, and an identical completed search reuses its stored images instead of calling Wikimedia again.
- **Non-image filtering.** Commons categories also hold PDFs, DjVu scans and other non-image files. Anything whose MIME type is not `image/*` is dropped.

### Review

- **Make confirmation guards against a mis-resolved category.** Categories are curated by people, so the keyword filters and off-make rejection that full-text search needed were removed with it. `MakeRelevanceChecker` still records whether the searched make appears in an image's title, description or categories, shown as a **Confirmed / Not confirmed** badge with a filter. It is a cheap check against a category such as BMW Alpina's, whose tree holds more than the searched make.
- **A human verdict recorded beside the machine's.** `review_status` (approve / reject) is a separate column from `make_confirmed` and `year_confirmed`, so a reviewer's judgement and `MakeRelevanceChecker`'s stay comparable rather than overwriting one another. Both show on the Results table, and the mobile Review tab works the queue of images no human has ruled on yet.
- Sortable, searchable tables with thumbnails, live-polling status badges, preview modals, and per-row or bulk delete.

### Export

- **Download Selected as ZIP** — every selected image, resized and renamed.
- **Download Confirmed as ZIP** — the same, narrowed to make-confirmed images only.
- **Export Selected as CSV** — a manifest whose `Filename` column matches the ZIP entries exactly, so the archive and the spreadsheet never drift apart.
- Filenames are deterministic and filesystem-safe: `1997 Toyota RAV4.jpg`, with duplicates suffixed `1997 Toyota RAV4 2.jpg`.

### Administration

- Dedicated **Car Makes** catalogue (makes with a models repeater) that feeds the search dropdowns.
- **Admin Users** management with hashed passwords and blank-to-keep password editing.
- Wikimedia block events recorded and visible, rather than failing silently.
- **A pipeline error log.** `error_events` records why a CSV upload, an individual row, a search run, an image download or a Wikimedia block failed, with a retention window, a per-import ceiling so one bad upload cannot write thousands of rows, and an `error-events:prune` command (nothing prunes on a schedule — there is no cron on shared hosting).
- **Pipeline health on the landing page** — an overview widget, a search-throughput chart, an errors-by-context chart, and a latest-failures table.

---

## JSON API

A versioned JSON API under `/api/v1` exposes the pipeline to clients other than the admin panel. It is what the Expo app talks to, and it is versioned from its first commit — a breaking change ships as `/v2` beside it rather than in place.

**Authentication** is a Sanctum bearer token. `POST /auth/login` exchanges credentials for a token carrying the abilities the client asks for, from these eight:

| Ability | Grants |
| --- | --- |
| `search:read` | list, show and count images; list and show searches and a search's images; the make/model catalogue |
| `search:write` | run one ad-hoc search |
| `search:run` | run the next chunk of a CSV import's queued searches |
| `review:write` | record an approve / reject verdict |
| `imports:read` | list and show CSV imports with their coverage |
| `imports:write` | upload a CSV |
| `exports:read` | mint a signed CSV or ZIP export link |
| `errors:read` | pipeline health summary, daily series, and the error log |

A client that asks for nothing gets `search:read`, `search:write`, `review:write` and `errors:read`, the four that existed before scoping, so an older client keeps exactly what it had. `search:run`, `imports:read`, `imports:write` and `exports:read` are issued only when asked for by name. The server intersects the request with the eight rather than widening it. The mobile web build asks for six and leaves out `imports:write` and `exports:read`, because its token lives in `localStorage`; the native build asks for all eight.

The abilities are constants, so a typo in a route is a fatal error rather than a silent 403.

| Method | Route | Notes |
| --- | --- | --- |
| `POST` | `/auth/login` | 5 req/min — the only route the open internet reaches |
| `POST` | `/auth/logout` | revokes the calling token |
| `GET` | `/auth/me` | the authenticated user |
| `GET` | `/images`, `/images/{image}`, `/images/count` | cursor-paginated; filter by make, model, year, verdicts, download status and CSV import |
| `GET` | `/searches`, `/searches/{search}` | search runs; filter by status, source (`csv` / `adhoc`), import and coverage |
| `GET` | `/searches/{search}/images` | a run's images |
| `GET` | `/makes` | the make/model catalogue, for suggestions |
| `POST` | `/searches` | 10 req/min — runs the search inline |
| `GET` | `/imports`, `/imports/{import}` | CSV imports with their coverage |
| `POST` | `/imports` | 10 req/min — upload a CSV |
| `POST` | `/searches/run-chunk` | 10 req/min — runs one bounded chunk of an import's searches |
| `POST` | `/exports` | 10 req/min — mints a signed link to a CSV or ZIP of the filtered images |
| `PATCH` | `/images/{image}/review` | the human verdict |
| `GET` | `/health/summary`, `/health/series`, `/errors` | pipeline health, daily series, and failures |

Four details are worth knowing before building against it:

**Every rate limit is named on its route**, not inherited from the middleware group. Login is the tightest at 5/min because it is unauthenticated. The routes that reach Wikimedia or create work (`POST /searches`, `/searches/run-chunk`, `/imports` and `/exports`) are next at 10/min, because Wikimedia has blocked this application before.

**`POST /searches` is capped tighter than the admin panel.** There is no queue worker on shared hosting, so the search runs inside the HTTP request and has to finish inside `max_execution_time`. `API_SEARCH_MAX_YEAR_SPAN` (3) and `API_SEARCH_MAX_IMAGES_PER_YEAR` (5) bound what one call may ask for; a search too large for the API is still available from the panel. It answers `201`, `200`, `503` or `502` with a real search row in every case — a `503` means Wikimedia blocked the run, and the row records that.

**Export links are signed, short-lived, and single-use for ZIPs.** `POST /exports` returns a link to `GET /exports/download`, a web route outside `/api/v1`, because the app opens it in the system browser, which sends no bearer token. The signature is the credential, so the link expires after five minutes, and a ZIP link stops working after its first use, because replaying one costs up to a hundred Wikimedia fetches. A ZIP is refused above 100 matching images; a CSV has no cap because it fetches nothing.

**CORS allows only the configured browser origin.** `CORS_ALLOWED_ORIGINS` is empty by default. The web build of the mobile app is the only browser that should reach `api/*`; the native build sends no `Origin` header and is unaffected. **If the deployed web origin is not listed there, the browser blocks every request and sign-in fails silently.**

---

## Mobile client

An Expo app under [`mobile/`](mobile/) — five tabs, TypeScript throughout, talking to `/api/v1`.

| Tab | What it does |
| --- | --- |
| **Search** | run an ad-hoc search, with makes and models suggested from the catalogue; browse past runs and open a run's images |
| **Library** | every harvested image, filtered like the panel's Results page; on native, export the filtered set as a CSV or ZIP |
| **Pipeline** | CSV imports and their coverage, and running an import's queued searches; on native, upload a CSV |
| **Review** | work the queue of images no human has ruled on, approving or rejecting |
| **Health** | the pipeline health summary, daily throughput, failures by kind, and the error log |

```mermaid
flowchart LR
    subgraph App["Expo app - mobile/"]
        direction TB
        T1["Search"]
        T2["Library"]
        T3["Pipeline"]
        T4["Review"]
        T5["Health"]
    end

    subgraph Client["src/api"]
        direction TB
        RQ["TanStack Query<br/>cursor pages - optimistic writes"]
        AC["client.ts<br/>one request path - 401 signs out"]
        ZD["schemas.ts<br/>Zod at the boundary"]
    end

    TS["TokenStore<br/>SecureStore (native)<br/>localStorage (web)"]

    subgraph API["Laravel - /api/v1"]
        direction TB
        SAN["Sanctum<br/>scoped abilities + throttles"]
        CTL["Controllers + Resources"]
    end

    SVC["CarImageSearchService<br/>the same engine the panel uses"]

    T1 --> RQ
    T2 --> RQ
    T3 --> RQ
    T4 --> RQ
    T5 --> RQ
    RQ --> AC
    AC --> ZD
    TS --> AC
    AC --> SAN
    SAN --> CTL
    CTL --> SVC
```

It ships two ways from one codebase:

- **Web:** built by [`.github/workflows/mobile.yml`](.github/workflows/mobile.yml) and published to Netlify on every push to `main` that touches `mobile/**`.
- **Android APK:** planned, tag-triggered (see the design doc).

### Local development

```bash
cd mobile
npm install
cp .env.example .env      # point EXPO_PUBLIC_API_URL at your Laravel dev server
npm run web               # or: npm start, npm run android
```

```bash
npm run typecheck   # tsc --noEmit
npm run lint
npm test            # 182 tests, 31 suites
npm run build:web   # expo export -p web  ->  dist/
```

`mobile/` keeps its own `package.json` and lockfile, separate from the root one that builds Laravel's Vite assets. That separation is deliberate — it is what stops Metro resolving the repository root's `node_modules`.

> **After changing `EXPO_PUBLIC_API_URL`, export with `--clear`.** Metro's cache is not keyed on `EXPO_PUBLIC_` values, so a warm cache will happily serve a bundle carrying the previous run's URL — and a verification step that greps the emitted bundle will report a false PASS.

### How it is put together

**The token store is one interface with two implementations.** `expo-secure-store` on native, `localStorage` on web, selected by `Platform.OS`. Every web call is wrapped in try/catch, because a browser in private mode can *throw* on storage access rather than return empty — a missing token means "signed out", which is recoverable; an exception at module load is not. A bearer token in `localStorage` is a genuine exposure, and the mitigation is named rather than waved away: token abilities are scoped, so a stolen web token can do only what the API let it do.

**One request path, and one place a session ends.** A private `send()` builds the URL, attaches the bearer header and reads the body once, so the "a 204 has no JSON to parse" case is handled once instead of in every caller. A `401` signs the user out from that single function. A `403` deliberately does **not** — the token is alive and merely lacks an ability, and treating the two alike would log a reviewer out for opening a screen they were never granted.

**Zod runs at the boundary, not at the point of use.** Every response is parsed against a schema, so drift between a PHP `Resource` and the client contract throws at the fetch, naming the field that moved, rather than surfacing as `undefined is not an object` three screens later.

**The contract is pinned in both languages.** `tests/Feature/Api/ContractFixturesTest.php` captures real responses into `mobile/src/api/__fixtures__/*.json` and fails when the committed copies drift; the mobile suite then asserts its Zod schemas against those same files. A renamed field is a red PHP run *and* a red TypeScript run. Regenerate deliberately:

```bash
UPDATE_CONTRACT_FIXTURES=1 php artisan test --filter=ContractFixturesTest
```

The capture freezes the clock and normalises the token to a placeholder, so a fixture diff always means a real change and nothing credential-shaped is committed.

**Approve and reject are optimistic, with a real undo.** The verdict is written into every cache that could hold the row and a snapshot is restored if the request fails — an optimistic update that cannot undo itself leaves the UI asserting something the server never accepted. The cache predicate matches `queryKey[0] === 'images' || queryKey[2] === 'images'` rather than a plain `['images']` prefix, because a run's own image list is keyed `['searches', id, 'images', filters]`; a prefix filter would leave a stale badge on exactly the screen the reviewer was looking at.

**Auth is a route guard, not a per-screen check.** `app/(app)/_layout.tsx` redirects to `/login` while the status is `anonymous` and renders a spinner while it is `loading`. That loading branch carries its own `<PageTitle>`, because the static export renders precisely that branch into every guarded route's HTML — whether a session exists is only knowable in the browser — so without it those pages would ship with a blank browser tab.

### Web deployment

The build runs in GitHub Actions, **not** on Netlify, and the Git repository is deliberately not linked to the Netlify site: one build of record rather than two that can disagree. The workflow typechecks, lints, tests and exports on every push and pull request touching `mobile/**`, then publishes `dist/` with the Netlify CLI when `MOBILE_DEPLOY_ENABLED` is `true`.

Three things about [`mobile/netlify.toml`](mobile/netlify.toml) are worth knowing before editing it:

- **The rewrites are specific, not a catch-all.** A static export keeps Expo Router's filename verbatim, so `/library/42` lives on disk as `dist/library/[id].html` and a shared link would otherwise 404. Four ordered 200-rewrites map them (`/pipeline/:id`, `/search/runs/:id`, `/search/:id`, `/library/:id` — the deeper `/search/runs/:id` is listed before `/search/:id`). Because they are not a catch-all, an existing file still wins and a genuinely unknown path still 404s instead of being handed an app shell.
- **The rewrites are checked against the router.** The list is maintained by hand, and the tab restructure once left `/runs/:id` pointing at a deleted page while `/library/:id` 404'd in production for three days — clicking through inside the app still worked, so only a hard navigation showed it. `npm run check:redirects` (`scripts/check-netlify-redirects.mjs`) now fails the CI build when the rewrites and the exported routes disagree.
- **The CSP pins a script hash instead of allowing `'unsafe-inline'`.** The export emits exactly one inline script — the stable one-liner `globalThis.__EXPO_ROUTER_HYDRATE__=true;` — so it is hashed and every other inline script stays blocked. If a future Expo version changes that snippet the hash goes stale and the page client-renders instead of hydrating; recompute it from the emitted HTML rather than reaching for `'unsafe-inline'`.

`EXPO_PUBLIC_API_URL` is inlined into the bundle at build time — there is no runtime config file, so a deployed build cannot be pointed at the wrong backend by accident, and changing the variable requires a rebuild. That is why the deploy job accepts `workflow_dispatch`: no push touches `mobile/**` just because the API moved, and without a manual trigger the only way to republish would be an empty commit.

> Set `EXPO_PUBLIC_API_URL` as a repository **variable**, not a secret. An unset `vars.*` renders as an empty string, the client reads it with `??` (which does not treat `""` as absent), and `new URL(path, "")` then throws on every request — a green deploy shipping a site where nothing loads. The export step now fails on an empty value rather than let that through.

**Setting Netlify up for the first time, or debugging a deploy?** See [`docs/netlify-deploy.md`](docs/netlify-deploy.md) — it covers the one-time setup, why the repository is deliberately not linked, and the specific failure modes worth recognising: **a blank page means the CSP, a silent sign-in failure means CORS.**

---

## Engineering notes

The parts of this project worth reading are the ones that exist because the obvious approach did not survive contact with the real API.

**Category membership replaced full-text search.** Wikimedia's full-text index matches loosely: an `Acura CL` query returned `Honda Accord CL3` photographs, because the Accord's chassis code contains the model token. Every fix for that (keyword filters, off-make rejection, a retry without the year) cleaned up after the search instead of removing the cause. A search now resolves make and model to a Commons category, which people curate, reads the whole category, and keeps the files whose title names the year. The order matters: whole category, then the year filter, then the limit. `Category:Cadillac STS` holds 56 files, of which 6 name 2005, so applying the limit to the fetch would hand the year filter an arbitrary ten files and find none of them.

**Wikimedia rate-limit etiquette is built in, not bolted on.** Requests send a descriptive, contactable `User-Agent` (Wikimedia's UA policy rejects generic ones with 429/403), set `maxlag=5` so the app backs off when replication lags, cache category listings for an hour and resolved categories indefinitely, pause one second between bulk queries, and retry transient failures with exponential backoff. A 429/403/503 raises a typed `WikimediaBlockedException`, is persisted to `wikimedia_block_events`, and **halts the bulk loop** — the failure mode of a harvesting tool should never be to keep hammering.

**Thumbnails are generated locally because the CDN refuses.** The first implementation requested Wikimedia's `/thumb/` URLs. Those return HTTP 400 when requested from datacenter and shared-hosting IPs, so it worked in development and failed in production. The fix was to download the original and resize with GD (`ImageResizer`, default 1600px max width, JPEG re-encode) — roughly an 85% size reduction, and host-independent. Unsupported formats fall through with the original bytes intact, so an image is never lost from an archive.

**CSV model strings are normalized before a category is resolved.** Vehicle data encodes models as engine displacement plus trim (`2.2CL/3.0CL`); Wikimedia catalogues the bare model (`CL`). `ModelSearchTermNormalizer` strips the displacement prefix and collapses slash-separated variants, but keeps the original whenever stripping would leave too little to search on. It is the first step of `CommonsCategoryResolver`.

**Transmission and color are recorded, not searched.** Image pages never say "Automatic 4-spd", and a category holds a model's photographs whatever the gearbox or paint, so neither narrows what is fetched. Both stay on the search, and transmission is carried into the CSV manifest.

**Synchronous work is capped rather than left to time out.** A bulk run is driven by the browser: a click queues the selection, and each Livewire poll runs one chunk of at most 50 queries or about 10 seconds, so no single request gets near the gateway timeout. Closing the tab stops the run, and **Run all pending** resumes it. Bulk ZIPs stop at 100 images because they are built inside a single web request on shared hosting. Each cap is a config value with an explicit reason in `config/cars-images.php`.

**Failure paths are explicit.** A per-image fetch failure skips that image instead of aborting the archive; an archive where everything failed reports it instead of serving a zero-entry ZIP; a search that throws is forced to `failed` even though its status update was inside the rolled-back transaction. A refresh deletes and refetches inside **one** transaction, so a rate-limited Wikimedia cannot leave a search stripped of the images it was replacing.

**One image can belong to many searches.** The same Commons file legitimately answers several queries, so ownership — `(car_search_id, year, provider, provider_image_id)` — is part of the upsert key and is enforced by a unique index. Keyed only on the file, a later search would *move* the row instead of copying it, silently emptying the earlier search.

**The API and its client are pinned to each other, not trusted to agree.** The usual way a backend and a mobile client drift apart is quietly: a field is renamed in a serializer, the client renders a blank, and nobody notices until a user reports it. Here the PHP suite *generates* the JSON fixtures the Expo app's Zod schemas are asserted against, so the rename fails the PHP suite first and then names the moved field in the TypeScript one. The cost is a deliberate regeneration step; the benefit is that contract drift cannot reach production silently.

**The API's limits are tighter than the panel's, on purpose.** `POST /searches` runs its search inside the HTTP request, because there is no queue worker on shared hosting — so it caps the year span at 3 and images-per-year at 5, where the panel allows far more. A search too large for the API is not refused work; it is redirected to the surface that can afford it.

**A cap on queries is not a cap on cost.** The CSV importer has always bounded the number of *queries* an upload can produce, which says nothing about the number of *images* those queries then download. At the defaults that is 1,000 × 5 = 5,000 downloads, paced at a second apiece. `CSV_IMPORT_MAX_PROJECTED_IMAGES` defaults to exactly the product of the two caps above it — so out of the box it rejects nothing the old cap allowed — but raising images-per-year is now caught at upload time instead of part-way through a long run.

---

## Tech stack

| Layer | Choice |
| --- | --- |
| Runtime | PHP 8.3+ (`ext-gd`, `ext-zip`, `ext-intl`, `ext-pdo_mysql`) |
| Framework | Laravel 13.x |
| Admin UI | Filament 5.x (Livewire 4) |
| Database | MySQL 8.0 |
| External API | MediaWiki / Wikimedia Commons |
| Image processing | GD |
| Local environment | Docker (Apache + mod_php + MySQL) |
| Tests | PHPUnit 12 (requires `ext-pdo_sqlite`) |
| API auth | Laravel Sanctum (bearer tokens, scoped abilities) |
| Mobile | Expo SDK 57, Expo Router 57, React Native 0.86, React 19.2 |
| Mobile data | TanStack Query 5, Zod 4 |
| Mobile styling | NativeWind 4 (Tailwind 3) |
| Mobile tests | Jest 29 + `@testing-library/react-native` |
| Web hosting (app) | Netlify, static export published from GitHub Actions |

> **On the current major of every dependency** — Laravel 13.36, Filament 5.10, Livewire 4.4, PHPUnit 12.5 as of 2026-10-20, when `composer outdated --direct` listed no new major releases. The upgrade was executed in verified stages; the plan, compatibility matrix, per-stage gates, and the issues it surfaced are recorded in [`docs/upgrades/2026-08-24-laravel-13-filament-5-upgrade.md`](docs/upgrades/2026-08-24-laravel-13-filament-5-upgrade.md).

---

## Getting started with Docker

The Docker stack mirrors SiteGround shared hosting — Apache + mod_php + MySQL, with `file` cache/session and a `sync` queue — so routing and `.htaccess` problems surface locally instead of after deployment.

**Prerequisites:** Docker Engine 24+ and Docker Compose v2.

```bash
cp .env.example .env
# The defaults work as-is for Docker. Before harvesting, set WIKIMEDIA_USER_AGENT
# to a string identifying your deployment with a real contact address.
docker compose build
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan storage:link
```

The panel is then at `http://localhost:8080/admin` — change `APP_PORT` in `.env` to use a different host port.

**Notes**

- `docker compose down` keeps the `cars-mysql-data` volume; `docker compose down -v` wipes the database.
- The `app` container runs as UID/GID 1000 — override with the `WWWUSER` / `WWWGROUP` build args if your host user differs.
- Re-run `docker compose exec app composer install` after pulling changes to `composer.json` / `composer.lock`.
- MySQL is published on `${FORWARD_DB_PORT}` (default `3307`) if you want to attach a GUI client.

---

## Getting started without Docker

**Prerequisites:** PHP 8.3+ with `gd`, `zip`, `intl`, `pdo_mysql` (plus `pdo_sqlite` to run the tests), Composer, MySQL 8, and Node.js only if you intend to rebuild frontend assets.

```bash
git clone <YOUR_REPO_URL> cars-images-api
cd cars-images-api

composer install
cp .env.example .env          # set DB_HOST=127.0.0.1 and the DB_* values for your machine
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve             # or point Laragon / Valet / nginx at public/
```

`migrate --seed` creates the Filament admin user and seeds the default make/model catalogue used by the search form.

> **Change the seeded admin password before exposing the panel.** `FilamentAdminUserSeeder` creates its account from credentials committed to this repository, and because it uses `updateOrCreate`, re-running the seeder resets that password back to the committed value.

---

## Configuration

Wikimedia integration lives in `config/images.php`; all application limits live in `config/cars-images.php`. Both are environment-driven.

| Variable | Default | Purpose |
| --- | --- | --- |
| `WIKIMEDIA_BASE_URL` | `https://commons.wikimedia.org/w/api.php` | MediaWiki API endpoint |
| `WIKIMEDIA_USER_AGENT` | — | **Set this to a real, contactable UA.** Wikimedia blocks generic agents |
| `WIKIMEDIA_TIMEOUT` | `10` | Per-request timeout (seconds) |
| `WIKIMEDIA_RETRY_TIMES` | `3` | Retry attempts for transient errors |
| `WIKIMEDIA_RETRY_SLEEP_MS` | `200` | Backoff base; doubles per attempt |
| `WIKIMEDIA_CACHE_TTL` | `3600` | Category-listing cache lifetime (seconds) |
| `WIKIMEDIA_MAXLAG` | `5` | MediaWiki `maxlag` courtesy parameter |
| `WIKIMEDIA_CATEGORY_MISS_TTL_DAYS` | `30` | Days before a model with no category is looked up again |
| `WIKIMEDIA_CATEGORY_MAX_FILES` | `500` | Most files read from one category |
| `WIKIMEDIA_CATEGORY_PAGE_SIZE` | `200` | Files per category request |
| `CSV_IMPORT_MAX_COMBOS` | `1000` | Reject an upload above this many unique queries |
| `CSV_IMPORT_DEFAULT_IMAGES_PER_YEAR` | `5` | Images requested per imported query |
| `CARS_BULK_RUN_MAX_QUERIES` | `50` | Queries per bulk-run chunk |
| `CARS_BULK_RUN_AUTO_CHUNK_SECONDS` | `10` | Wall-clock budget per bulk-run chunk (one poll, or one `run-chunk` call) |
| `CARS_BULK_RUN_SLEEP_SECONDS` | `1` | Pause between bulk queries |
| `CARS_DOWNLOAD_MAX_WIDTH` | `1600` | Max width for ZIP images (resized locally) |
| `CARS_DOWNLOAD_JPEG_QUALITY` | `82` | JPEG quality for resized images |
| `CARS_BULK_DOWNLOAD_MAX_IMAGES` | `100` | Max images per synchronous ZIP |
| `CSV_IMPORT_MAX_UPLOAD_KB` | `5120` | Max size of an uploaded CSV |
| `CSV_IMPORT_MAX_PROJECTED_IMAGES` | `5000` | Reject an upload committing to more image downloads than this |
| `ERROR_LOG_RETENTION_DAYS` | `30` | How long a recorded failure is kept |
| `ERROR_LOG_MAX_EVENTS_PER_IMPORT` | `500` | Events stored per CSV import before suppression |
| `API_SEARCH_MAX_YEAR_SPAN` | `3` | Max year span for `POST /api/v1/searches` |
| `API_SEARCH_MAX_IMAGES_PER_YEAR` | `5` | Max images per year for `POST /api/v1/searches` |
| `CORS_ALLOWED_ORIGINS` | — | **Comma-separated browser origins allowed to call `api/*`.** Empty blocks every browser |

The Expo app is configured separately, in `mobile/.env`:

| Variable | Default | Purpose |
| --- | --- | --- |
| `EXPO_PUBLIC_API_URL` | `http://localhost:8000` | API origin, **inlined into the bundle at build time** |

---

## Usage

### CSV bulk pipeline

The three pages under the **Cars** navigation group run left to right.

**1. Upload CSV** (`/admin/csv-imports/create`)

Required columns are `Make`, `Model`, `Year`; `Transmission` is optional and carried through to the manifest. Rows are deduplicated by `(Year, Make, Model)`, rows with a missing field or an implausible year are skipped and counted, and an upload producing more than `CSV_IMPORT_MAX_COMBOS` unique queries is rejected outright rather than half-imported.

```csv
Make,Model,Year,Transmission
Toyota,RAV4,1997,Automatic 4-spd
Acura,2.2CL/3.0CL,1998,Manual 5-spd
```

**2. Search Queries** (`/admin/search-queries`)

Review the imported queries, then **Run** a single row, select many and **Run Selected**, or use **Run all pending** to queue every pending or failed search the current filters select. The page then works through the queue itself, one chunk per poll (`CARS_BULK_RUN_MAX_QUERIES` queries or `CARS_BULK_RUN_AUTO_CHUNK_SECONDS` seconds). Closing the tab stops the run, and **Run all pending** picks up where it left off. The **Coverage** filter separates searches that found images, ran and found nothing, and have not run yet. A Wikimedia block pauses the run, raises a persistent notification, and records the event.

**3. Results** (`/admin/results`)

Browse the harvested images, filter by source CSV or by **Make match**, then export the selection as a ZIP, a confirmed-only ZIP, or a CSV manifest. A coverage panel counts the searches behind the images (run, found images, ran and found nothing, not run yet), so a half-searched import does not look finished.

### Ad-hoc search

**Cars → Car Image Searches → Create.** Pick a make and a model (the model list follows the make) and set a year range — reversed ranges are normalized. The model is what resolves a Commons category, so a search left on **All models** completes with no images. Color, transmission and transparent background are recorded on the search but do not narrow the results. The search runs synchronously and redirects to its view page, where the **Images** relation holds the results.

From that view page, **Refresh from Wikimedia** deletes the search's images, clears the cached responses for its years, and re-runs with the current filters. Editing the search instead re-runs it with the *new* filters.

### Catalogue and users

**Cars → Car Makes** manages makes and their models (used by the search dropdowns; built-in defaults apply when the tables are empty). **System → Admin Users** manages panel accounts — leave the password blank when editing to keep the existing one.

---

## Testing

```bash
php artisan test                        # whole suite
php artisan test --testsuite=Unit       # fast, no database
php artisan test --filter=BatchZipBuilder
```

363 tests across 57 files: 56 unit tests over the pure helpers (filename building, image resizing, category resolution, make relevance, model normalization, model-year matching) and 307 feature tests over the CSV importer and import coverage, category retrieval and exact-year selection, ZIP/CSV export and signed export links, Wikimedia block handling, resource scoping, the Results page bulk actions, bulk-run chunking, the error-log instrumentation and its widgets, every `/api/v1` endpoint with its abilities and rate limits, a smoke test that mounts **every** page in the panel, the registered record/bulk actions on every table, the CSV upload driven through the Filament form itself, and the ad-hoc search form's `All ...` sentinel round-trip.

The last three exist as upgrade insurance, and they earned it: they carried this codebase through Laravel 12→13 and Filament 4→5 (Livewire 3→4) with no behavioural regressions. A major release typically breaks an application by renaming a builder method, which leaves the resource compiling but the page throwing on mount — `PanelSmokeTest` turns that into a failing test rather than a support ticket, and `TableActionsTest` catches the subtler case where a page still renders but has quietly lost its buttons.

> Feature tests run against an in-memory SQLite database (see `phpunit.xml`), so **`ext-pdo_sqlite` must be installed** — without it every database-backed test errors with `could not find driver` while the unit tests still pass.

`ContractFixturesTest` is not an ordinary test: it *generates* the JSON fixtures the mobile app's Zod schemas are asserted against, and fails when the committed copies no longer match what the API returns. A red run there means the API shape changed — regenerate, then run the mobile suite, and the Zod schemas will name the field that moved.

Because the comparison is byte-for-byte, every source of nondeterminism has to be pinned or the test fails on a commit that changed no API code: the clock is frozen so timestamps are stable, the random token is normalized to a placeholder, and `phpunit.xml` sets `APP_URL` — Laravel builds the absolute `links.next` and `meta.path` URLs of a paginated response from `config('app.url')`, so an unpinned value records the generating machine's host into the fixtures. `CORS_ALLOWED_ORIGINS` is pinned there for the same reason: a developer's or CI's `.env` must not leak into a test.

```bash
UPDATE_CONTRACT_FIXTURES=1 php artisan test --filter=ContractFixturesTest
```

The mobile app has its own suite — 182 tests across 31 files, covering the API client's error and 401 handling, the Zod schemas against those fixtures, the token store on both platforms, the auth context, ability gating and route guard, the optimistic review mutation and its cache predicate, the CSV upload and bulk-run hooks, the coverage, export and run-progress panels, the make/model suggestions, the charts, the infinite grid, and the search and health screens:

```bash
cd mobile
npm test            # jest
npm run typecheck   # tsc --noEmit
npm run lint
```

Code style is enforced with Pint:

```bash
./vendor/bin/pint --test    # check
./vendor/bin/pint           # fix
```

---

## Deployment

**Deployment is continuous.** Pushing to `main` runs the suite and, if it is green, deploys to SiteGround over SSH via [`.github/workflows/ci-cd.yml`](.github/workflows/ci-cd.yml) — no manual session required. The deploy job has `needs: test`, so a red suite never reaches production.

The interesting part is the failure behaviour rather than the happy path:

| Guard | What it prevents |
| --- | --- |
| `trap ... EXIT` around maintenance mode | a failed migration leaving the panel offline |
| Pinned `SSH_KNOWN_HOSTS` + `StrictHostKeyChecking=yes` | handing the deploy key to a hijacked hostname |
| `BatchMode` + `ConnectTimeout` | a credential prompt hanging the job until it times out |
| `concurrency` group | two deploys interleaving a `composer install` and a migration |
| `artisan` preflight on `DEPLOY_PATH` | `git reset --hard` running in the wrong directory |
| HTTP 200 smoke check on `/admin/login` | a deploy that 500s the site reporting success |
| Docs-only path filter | taking production offline to publish a changelog entry |

Each was verified by execution, not by reading: the script was run end-to-end against a throwaway clone, and the trap was proven with a deliberately broken migration — the run exits non-zero *and* the site comes back up.

`DEPLOYMENT.md` covers the rest: §6.1 the automated deploy and its secrets, §6.2 the manual SSH sequence as fallback, plus directory layout, serving Laravel from `public/`, the `.htaccess` rewrite that keeps Livewire's endpoint reachable, and troubleshooting. The Docker stack above deliberately mirrors that environment.

### The mobile pipeline is separate

[`.github/workflows/mobile.yml`](.github/workflows/mobile.yml) watches `mobile/**` only, and `ci-cd.yml` ignores it. A change to the Expo app therefore cannot cycle production through maintenance mode, and a change to the API cannot spend CI minutes building a Metro bundle. The app's job typechecks, lints, tests and exports on every push and pull request, then publishes `dist/` to Netlify from `main` when the repository variable `MOBILE_DEPLOY_ENABLED` is `true` — the same switch pattern as `DEPLOY_ENABLED`, so `main` could carry this code before the Netlify site existed.

`cancel-in-progress` is set for pull requests only. An outdated PR run is worth superseding; a run on `main` may be midway through publishing, and a cancelled deploy is worse than a slow one.

Setup and the failure modes are in [`docs/netlify-deploy.md`](docs/netlify-deploy.md).

### Pins are maintenance, not safety

Both workflows pin their actions to a major — `actions/checkout@v7`, `actions/cache@v6`, `actions/setup-node@v7`, `shivammathur/setup-php@v2` — and the Netlify step pins `netlify-cli` to a major too, so a third party cannot change what a deploy does without a commit here. The cost is the opposite failure mode, and both halves of it have been paid: `netlify-cli@17` went ten majors stale and started answering deploys with `422 no records matched`, a message naming neither the version nor the cause; and the `@v4` action pins declared Node 20, which the runner now force-migrates to 24 with a warning on every job and will eventually decline to run. Treat a step that breaks with no local change as a stale pin first. (`setup-php` staying on `v2` is deliberate — it already ships a Node 24 entrypoint. `cache@v5` and newer need runner 2.327.1 or newer, which hosted runners are well past.)

---

## Project structure

```
app/
├── Auth/                TokenAbilities — what a bearer token is allowed to do
├── Console/Commands/    PruneErrorEvents (no cron on shared hosting; run by hand)
├── Exceptions/          WikimediaBlockedException — typed rate-limit signal
├── Filament/
│   ├── Pages/           Results (bulk export surface)
│   ├── Resources/       CsvImport, SearchQuery, CarSearch, CarImage, CarMake, ErrorEvent, User
│   └── Widgets/         PipelineHealthOverview, SearchThroughputChart, ErrorsByContextChart, LatestFailuresTable
├── Http/
│   ├── Controllers/     Single-image download (authenticated) and signed export download
│   │   └── Api/V1/      Auth, Image, Search, Make, Import, RunChunk, Export, ReviewImage, HealthSummary, HealthSeries, Error
│   ├── Requests/Api/V1/ Validation and caps for every API entry point
│   └── Resources/Api/V1 The JSON shapes the mobile client is pinned to
├── Jobs/                Queue scaffolding (not dispatched yet — see Roadmap)
├── Models/              CarSearch, CarImage, CsvImport, CarMake, CarModel, CommonsCategoryLookup, ErrorEvent, WikimediaBlockEvent, User
├── Policies/            CarImagePolicy, CarSearchPolicy, CsvImportPolicy
└── Services/
    ├── Downloads/       BatchZipBuilder, BatchCsvExporter, ExportQuery, ImageResizer, FilenameBuilder
    ├── Health/          PipelineHealthSummary, PipelineSeries — one source for the widgets and the API
    ├── Images/          WikimediaClient, CarImageSearchService, CommonsCategoryLocator, CommonsCategoryResolver,
    │                    ModelYearMatcher, MakeRelevanceChecker, ModelSearchTermNormalizer, CarImageZipService
    ├── Imports/         CsvQueryImporter, ImportCoverage
    ├── Logging/         ErrorEventLogger
    └── Search/          RunSearchQueryAction, RunChunkAction
config/
├── cars-images.php      Import caps, bulk-run pacing, download sizing, API caps, error log
├── cors.php             Which browser origins may call api/*
├── images.php           Wikimedia client settings
└── sanctum.php          Token auth
routes/api.php           /api/v1 — versioned from the first commit
mobile/                  The Expo app — its own package.json, lockfile and CI
├── app/                 Expo Router routes; (app)/ sits behind the auth guard
├── src/api/             client.ts, schemas.ts, queryKeys.ts, hooks/, __fixtures__/
├── src/auth/            AuthContext, TokenStore (SecureStore | localStorage), useCan
├── src/theme/           Design tokens, shared with the Tailwind config
├── src/ui/              Screen, InfiniteGrid, ImageCard, ReviewCard, ExportPanel, charts/, ...
├── scripts/             check-netlify-redirects.mjs — fails CI when rewrites and routes disagree
└── netlify.toml         Publish directory, route rewrites, CSP
docs/                    Design specs and implementation plans per feature
├── netlify-deploy.md    One-time Netlify setup and its failure modes
├── superpowers/         Per-feature specs and plans
└── upgrades/            The Laravel 13 / Filament 5 upgrade record
tests/{Unit,Feature}/    Mirrors the app/ layout; Feature/Api/ covers /api/v1
```

Background on the original design and decision history lives in `PLAN.md`, `CHAT.md`, and `docs/`.

---

## Roadmap

- Ship the Android APK from a tagged build, alongside the existing web export.
- Move bulk search and bulk download onto a queue worker, retiring the synchronous caps (`app/Jobs/` holds the scaffolding) — which would also lift the tighter caps on `POST /api/v1/searches`.
- Persist exports to the `cars` storage disk instead of streaming them straight to the browser.
- Explore AI-assisted classification for ambiguous results (see `PLAN.md`, section 13).

---

## License

No license file ships with this repository; the underlying Laravel skeleton it was generated from is MIT-licensed. Images retrieved through this tool remain subject to their individual Wikimedia Commons licences, which are stored per image in the `license` and `attribution` columns.
