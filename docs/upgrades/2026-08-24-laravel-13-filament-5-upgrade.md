# Technical Design: Laravel 13 + Filament 5 Dependency Upgrade

**Date:** 2026-08-24
**Status:** ✅ **Executed and verified** — all stages complete on 2026-08-24
**Author:** Prepared during the 2026-08-24 system review

---

## 0. Execution record

All stages were executed and gated. Final state:

| Package | Before | After |
| --- | --- | --- |
| laravel/framework | 12.61.0 *(12.38.1 installed)* | **13.26.1** |
| filament/* | 4.11.6 *(4.2.0 installed)* | **5.7.6** |
| livewire/livewire | 3.8.0 *(3.6.4 installed)* | **4.4.1** |
| phpunit/phpunit | 11.5.55 | **12.5.33** |
| laravel/tinker | 2.11.1 | **3.0.2** |
| guzzlehttp/guzzle | 7.10.5 | **8.0.2** |
| league/commonmark | 2.8.2 | **2.10.0** |

Final gate: **102 tests passing**, `composer audit` clean, `pint --test` clean
across 108 files, `composer validate` clean, `check-platform-reqs` satisfied.

**HTTP verified:** `/admin` → 302 unauthenticated, `/admin/login` → 200, all
Filament 5 assets → 200, token-less POST to the Livewire endpoint → 419.

**Browser verified** (Playwright, real session against the running container) —
this covers the two things the suite structurally cannot reach:

- Logged in through the Filament 5 login form; the panel renders fully styled
  (sidebar, topbar, tables, pagination), confirming asset publishing and
  Tailwind 4 are intact.
- **The make→model dependent select**, which is the single genuine Livewire 4
  async case in this codebase (`CarSearchResource.php:48` is the only `Select`
  with a Closure `options()`). Setting `data.make` to `Honda` through
  `$wire.set()` performed the real server round-trip: server state became
  `Honda`, `afterStateUpdated` correctly reset `model` to `null`, and opening
  the Model panel fetched **Accord / Civic / CR-V / Jazz** with no stale Toyota
  entries. Livewire 4's move of this fetch to async actions did not regress it.

### What the plan got right

Splitting Laravel 13 from Filament 5 worked exactly as intended, and migrating
the deprecated `->actions()` / `->bulkActions()` builders **while still on
Filament 4** meant the official `vendor/bin/filament-v5` script found **nothing
left to rewrite** — it processed 51 files and changed zero. The riskiest stage
turned out to be the cheapest, because the risk had already been retired on a
version where the old and new APIs both worked and the suite could prove it.

### What the plan did not predict

Three issues surfaced only by executing, none of them caused by the new versions
— all pre-existing problems that the upgrade exposed:

1. **The test suite crashed with `Premature end of PHP process`** on a GD-heavy
   test, immediately after Filament 5 landed. Not a Filament bug: the container
   ran at PHP's default `memory_limit = 128M`, and one test allocates a
   3000×2000 truecolor image (~24 MB in GD). Filament 5's larger baseline was
   simply the straw. Investigating it surfaced two further contradictions
   between PHP's defaults and the app's own advertised limits —
   `upload_max_filesize = 2M` against a CSV form promising 5 MB, and
   `max_execution_time = 30` against a 50-second bulk-run cap that therefore
   could never be reached. Fixed with `docker/php/php.ini`.

2. **Livewire 4 moved every route to an `APP_KEY`-derived prefix**
   (`/livewire-c3b9adb8/...` instead of `/livewire/...`). `DEPLOYMENT.md`
   hardcoded a SiteGround `.htaccess` rewrite for `/livewire/livewire.js` — the
   very rule that exists to stop the Filament login breaking on shared hosting.
   Confirmed empirically that the prefix changes when `APP_KEY` changes, so it
   can never be hardcoded; the rule is now the pattern
   `^/livewire(-[A-Za-z0-9]+)?/`. **This would have broken production login
   while every test stayed green.**

3. **`VerifyCsrfToken` was in use after all.** The plan's first draft claimed the
   Laravel 13 CSRF rename did not affect this codebase. A grep proved otherwise:
   `AdminPanelProvider` registered it directly in the panel middleware stack.
   Reading `PreventRequestForgery` showed its checks are OR-ed
   (`hasValidOrigin() || tokensMatch()`), so the change is backwards compatible —
   and that the same condition contains `runningUnitTests()`, which is why no
   test can ever exercise this path.

### Lesson for the next upgrade

Every one of those three was invisible to a green test suite. Two were caught
only by driving real HTTP, and one only by reading framework source. Keep the
suite as the gate for *behaviour*, but budget explicit time for
environment-level and transport-level verification — that is where major
upgrades actually break.

---

## 1. Objective

Move the application onto the current release of every dependency —
Laravel 13, Filament 5, Livewire 4 — without regressing behaviour, and
clear the outstanding security advisories on the way.

The controlling constraint is **no silent breakage**. Every stage below
therefore ends with a verification gate that must pass before the next
stage begins. A stage that fails its gate is rolled back, not patched
forward.

---

## 2. Current state

Two separate problems, often confused:

| | `composer.lock` says | `vendor/` actually has |
| --- | --- | --- |
| laravel/framework | v12.61.0 | **v12.38.1** |
| filament/filament | v4.11.6 | **v4.2.0** |
| livewire/livewire | v3.8.0 | **v3.6.4** |
| guzzlehttp/guzzle | 7.10.5 | 7.10.0 |
| league/commonmark | 2.8.2 | 2.7.1 |

`vendor/` was never reinstalled after commit `41b6375`
("chore(deps): update to latest Laravel 12.x compatible releases"), so the
code that runs is months behind the code that is pinned.

**Security consequence.** `composer audit` reports:

- against `vendor/` (what actually runs): **47 advisories / 18 packages — 9 high**
- against `composer.lock` (what is pinned): **18 advisories / 4 packages — 5 high**

The 29-advisory difference is entirely Filament: 4.2.0 is affected by the
`ImageColumn` XSS advisory (CVE-2026-48167, fixed in 4.11.5), the
unauthenticated temporary-file-upload advisory (CVE-2026-48500), and the
MFA recovery-code advisories. 4.11.6 is not. **`composer install` alone
resolves those 29.**

The 5 high advisories that survive in the lock are Guzzle
(non-canonical host bypass, needs ≥ 7.15.2) and four CommonMark
denial-of-service advisories (needs ≥ 2.9.0). Stage 1 clears them.

---

## 3. Target state

| Package | From | To | PHP floor |
| --- | --- | --- | --- |
| laravel/framework | 12.67.0 | **13.26.1** | ^8.3 |
| filament/* | 4.12.6 | **5.7.6** | ^8.2 |
| livewire/livewire | 3.8.5 | **4.4.1** | ^8.1 |
| laravel/tinker | 2.11.1 | **3.0.2** | ^8.1 |
| phpunit/phpunit | 11.5.56 | **12.5.33** | — |

Effective PHP floor is **8.3**, set by Laravel 13 (`php ^8.3`). The project
already requires 8.3 in practice, so no host change is needed — but
`composer.json` still declares `^8.2` and must be corrected (§7).

### 3.1 Compatibility matrix (verified, not assumed)

Read from the registry with `composer show -a`:

| Package / version | `illuminate/contracts` | `livewire/livewire` |
| --- | --- | --- |
| `filament/support` **4.12.6** | `^11.28｜^12.0｜^13.0` | `^3.5` |
| `filament/support` **5.7.6** | `^11.28｜^12.0｜^13.0` | `^4.1` |
| `livewire/livewire` **4.4.1** | `^10.0｜^11.0｜^12.0｜^13.0` | — |

**The two major upgrades are decoupled.** This is the single most useful
fact in this document:

- Filament 4.12 **already supports Laravel 13**. So Laravel can go to 13
  while Filament stays on 4 and Livewire stays on 3.
- Filament 5 supports Laravel 12 **and** 13, but forces Livewire 3 → 4.

That means Laravel 13 and Filament 5 can be landed and verified as two
independent steps, each with its own rollback point, instead of one
change that alters the framework, the admin panel, and the frontend
runtime simultaneously.

> **Correction to the README.** Before this review the README stated that
> Laravel 13 was "not yet adopted because Filament 4 and Livewire 3 do not
> support it". That is no longer true — Filament 4.12 and Livewire 3.8
> both declare Laravel 13 support. The README must be updated when
> Stage 2 lands.

---

## 4. Verification harness

"Nothing will be broken" is only meaningful if it is checked. The gate
for every stage is the same command:

```bash
php artisan test          # must be 95/95 green
vendor/bin/pint --test    # must report no style drift
composer audit            # advisory count must not increase
```

### 4.1 The suite as it stands

| | Tests | Covers |
| --- | --- | --- |
| `tests/Unit` | 34 | Filename building, image resizing, make relevance, model-string normalization |
| `tests/Feature` | 61 | CSV import, ZIP/CSV export, Wikimedia block handling and recall fallback, resource scoping, Results actions, **panel smoke, search-form behaviour** |

The two bolded suites were added by this review specifically as upgrade
insurance (§4.2). Total: **95 tests**.

### 4.2 Tests added as the safety net

`tests/Feature/Filament/PanelSmokeTest.php` — 16 tests, one per page in
the panel, each asserting only that the page mounts and renders for an
authenticated admin. This is the highest-value guard for a Filament major
upgrade: when a builder method is renamed or removed, the resource still
compiles and only fails when Livewire mounts it. Before this file, no
test mounted `ListCarImages`, `ListCarMakes`, `CreateCarMake`,
`EditCarMake`, `ListUsers`, `CreateUser`, `EditUser`, `ListCarSearches`,
`CreateCarSearch`, `ViewCarSearch`, or `EditCarSearch` at all — an
upgrade could have broken eleven pages with a green suite.

`tests/Feature/Filament/CarSearchFormTest.php` — 4 tests pinning the
`__all__` sentinel round-trip (`dehydrateStateUsing` → NULL on save,
`afterStateHydrated` → `__all__` on edit), year-range normalization, and
completed-search reuse. These are Filament-lifecycle behaviours with no
deprecation path: if v5 changes when those hooks fire, the literal string
`"__all__"` starts reaching the database and poisoning Wikimedia queries.

### 4.3 Proving the safety net actually catches things

A characterization test passes before *and* after by design, so passing
proves nothing on its own. Each must be shown to fail when the behaviour
it guards is removed. Run once, before Stage 1:

| Test | Mutation that must turn it red |
| --- | --- |
| `test_all_sentinel_options_are_persisted_as_null` | Delete the `dehydrateStateUsing()` call on the `model` select in `CarSearchResource::form()` |
| `test_editing_a_search_hydrates_the_all_sentinel_for_null_filters` | Delete the `afterStateHydrated()` call on the same select |
| `test_reversed_year_range_is_normalized` | Remove the swap in `CarImageSearchService::createSearch()` |
| `test_identical_completed_search_is_reused_without_calling_wikimedia` | Make `handleRecordCreation()` skip the `findExistingCompletedSearch()` short-circuit |
| `PanelSmokeTest::*` | Rename any builder method used by the resource under test |

Revert each mutation after confirming red. A test that stays green under
its mutation is not protecting anything and must be fixed before it is
relied on as an upgrade gate.

---

## 5. Stages

### Stage 0 — make the suite runnable *(done)*

> **Correction (post-review).** This section originally claimed the Docker
> image lacked `pdo_sqlite` and that all 61 feature tests errored with
> `could not find driver`. **That was wrong.** `php:8.3-apache` compiles
> `pdo_sqlite` in statically (no `.so`, no ini file), so the tests always
> ran inside the container — the running image, built 2026-05-06, proves it.
> The driver was missing only on the *host* PHP used for ad-hoc runs, which
> is a developer-machine issue, not a project one. The `Dockerfile` change
> made here was a no-op and has been reverted. This stage's real content is
> the `APP_KEY` fix below.

- `phpunit.xml` set no `APP_KEY`, so the suite depended on an untracked
  `.env` and would die in CI with `MissingAppKeyException`. Fixed with a
  fixed throwaway key.
- `phpunit.xml` also inherited the real `.env` Wikimedia retry backoff
  (5 retries, 2000ms exponential base), which made one failure-path test
  sleep 2+4+8+16 = 30 real seconds. Retry values are now pinned for tests.

**Lesson recorded deliberately:** the Dockerfile claim survived because the
change was never built — `docker compose exec` reuses the existing image.
Verify an image change by building it, not by reasoning about it.

**Gate:** rebuild the image and confirm the suite runs at all.

```bash
docker compose build app
docker compose up -d
docker compose exec app composer install     # also syncs vendor/ to the lock
docker compose exec app php artisan test     # expect 95/95
```

That `composer install` is also the cheapest security win available — it
clears the 29 Filament advisories described in §2 with no code change.

### Stage 1 — in-major update (no code changes expected)

Stays inside the existing `composer.json` constraints (`^12.0`, `~4.0`),
so no application code is affected.

```bash
git switch -c chore/deps-stage-1
composer update --with-all-dependencies
```

Verified by dry run to produce:

| Package | From → To | Why it matters |
| --- | --- | --- |
| laravel/framework | 12.61.0 → **12.67.0** | patch/minor only |
| filament/* | 4.11.6 → **4.12.6** | minor; adds Laravel 13 support |
| **guzzlehttp/guzzle** | 7.10.5 → **7.15.3** | clears the high host-bypass advisory |
| **league/commonmark** | 2.8.2 → **2.10.0** | clears 4 high DoS advisories |
| guzzlehttp/psr7 | 2.10.4 → 2.13.0 | clears CRLF-injection advisories *(Stage 1 projection; Stage 3 superseded this with 3.0.0, pulled up by Guzzle 8)* |
| symfony/mime | 7.4.13 → 7.4.17 | clears the header-injection advisory |
| livewire/livewire | 3.8.0 → 3.8.5 | patch |

That the resolver completed at all is itself evidence: Composer blocks
resolution against packages with known advisories, so a successful
`composer update` means the resulting set is advisory-clean for the
blocking severities.

**Gate:** `php artisan test` (95/95), `composer audit`, `vendor/bin/pint --test`.
**Risk:** low. Highest-risk element is several Symfony components moving
7.4 → 8.1, which Laravel 12 explicitly permits (`^7.0|^8.0`).

### Stage 2 — Laravel 12 → 13

Filament stays on 4.12, Livewire stays on 3.8. Framework-only change.

```bash
git switch -c chore/laravel-13
composer require laravel/framework:"^13.0" laravel/tinker:"^3.0" --no-update
composer require phpunit/phpunit:"^12.0" --dev --no-update
composer update --with-all-dependencies
```

Breaking changes from the official guide, filtered to this codebase:

| Change | Impact here | Action |
| --- | --- | --- |
| **CSRF middleware renamed** `VerifyCsrfToken` → `PreventRequestForgery`, now verifies `Sec-Fetch-Site` | **High, and it applies here.** `AdminPanelProvider.php:17,50` registers `VerifyCsrfToken::class` directly in the panel's middleware stack. Laravel 13 keeps `VerifyCsrfToken` as a deprecated alias so the panel will still boot, but it is now the origin-verifying middleware in front of every panel request | Replace the import and the stack entry with `PreventRequestForgery::class`. Then verify login **and** a Livewire round-trip: this middleware sits in front of `/livewire/update`, which is how every table, form, and modal in the panel communicates. This is the single riskiest item in Stage 2 |
| `laravel/tinker` → ^3.0, `phpunit/phpunit` → ^12.0 | Required | Included above |
| Cache/session key prefixes now hyphenated | Low — cold cache and forced re-login unless `CACHE_PREFIX` / `SESSION_COOKIE` are pinned | Accept the cold cache (Wikimedia results simply re-fetch), or pin both in `.env` |
| `config/session.php` `serialization` defaults to `json` in the new skeleton | Low | Do **not** sync this key, or accept that all sessions invalidate |
| Cache `serializable_classes` now `false` | Medium in general; **none here** — only arrays are cached (`WikimediaClient` caches `Collection`→array data) | Confirm the Wikimedia cache still hydrates after upgrade; `WikimediaRecallFallbackTest` covers this |
| `upsert()` now validates non-empty `uniqueBy` | **None** — the code uses `updateOrCreate()` and `insert()`, never `upsert()` | — |
| `Str` factories reset between tests | Test-only, none in use | — |
| `symfony/polyfill-php85` defines `array_first`/`array_last` | **None** — `laravel/helpers` is not installed | — |

**Gate:** the §4 commands, plus manual confirmation of login and one
Livewire round-trip (paginate a table) because of the CSRF change.
`PanelSmokeTest` covers page mounting, but it exercises Livewire
in-process and does **not** traverse the HTTP middleware stack — so the
`Sec-Fetch-Site` behaviour can only be confirmed in a browser.
**Risk:** medium, concentrated entirely in the CSRF/origin change.
The official guide estimates 10 minutes; budget more than that for the
middleware verification.

### Stage 3 — Filament 4 → 5 (+ Livewire 3 → 4)

The only stage that will require application code changes. Do it alone,
after Stage 2 is merged and green.

```bash
git switch -c chore/filament-5
composer require filament/upgrade:"^5.0" -W --dev
vendor/bin/filament-v5                                   # automated rewrite
composer require filament/filament:"^5.0" -W --no-update
composer update
php artisan filament:upgrade
```

Requirements per the official guide: **PHP 8.2+, Laravel 11.28+,
Livewire 4.0+, Tailwind CSS 4.0+**.

Known work in this codebase, from the review:

1. **Migrate the deprecated table API first, on Filament 4, as its own
   commit.** `->actions()` / `->bulkActions()` are deprecated in v4 in
   favour of `->recordActions()` / `->toolbarActions()`, and are still
   used in `CarImageResource` (lines 80, 102), `CarMakeResource` (64, 67),
   `UserResource` (64, 67), `CarSearchResource` (196, 199), and
   `CarImagesRelationManager` (63, 85). `CsvImportResource`,
   `SearchQueryResource`, and `Results` already use the v4 names, so the
   codebase is inconsistent with itself. Doing this on v4 — where both
   names work — means the change is verifiable by the existing suite
   *before* the framework moves under it. Whether v5 removes the old
   names outright should be confirmed against the v5 guide; either way
   this is the safe order.
2. **Livewire 3 → 4 is a major in its own right.** It is the transport
   for every table, form, modal, and notification in the panel, and it is
   what makes `PanelSmokeTest` worth having.
3. **Tailwind CSS 4 is already satisfied.** `package.json` already pins
   `tailwindcss ^4.0.0` and `@tailwindcss/vite ^4.0.0`, and
   `AdminPanelProvider` registers no custom Filament theme
   (`resources/css/app.css` is the application's own stylesheet, not a
   panel theme). This requirement costs nothing here.
4. **Re-check `->poll()` intervals.** `SearchQueryResource` polls every
   3s and `CarImagesRelationManager` every 1s; polling internals are a
   common major-version change.

**Gate:** the §4 commands, **plus** a manual pass of the checklist in §6.
Automated tests cannot see styling, modal behaviour, or file downloads.
**Risk:** high. This is the stage to expect real work in.

### Stage 4 — remaining tooling

`laravel/pint` 1.30.5, `laravel/sail` 1.67.0, `mockery` 1.6.15,
`nunomaduro/collision` 8.9.5 all ride along with Stage 1. `phpunit/phpunit`
12 and `laravel/tinker` 3 land with Stage 2. Nothing is left over.

---

## 6. Manual QA checklist (Stage 3 gate)

The automated suite does not assert on rendered styling, modal
interaction, or binary downloads. Walk these once against a seeded
database:

- [ ] Log in at `/admin`; confirm styling is present (a Tailwind 4 or
      asset-publishing failure shows up as an unstyled panel)
- [ ] Upload a CSV from `sample/`; confirm the import summary notification
- [ ] Run one query, then a bulk **Run Selected**; confirm the live loader
      and the status badge transitions
- [ ] On Results: paginate, sort, search, and apply the **Make match**
      filter — this is also where the `searchId` scoping bug (see the
      review) will show itself
- [ ] **Download Selected as ZIP** — open the archive, confirm the
      `YEAR MAKE MODEL.jpg` names and that images are resized
- [ ] **Download Confirmed as ZIP** on a selection with no confirmed
      images — confirm the warning notification rather than an error
- [ ] **Export Selected as CSV** — confirm the filenames match the ZIP
- [ ] Ad-hoc search: create one with **All models / colors / transmissions**,
      then edit it and confirm the dropdowns still read "All ..."
- [ ] Single-image **Preview** modal and its **Download** button
- [ ] Create and edit an admin user; confirm blank-password-keeps-existing

---

## 7. Companion fixes this upgrade unblocks

`composer.json` declares `php: ^8.2`, but the true floor is **8.3**
(`filament/actions` → `openspout/openspout` requires
`~8.3.0 || ~8.4.0 || ~8.5.0`), and Laravel 13 makes it explicit. It also
declares no `ext-*` requirements even though the app hard-depends on GD
(`ImageResizer`), ZipArchive (`BatchZipBuilder`), and intl
(`filament/support`).

Both fixes were deliberately deferred: changing `require` invalidates the
lock's content hash, and re-locking currently **fails** because Composer
refuses to resolve against the advisory-affected packages in §2. Apply
them as part of Stage 1, once the resolver is unblocked:

```json
"require": {
    "php": "^8.3",
    "ext-gd": "*",
    "ext-intl": "*",
    "ext-zip": "*",
    ...
},
"require-dev": {
    "ext-pdo_sqlite": "*",
    ...
}
```

Then `composer validate --strict` and `composer check-platform-reqs`
should both pass — and belong in CI.

---

## 8. Rollback

Every stage is one branch and two tracked files (`composer.json`,
`composer.lock`). To abandon any stage:

```bash
git checkout composer.json composer.lock
composer install                  # restores the previous vendor/ exactly
php artisan filament:upgrade      # republishes the matching panel assets
php artisan optimize:clear
```

Nothing in Stages 1–4 writes to the database, so no migration rollback is
involved. Do not squash the stages into one commit: the value of the
staging is that a failed gate identifies which upgrade caused it.

---

## 9. Recommendation

Land **Stage 0 and Stage 1 immediately** — Stage 0 clears 29 advisories
and Stage 1 clears the remaining 5 high ones, with no application code
change and low risk. Treat them as a security fix, not a version-number
exercise.

Land **Stage 2 (Laravel 13)** next. It is genuinely small for this
codebase — the only high-impact change in the entire guide, the CSRF
rename, matches nothing in `app/`, `bootstrap/`, or `tests/`.

Schedule **Stage 3 (Filament 5)** as its own piece of work, preceded by
the deprecated-API migration on v4. It carries a Livewire major with it
and is the one stage where "make sure nothing breaks" depends on the
manual checklist as much as on the suite.

Do not run Stages 2 or 3 until `php artisan test` reports 95/95 in a
real environment. That gate is the whole point of Stage 0.
