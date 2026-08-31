# Commons Category Retrieval — Design

**Date:** 2026-08-31
**Status:** Approved (pending implementation plan)
**Project:** cars-images-api (Laravel 12 + Filament 4)

## Goal

Replace Wikimedia full-text search with Commons **category-tree** retrieval, so that every stored image is a photograph of the requested make, model, and **exact model year** — or no image is stored at all.

Precision is the explicit priority. A row that returns nothing is a correct outcome; a row that returns a plausible-looking wrong car is not.

## What is wrong today

A search for `Acura 2.3CL/3.0CL` across 1997–1999 produced three rows showing the **same photograph**, and that photograph is not an Acura CL:

```
File:Clx.jpg  →  Category:Acura CL-X
                 description: "Acura CL-X concept car"
```

Three defects compound to produce it:

1. **Full-text search is the wrong retrieval primitive.** `generator=search` ranks by term overlap across a global media library. Querying `Acura CL 1997 car` returns ten Honda Accords, because the Accord chassis code `CL3` contains the token `CL`. The actual Acura CL photographs rank below them and are never seen.
2. **The year-relaxed fallback is year-independent.** When a year-specific query finds nothing usable, `buildQuery(year: null)` produces `Acura CL car` — the identical string for every year, drawing the identical cache entry. Each year in the range receives the same file, stamped with whatever year the loop was on.
3. **Off-make rejection compensates downstream for a bad upstream choice.** `MakeRelevanceChecker` exists only to discard the wrong-make results that full-text search invites.

The `year_confirmed` flag added on 2026-08-31 made defect 2 visible but did not fix it.

## Evidence: Commons has the right data, in the right shape

Commons maintains a curated generation category tree the application never queried:

```
Category:Acura CL
├── Category:Acura CL YA1   ← 1997–1999, 17 files
└── Category:Acura CL YA4   ← 2001–2003, 11 files
```

`deepcategory:"Acura CL"` returns **29 files, zero Hondas**. Category membership is human-curated ground truth; full-text relevance is a statistical guess.

Within `Acura CL YA1`, file titles carry the model year:

| Title | Model year |
|---|---|
| `1997 Acura CL -- 01-28-2010.jpg` | 1997 (`01-28-2010` is the photo date) |
| `1997 Acura CL, rear 8.2.20.jpg` | 1997 |
| `1999 Acura CL 3.0.jpg` | 1999 |
| `1999 Acura CL.jpg` | 1999 |
| `1998-1999 Acura CL -- 04-11-2012 1.JPG` | range — excluded |
| `1st gen Acura CL.JPG` | none — excluded |

## Constraints

Inherited from `2026-05-18-csv-bulk-search-and-download-design.md` and re-verified:

- **`QUEUE_CONNECTION=sync`.** There is no queue worker and no cron on SiteGround shared hosting. The classes in `app/Jobs/` are dead code — nothing dispatches them. All work runs synchronously inside the Filament request, paced by explicit admin clicks.
- **Wikimedia blocking risk.** Bulk reads can trigger 429/503. Category resolution adds API calls per *new* make/model, so it must be cached persistently rather than recomputed.
- **No AI.** Year and make determination is deterministic string logic, reviewable by hand.

## Measured coverage

Random sample of 30 rows drawn from the 23,994 distinct `(make, model, year)` combinations in
`COMPLETE LIST = ALL VEHICLES - sorted - 4 columns.csv` (47,523 raw rows, 5,136 distinct make/model pairs):

| Outcome | Rows | Share |
|---|---|---|
| **Exact-year photograph found** | **11** | **36%** |
| Category resolved, files present, none names the year | 9 | 30% |
| No category resolved from the CSV model string | 8 | 27% |
| Category exists but is empty | 2 | 7% |

Where exact matches exist there are usually several — Saab 9-4X 11, Suzuki Kizashi 8, Cadillac STS 6, Pontiac Vibe 4 — so distinct images per year are comfortably available.

**36% of rows will store an image; 64% will store nothing.** This is the accepted trade.

> An earlier measurement reported 50%. It was wrong: the naive regex counted photo dates as model years (`Hyundai Santa Fe` 4→1, `Suzuki Kizashi` 21→8, `Audi SQ8` 1→0). 36% is the figure produced by the matcher specified below.

### Why category resolution is the real bottleneck

The CSV carries EPA trim, drivetrain, and body qualifiers that Commons category names never use:
`Santa Fe XL AWD`, `328i xDrive`, `F150 Pickup 2WD FFV`, `Cooper Hardtop 2 door`, `K20 Pickup 4WD`.

`ModelSearchTermNormalizer` only strips a leading engine displacement, so it resolved **5 of 30** categories.
Stripping qualifiers and then shrinking the token prefix resolved **22 of 30**, and the results are correct:
`Hyundai Santa Fe`, `Land Rover Range Rover`, `BMW X5`, `Cadillac STS`, `Saab 9-4X`, `Ford F150`.

Letting Commons resolve the category itself (namespace-14 search) was tried and **rejected**: it scored worse
(17/30) and returned confidently wrong neighbours — `Mini Cooper Hardtop 2 door` → `Category:Mini Cooper`,
`Mitsubishi Truck 2WD` → `Category:Mitsubishi` (the entire brand). Deterministic prefix-stripping is testable
offline with zero API calls and cacheable per make/model; the search path is neither.

## Architecture

```
CarSearch (make, model, year)
   │
   ├─ CommonsCategoryResolver::candidates(make, model)     pure, no I/O
   │     "Santa Fe XL AWD"
   │       → ["Hyundai Santa Fe XL AWD", "Hyundai Santa Fe XL", "Hyundai Santa Fe", "Hyundai Santa"]
   │
   ├─ CommonsCategoryLocator::locate(make, model)          persistent cache + I/O
   │     walks candidates longest-first, returns the first that exists
   │       → "Hyundai Santa Fe"
   │
   ├─ WikimediaClient::filesInCategory(category)           I/O, request-cached
   │     deepcategory: search, namespace 6, WHOLE category
   │       → image arrays in the existing mapPageToImage() shape
   │
   └─ ModelYearMatcher::modelYear(title, make)             pure, no I/O
         keep only files whose model year === the search year,
         THEN take images_per_year
```

**`images_per_year` is applied last, never as the fetch limit.** Retrieval must pull the whole category before
the year filter runs. `Category:Cadillac STS` holds 56 files, 6 of which name 2005; fetching `srlimit=10`
returns 10 arbitrary files and finds none of them. This is the same class of mistake as the original bug —
narrowing on the wrong side of a filter.

Each unit has one purpose, a narrow interface, and can be tested alone. The two pure units
(`CommonsCategoryResolver`, `ModelYearMatcher`) carry all the difficult logic and need no network.

### `CommonsCategoryResolver` (new, pure)

**Does:** turns a CSV `(make, model)` into an ordered list of candidate Commons category names, longest and
most specific first.

**Interface:** `candidates(string $make, string $model): array<int, string>`

**Depends on:** `ModelSearchTermNormalizer` (retained — it is the correct first step; `Category:Acura 2.3CL/3.0CL`
does not exist, `Category:Acura CL` does).

Steps, in order:

1. Normalize via `ModelSearchTermNormalizer` (`2.3CL/3.0CL` → `CL`).
2. Drop parenthetical asides (`(18 inch Wheels)`).
3. Emit the full string, then the string with qualifier tokens removed, then successively shorter token prefixes.
4. Prefix each with the make; de-duplicate, preserving order.

Qualifier tokens: `AWD 4WD 2WD FWD RWD xDrive sDrive quattro 4MATIC FFV MHEV PHEV EcoDiesel LWB SWB Pickup
Truck Van Wagon Convertible Cabriolet Roadster Coupe Sedan Hatchback Hardtop "Gran Turismo" "Gran Coupe"
"<n> door" "<n> inch Wheels" New`.

Candidate generation stops at one remaining token, so a bare make is never probed — this is what prevents the
`Mitsubishi Truck 2WD` → `Category:Mitsubishi` failure seen in the rejected search-based approach.

Three names sit close together and mean different things:
`CommonsCategoryResolver` (pure, generates candidates), `CommonsCategoryLocator` (I/O, picks the winner and
persists it), `CommonsCategoryLookup` (the Eloquent model for the cache table).

### `CommonsCategoryLocator` (new, I/O)

**Does:** turns a CSV `(make, model)` into the one Commons category to search, consulting and populating the
persistent cache so a given model is resolved at most once.

**Interface:** `locate(string $make, string $model): ?string`

**Depends on:** `CommonsCategoryResolver` for candidates, `WikimediaClient::categoryExists()` for the probe,
`CommonsCategoryLookup` for persistence.

1. Return the cached `category` if a fresh `CommonsCategoryLookup` row exists for `(make, model)`.
2. A cached row with a null `category` is a **known miss** — return null without probing, unless `checked_at` is
   older than `config('images.wikimedia.category_miss_ttl_days')` (default 30). Misses expire because Commons
   categories are created over time; hits do not, because a category that exists does not stop existing.
3. Otherwise walk `candidates()` longest-first, probing each with `categoryExists()`, and persist the first hit
   — or persist a miss if none match.

Writes happen **outside** the search transaction: a resolution is cache state, not search state, and must
survive a later failure in the same run.

### `ModelYearMatcher` (new, pure)

**Does:** extracts the model year a Commons file title asserts, or `null`.

**Interface:** `modelYear(string $title, string $make): ?int`

**Order is load-bearing:**

1. **Strip photo dates first** — `01-28-2010`, `2017.1.23`, `8.2.20`, `04-25-2026`. Skipping this step files a
   1997 Acura CL under 2010.
2. **Reject ranges** — `1997-1999`, `1998-99`, `'98-'99`. Strict exact-year policy excludes them.
3. **Take the leading year**, or the year immediately preceding the make name.

Verified correct against all 17 real titles in `Category:Acura CL YA1`.

### `WikimediaClient` (modified)

Loses `searchCars()`, `buildQuery()`, `clearSearchCache()` and `isCarImage()`. Gains:

- `categoryExists(string $category): bool`
- `filesInCategory(string $category): Collection` — the **entire** category, paginating on `sroffset` while the
  response carries a `continue` key, capped at `config('images.wikimedia.category_max_files')` (default 500) so
  a pathologically large tree cannot stall a synchronous request. Measured: `srlimit=200` returns all 56 files
  of `Category:Cadillac STS` with no `continue`; `srlimit=10` returns 10 and sets `continue`.

`mapPageToImage()`, the block-detection (429/403/503 → `WikimediaBlockedException`), the retry/backoff policy,
the `maxlag` parameter and the User-Agent header are all unchanged. The MIME filter is **retained** — Commons
categories do contain PDFs and DjVu files.

## Data flow

```
1. Admin clicks Run on a search query        (synchronous, as today)
2. Resolve category for (make, model)        cached in commons_category_lookups; ~5 API calls on first touch,
                                             0 thereafter for every other year of the same model
3. Fetch the WHOLE category                  cached under config('images.wikimedia.cache_ttl'), keyed on the
                                             category alone. No year in the key, and none needed: the year is
                                             applied in step 4, not in the query. Every year of a model reads
                                             this one entry and filters it differently.
4. Filter to exact-year matches              pure, no I/O
5. Take images_per_year, store as CarImages  year_confirmed = true by construction
```

Because a title naming 1997 cannot also name 1999, **uniqueness across years is guaranteed by construction**.
No cross-search de-duplication is required.

### API cost

Per *new* make/model: ~4 category-existence probes + 1 category listing ≈ 5 calls, then zero forever.
Per additional year of an already-resolved model: 0 calls beyond the cached category listing.

For the Acura CL example — three searches, one model — that is ~5 calls total, against 3–6 today. The cost
amortizes naturally across the admin-paced bulk-run flow; **no upfront 25k-call sweep is needed or wanted**,
which matters because there is no queue worker to run one on.

## Schema changes

### New table: `commons_category_lookups`

Persistent resolution cache. Deliberately separate from `car_models`, which holds the *curated* catalogue and
is keyed on catalogue names rather than raw CSV strings.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `make` | string | as it appears in the CSV |
| `model` | string | raw CSV model string, before normalization |
| `category` | string, nullable | resolved Commons category, without the `Category:` prefix |
| `checked_at` | timestamp | when the probe last ran; drives miss expiry |
| `created_at` / `updated_at` | timestamps | |

Unique on `(make, model)`. A row with `category` null records a **known miss**, so the 27% that resolve to
nothing are probed once rather than on every run. `checked_at` is a timestamp rather than a boolean so misses
can expire after `config('images.wikimedia.category_miss_ttl_days')` (default 30) — Commons categories are
created over time. Hits never expire.

### New column: `car_searches.commons_category`

Nullable string. Records what was resolved for this specific search. Without it, an empty result is
indistinguishable between "no category exists for this model" and "the category exists but holds no photograph
naming this year" — which are 27% and 30% of rows respectively, and call for different follow-up.

### `car_images` — unchanged

`year_confirmed` becomes `true` for every new row by construction. It is retained rather than dropped because
`false` and `null` now precisely identify legacy full-text rows, which is what the rollout purge selects on.

## What gets deleted

| Removed | Because |
|---|---|
| `WikimediaClient::searchCars()`, `buildQuery()` | Replaced by category retrieval |
| The year-relaxed fallback in `fetchAndStoreForYear()` | The defect this design exists to remove |
| `MakeRelevanceChecker::isOffMake()` **call site** | Category membership guarantees the make |
| `WikimediaClient::isCarImage()` keyword filter | Category membership guarantees the subject |

`MakeRelevanceChecker::isConfirmed()` is **kept** and still populates `make_confirmed`: it is a cheap guard
against a mis-resolved category such as `BMW Alpina`, where the tree may hold more than the searched make.
`ModelSearchTermNormalizer` is **kept** as the resolver's first step.

## Error handling

- **Wikimedia block (429/403/503)** — unchanged. `WikimediaBlockedException` propagates, `RunSearchQueryAction`
  records a `wikimedia_block_events` row and marks the search `failed`. Category probes are subject to the same
  handling, so a block during resolution is surfaced rather than cached as a miss.
- **No category resolved** — search completes with zero images; `commons_category` stays null.
- **Category resolved, no exact-year file** — search completes with zero images; `commons_category` is set.
- **Transaction semantics** — unchanged. `runSearch()` and `refreshSearch()` keep their existing single-transaction
  behaviour. `commons_category_lookups` writes are cache population, not search state, and must happen outside the
  search transaction so a later failure does not roll back a valid resolution.

## Testing

**Unit, no network:**

- `CommonsCategoryResolverTest` — candidate order and content for `2.3CL/3.0CL`, `Santa Fe XL AWD`,
  `F150 Pickup 2WD FFV`, `i4 eDrive35 Gran Coupe (18 inch Wheels)`; asserts a bare make is never emitted.
- `ModelYearMatcherTest` — seeded with all 17 real `Acura CL YA1` titles plus photo-date forms
  (`01-28-2010`, `2017.1.23`) and range forms (`1998-99`, `'98-'99`, `1997-1999`).

**Feature, `Http::fake`:**

- Resolution walks candidates longest-first and stops at the first that exists.
- A known miss is not re-probed on a second run.
- 1997 and 1999 store distinct, correct files; **1998 stores zero**.
- A block during category resolution raises `WikimediaBlockedException` and is recorded.
- **`images_per_year` does not truncate retrieval**: a category of 56 files with `images_per_year = 2` still
  filters all 56 and stores the 2 that name the year — regression guard for the fetch-limit mistake.

**Retired:** `WikimediaRecallFallbackTest`, `YearRelaxedFallbackTest`, `OffMakeFilteringTest` — all pin
behaviour of the deleted full-text path.

**Adapted, not retired:** `WikimediaImageFilterTest`. It covers the MIME filter, which is retained; it is
retargeted from `searchCars()` to `filesInCategory()`.

## Rollout

1. Migrate (`commons_category_lookups`, `car_searches.commons_category`).
2. Deploy. Existing rows are untouched and still show the concept car.
3. Purge legacy rows: `car_images` where `year_confirmed` is `false` or `null`.
4. Re-run affected searches through the normal admin flow; the lookup cache warms as they go.

## Out of scope

- Relaxing the year rule to accept ranges (`1998-1999` counting for 1998). Deliberately excluded by the
  exact-year decision; revisit with measured numbers if 36% proves too thin.
- Non-Wikimedia image providers.
- Backfilling `year_confirmed` on legacy rows — they are purged, not repaired.
- Removing the unused classes in `app/Jobs/`. Dead, but unrelated to this change.
