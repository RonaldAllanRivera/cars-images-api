# React Native Mobile Client — Design

**Date:** 2026-09-05
**Status:** Approved (pending implementation plan)
**Project:** cars-images-api (Laravel 13 + Filament 5)

## Goal

Add a React Native (Expo) mobile client for the existing pipeline, and the JSON
API it needs, inside this repository. The app is a portfolio artefact: it must
be reachable for free and forever, without an App Store or Play Store listing.

Two deliverables, one codebase:

- a **web build** (`expo export -p web`) served free from Netlify — a link
  anyone can open with no install;
- an **Android APK** attached to a GitHub Release — proof the same source is a
  genuine native build.

## What exists today, and what does not

Despite the repository name, **there is no HTTP API**.

| Expected | Reality |
|---|---|
| `routes/api.php` | Does not exist. [`routes/`](../../../routes/) holds only `web.php` and `console.php`. |
| Token auth | Neither `laravel/sanctum` nor `laravel/passport` is in `composer.json`. |
| A non-panel route | Exactly one: the download at [`web.php:11-14`](../../../routes/web.php#L11-L14), behind session `auth`. |
| Human review of images | Does not exist. The only human verb on a `CarImage` is delete. |

Session cookies plus CSRF are the wrong shape for a mobile client, so the API
layer and its token auth are **part of this project**, not a prerequisite.

### `make_confirmed` is a machine verdict, not a human one

`make_confirmed` and `year_confirmed` are written in exactly one place —
[`CarImageSearchService.php:222`](../../../app/Services/Images/CarImageSearchService.php#L222),
by `MakeRelevanceChecker`. Everywhere else they are read-only: columns and
filters on [`Results.php`](../../../app/Filament/Pages/Results.php), and the
"confirmed only" ZIP filter at
[`Results.php:214`](../../../app/Filament/Pages/Results.php#L214).

Mobile approve/reject is therefore a **new capability** needing a schema change,
and it must not overwrite the machine's verdict. See [Schema changes](#schema-changes).

## Constraints

Re-verified on 2026-09-05:

- **`QUEUE_CONNECTION=sync`, no worker, no cron.** [`.env.example:47`](../../../.env.example#L47).
  Grepping `dispatch(` outside `app/Jobs/` finds only Livewire browser events at
  [`ListSearchQueries.php:229`](../../../app/Filament/Resources/SearchQueryResource/Pages/ListSearchQueries.php#L229).
  **`RunCarSearchJob` and `DownloadCarImagesJob` remain dead code.** All real work
  runs inline through
  [`RunSearchQueryAction::execute()`](../../../app/Services/Search/RunSearchQueryAction.php#L32).
  Filament survives this only by chunking bulk runs across many short Livewire
  requests.
- **Shared hosting.** Production is SiteGround; PHP `max_execution_time` bounds
  any inline request. There is no long-lived process to run a queue worker.
- **Wikimedia rate-limits this app.** `wikimedia_block_events` exists because it
  has happened. Any endpoint that reaches Commons is a new way to get blocked.
- **The deploy assumes repo == deployed app.**
  [`ci-cd.yml:142`](../../../.github/workflows/ci-cd.yml#L142) treats any
  non-docs change as deployable, and
  [`ci-cd.yml:271`](../../../.github/workflows/ci-cd.yml#L271) does
  `git reset --hard origin/main` **on the server**. A monorepo breaks both.
- **Tests need the `cars-ci-php:8.3` container** — host PHP has no `pdo_sqlite`.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Repo layout | **Monorepo**, app under `mobile/` | Cross-stack debugging: a bug that crosses the API boundary is one `git log`, one blame, one grep. Outweighs the tidiness of a split. |
| Delivery | **Web link + APK** | Web removes all friction; the APK proves it is a real native build. Both free permanently. |
| Framework | **Expo managed** | The only path where one codebase yields both a static web bundle and an APK for free. Bare RN CLI loses the web target and EAS. |
| Auth | **Sanctum API tokens**, real login | No demo account. Credentials issued on request. |
| Visibility | **Match Filament** — all authenticated users see everything | Consistent with the panel today. A permissive Policy makes it explicit rather than accidental. |
| Search execution | **Inline, hard-capped** | Matches how Filament's single-row action already works. No new infra on shared hosting, and a 5–10s response demos better than a polling spinner. |
| API shape | **Tailored endpoints**, not generic CRUD | The grid, the review queue and the health screen each want a different slice; generic resources over a phone connection are slow. |

**Accepted trade-off:** with no demo account, most portfolio visitors will not
log in. Mitigated by an unauthenticated landing screen that shows what the app
does before asking for credentials.

## Repo layout

```
cars-images-api/
├── app/  config/  database/  …     Laravel, structurally unchanged
├── routes/
│   ├── web.php
│   └── api.php                     new — /api/v1, Sanctum
├── mobile/                         new — Expo app
│   ├── app/                        expo-router screens
│   ├── src/                        api client, auth, ui
│   └── package.json
└── .github/workflows/
    ├── ci-cd.yml                   hardened — Laravel only
    └── mobile.yml                  new — Expo only
```

## CI hardening

Lands **before any application code**. Four changes:

**1. `ci-cd.yml` must not run on mobile-only pushes.** Filtering at the workflow
level (not the job level) means no runner starts at all:

```yaml
on:
  push:
    branches: [main]
    paths-ignore: ['mobile/**']
  pull_request:
    paths-ignore: ['mobile/**']
  workflow_dispatch:
```

*Known trap:* a skipped workflow reports **pending**, not success. If a job here
is ever made a required status check, mobile-only PRs become unmergeable.

**2. Belt-and-braces on the deploy filter.**
[`ci-cd.yml:142`](../../../.github/workflows/ci-cd.yml#L142) becomes:

```bash
if printf '%s\n' "$changed" | grep -qvE '\.md$|^docs/|^mobile/'; then
```

This covers what step 1 cannot: `workflow_dispatch` runs and **mixed commits**.
A commit touching both halves still deploys — correct, since Laravel changed.

**3. New `mobile.yml`,** the mirror image, triggered on
`paths: ['mobile/**', '.github/workflows/mobile.yml']`.

**4. `.gitignore`** gains `/mobile/node_modules`, `/mobile/.expo`,
`/mobile/dist`, `/mobile/android`, `/mobile/ios`.

**`mobile/` on the production server is accepted, not fixed.** The server's
`git reset --hard` will place the app's source on SiteGround. With the ignores
above that is a few MB of `.tsx` that PHP never executes. `git sparse-checkout`
would remove it at the cost of a fragile manual server step that eventually
breaks a deploy confusingly. The workflow carries a comment saying so.

## Laravel API

Scaffolded with `php artisan install:api`, which creates `routes/api.php` and
installs Sanctum. `HasApiTokens` goes on `User`. Everything is under `/api/v1`
from the first commit.

### Auth

| Endpoint | Throttle | Notes |
|---|---|---|
| `POST /api/v1/auth/login` | **5/min** | `email`, `password`, `device_name` → bearer token. The only endpoint the open internet can reach. |
| `POST /api/v1/auth/logout` | 60/min | Revokes the calling token only. |
| `GET /api/v1/auth/me` | 120/min | The app's "is my token still valid" probe. |

`device_name` makes tokens individually revocable — a lost phone does not force
rotating every token.

**Token abilities:** `search:read`, `search:write`, `review:write`,
`errors:read`. The web build must keep its token in `localStorage` (no
`SecureStore` equivalent on web), so scoping bounds the damage of theft.

### Reads

| Endpoint | Purpose |
|---|---|
| `GET /api/v1/images` | Browse grid. Filters: `make`, `model`, `year`, `make_confirmed`, `year_confirmed`, `review_status`, `download_status`. **Cursor** paginated. |
| `GET /api/v1/images/{id}` | Detail — attribution, license, dimensions, `source_url`. |
| `GET /api/v1/searches` | Run list, filterable by `pending`/`running`/`completed`/`failed`. |
| `GET /api/v1/searches/{id}` | Run detail with image counts. |
| `GET /api/v1/searches/{id}/images` | Images from one run. |
| `GET /api/v1/health/summary` | Run counts by status; recent failures grouped by context. |
| `GET /api/v1/errors` | Error log, filterable by the five contexts on [`ErrorEvent.php:18-26`](../../../app/Models/ErrorEvent.php#L18). |

Cursor pagination for images specifically: infinite scroll is the natural mobile
grid, and cursors neither skip nor duplicate rows when a harvest inserts
mid-scroll. Offset pagination does both.

### Writes

| Endpoint | Throttle | Notes |
|---|---|---|
| `POST /api/v1/searches` | **10/min** | Runs `RunSearchQueryAction` **inline**. Hard-capped (below). |
| `PATCH /api/v1/images/{id}/review` | 60/min | Sets `review_status`, `reviewed_by`, `reviewed_at`. |

**Search caps.** Because execution is inline under `max_execution_time`, the
Form Request rejects anything that cannot finish in time:

- `to_year - from_year` ≤ **3** (4 years inclusive)
- `images_per_year` ≤ **5**

Filament keeps its existing uncapped path; the caps bind API callers only. The
response returns the completed `CarSearch` with its images. A search that fails
mid-flight still marks the row `failed` and writes an `error_events` row,
because `RunSearchQueryAction` already does both — the API inherits that for
free.

### Schema changes

On `car_images`:

```php
$table->string('review_status', 16)->default('pending')->index(); // pending|approved|rejected
$table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('reviewed_at')->nullable();
```

Deliberately **separate** from `make_confirmed`. Overwriting the machine verdict
with the human one would permanently destroy the comparison *"how often was
`MakeRelevanceChecker` right?"* — the measurement that makes the flag worth
having, and a free future feature: surface the disagreements and review those
first.

`review_status` is surfaced in [`Results.php`](../../../app/Filament/Pages/Results.php)
as a column and filter, so web and mobile cannot disagree about what is approved.

### Conventions

- **API Resources live in `App\Http\Resources\Api\V1\`** and are named
  `ImageResource`, `SearchResource`, `ErrorResource`. The default
  `App\Http\Resources\CarImageResource` would collide by class name with the
  existing `App\Filament\Resources\CarImageResource` in every import block.
- **Form Requests** for all validation; no inline `$request->validate()`.
- **A permissive `CarImagePolicy`/`CarSearchPolicy`**, registered and explicit,
  matching Filament's current all-users-see-everything behaviour.
- **CORS config must be published first** — Laravel 11+ no longer ships
  `config/cors.php`; `php artisan config:publish cors` creates it. It then
  allows the Netlify origin for `api/*` — not `*`. The APK does not need CORS;
  the web build cannot work without it.

## Expo app

**Stack:** Expo (managed), expo-router, TypeScript `strict`, TanStack Query,
Zod, `expo-image`, NativeWind.

- **TanStack Query** — caching, infinite scroll, retry/backoff, and optimistic
  updates. The review queue must feel instant: approve moves the card
  immediately and rolls back only if the PATCH fails.
- **Zod** — validates responses at the boundary. A monorepo does not stop PHP
  and TypeScript types drifting; Zod turns drift into a clear error at the
  fetch rather than a crash three screens later.
- **`expo-image`** — grids of remote Wikimedia photos need real disk caching and
  placeholders; RN's built-in `Image` does not provide them.
- **NativeWind** — reuses the Tailwind vocabulary already used for Filament
  assets. Replaceable with plain `StyleSheet` if it fights the web build.

```
mobile/app/
├── _layout.tsx           providers: QueryClient, Auth
├── index.tsx             landing — what the app does, before login
├── login.tsx
└── (app)/                authenticated group
    ├── _layout.tsx       tabs + auth guard
    ├── search/           form → grid → [id] detail
    ├── runs/             list → [id] detail
    ├── review/           approve/reject queue
    └── health/           pipeline health + error log

mobile/src/
├── api/client.ts         base URL, bearer header, central 401 handling
├── api/schemas.ts        Zod schemas mirroring the API Resources
├── api/hooks/            useImages, useSearches, useReviewImage, useHealth
├── auth/TokenStore.ts    SecureStore on native | localStorage on web
└── auth/AuthContext.tsx
```

`TokenStore` behind one interface keeps the platform split from leaking into
screens: exactly one file knows web storage differs. The central 401 handler in
`client.ts` is the only place that clears the token and bounces to login.

`app/index.tsx` is unauthenticated by design — the mitigation for having no demo
account.

## Deploy pipelines

**Web** — on push to `main` touching `mobile/**`:

```
npm ci → tsc --noEmit → eslint → jest → expo export -p web → Netlify
```

`app.json` sets `web.output: "static"`, emitting real HTML per route. This gives
shareable URLs like `/runs/42`, working link previews, and no SPA catch-all
redirect. Netlify rather than GitHub Pages because Pages serves from
`/cars-images-api/`, and a sub-path fights expo-router without a custom domain.

`EXPO_PUBLIC_API_URL` is **inlined at build time**, not read at runtime — CI
injects it; there is no post-deploy config file.

**APK** — on tag `mobile-v*` only:

EAS Build with the `preview` profile (APK, not AAB — AAB is a Play Store format
that cannot be sideloaded), attached to a GitHub Release. Tag-triggered rather
than per-push because the EAS free tier meters Android build minutes. Fallback
if they run out: `eas build --local` on the Actions runner.

**Secrets required:** `NETLIFY_AUTH_TOKEN`, `NETLIFY_SITE_ID`, `EXPO_TOKEN`.

## Build order

1. **CI hardening** — before any application code.
2. **Laravel API** — Sanctum, `review_status` migration, reads, then writes.
3. **Expo app** — auth and search/browse, then review, then health.
4. **Deploy pipelines** — web first; APK once there is something worth installing.
5. **Phase 3** — CSV upload and ZIP export.

## Testing

- **Laravel:** feature tests per endpoint via `php artisan test` in the
  `cars-ci-php:8.3` container. Cover auth (valid, invalid, revoked, missing
  ability), pagination cursors, every filter, the search caps rejecting
  out-of-range input, and `review_status` transitions.
- **Mobile:** Jest + React Native Testing Library for the API client, Zod
  schemas, `TokenStore` on both platform paths, and the optimistic-update
  rollback. No E2E suite — disproportionate for this scope.
- **Contract:** the Zod schemas are asserted against fixtures captured from real
  API responses, so drift fails a test rather than the app.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Mobile search becomes a new way to get blocked by Wikimedia | 10/min throttle plus the year/image caps; existing `WikimediaBlockEvent` recording is inherited unchanged |
| Inline search still times out on SiteGround | Caps are set against measured production timing, not guessed; failures already mark the run `failed` and log an `error_events` row |
| A mobile commit deploys production | Two independent guards: workflow-level `paths-ignore` and the `^mobile/` deploy filter |
| Metro resolves the root `node_modules` instead of `mobile/`'s | Two separate lockfiles, no workspace linking; verified by a clean `npm ci` in CI |
| Token theft from web `localStorage` | Scoped token abilities; per-device tokens revocable individually |
| Portfolio visitors bounce at login | Unauthenticated landing screen showing the app before it asks for credentials |

## Out of scope

- App Store / Play Store submission of any kind.
- iOS `.ipa` distribution — impossible without a store or TestFlight.
- A public demo account or seeded demo backend.
- Switching to a real queue worker. Revisit only if the caps prove too tight.
- Push notifications, offline write sync, deep linking beyond expo-router's
  defaults.
- Any refactor of the Filament panel beyond surfacing `review_status`.

## Acceptance criteria

1. A mobile-only commit runs `mobile.yml` and **does not** start `ci-cd.yml` or
   touch production.
2. A Laravel-only commit runs `ci-cd.yml` and **does not** start `mobile.yml`.
3. `POST /api/v1/auth/login` returns a scoped bearer token; a request with a
   revoked or ability-less token is rejected `403`.
4. `POST /api/v1/searches` rejects a span over 3 years or `images_per_year`
   over 5, and otherwise returns a completed run with its images.
5. `PATCH /api/v1/images/{id}/review` sets `review_status`, `reviewed_by` and
   `reviewed_at`, leaves `make_confirmed` untouched, and the change is visible
   in the Filament Results table.
6. The web build is reachable at a public Netlify URL and can log in, search,
   review, and view pipeline health against production.
7. A `mobile-v*` tag produces an installable APK attached to a GitHub Release.
8. `php artisan test` and `vendor/bin/pint --test` pass in the container.
