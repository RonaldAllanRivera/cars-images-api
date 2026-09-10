# CSV Imports and Coverage — Design

**Date:** 2026-09-10
**Status:** Approved (pending implementation plan)
**Project:** cars-images-api (Laravel 13 + Filament 5 + Expo 57)
**Sub-project:** P3a of the programme — see the
[P1 spec's programme table](2026-09-09-mobile-design-system-and-navigation-design.md#programme)

## Goal

Give the mobile client the CSV pipeline's **read** half and its one write that
is not a crawl: see what was imported, see how much of it has actually been
searched, and upload a new CSV. Adds the fifth tab.

## Why P3 is two sub-projects

P3 was scoped as "the CSV pipeline". Reading
[`ListSearchQueries::runNextChunk()`](../../../app/Filament/Resources/SearchQueryResource/Pages/ListSearchQueries.php)
shows two halves with different risk profiles:

```
startBulkRun(ids)      →  seeds a client-held queue, returns immediately
wire:poll every 3s     →  runNextChunk()
  └─ per request: up to 50 queries OR 10s wall-clock, whichever comes first
     ├─ RunSearchQueryAction::execute()   synchronous Commons call
     ├─ sleep(1) between queries          courtesy pacing
     └─ WikimediaBlockedException → halt the run, report Retry-After
```

Imports and coverage are reads plus one multipart upload. The bulk run is a
ten-second-per-request loop containing `sleep()` and synchronous Commons calls,
driven from a phone on a network that drops, and it is the **third path to a
Wikimedia block** after the search form and the ZIP export. Reviewing that
alongside eight screens would bury it.

**P3a is this spec. P3b — the run verb, the chunk endpoint, progress, pause and
block handling — gets its own.** Nothing here can start a crawl.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Query listing | **Extend `GET /searches`**, not `GET /imports/{id}/searches` | See below — it also repairs an existing defect. |
| Coverage | **Extract to a service** consumed by both the panel and the API | Two implementations of the same question is how they drift. |
| Ability issuance | **`defaultScope()` frozen; clients request explicitly** | See below. |
| `imports:read` on web | **Yes** | A Pipeline tab that 403s on the public demo is worse than absent. |
| `imports:write` on web | **No** | Upload is the verb that seeds hundreds of queries. |
| Standalone all-queries screen | **Cut** | Per-import is the useful cut on a phone; "every CSV query ever" is a desktop table. The endpoint supports it (`source=csv` with no `csv_import_id`), so it is a screen away if wanted. |
| Run verb | **Out of scope** | P3b. |

### Extending `GET /searches` repairs a defect

`GET /searches` returns **every** `CarSearch`, ad-hoc and CSV-derived together.
The panel splits them deliberately —
[`SearchQueryResource::getEloquentQuery()`](../../../app/Filament/Resources/SearchQueryResource.php#L37)
is `whereNotNull('csv_import_id')`, and `CarSearchResource` is the complement —
and the API lost that distinction. P1 identified it and left it.

Three filters restore it and serve both screens from one endpoint:

| Parameter | Values | Mirrors |
|---|---|---|
| `source` | `csv`, `adhoc` | The two resources' base queries |
| `csv_import_id` | integer | The panel's "CSV Import" filter |
| `coverage` | `with_images`, `no_images`, `not_run` | The panel's coverage filter |

`coverage` is the one worth keeping verbatim, because it answers what `status`
cannot: a search that ran and found nothing is `completed`, exactly like one
that found five images. Its semantics, copied from the panel:

- `with_images` — `whereHas('images')`
- `no_images` — `status = completed` and `whereDoesntHave('images')`
- `not_run` — `status` in `pending`, `running`

**Consequence for P1's screens:** the Search tab's run list becomes
`source=adhoc`, completing the split P1 intended but could not finish without
this parameter.

### Freezing `defaultScope()`

The Pipeline tab needs `imports:read`. The tempting move is to add it to
`TokenAbilities::defaultScope()` — but that is precisely the widening
[P2](2026-09-10-api-authorization-scoping-design.md) exists to prevent, and it
would make `mobile/src/api/__fixtures__/login.json` churn on every project that
adds a capability.

Instead:

> **`defaultScope()` is permanently the four legacy abilities — a compatibility
> floor for clients that predate scoping. Every current client declares what it
> needs.**

| Build | Requests |
|---|---|
| Web | `search:read`, `search:write`, `review:write`, `errors:read`, `imports:read` |
| Native | those five plus `imports:write` |

P2 already widened `TokenAbilitySchema` to all eight, so the response parses
today. The only client change is `AuthContext.signIn` passing an `abilities`
array — and because the fixture capture posts no `abilities`,
`ContractFixturesTest` stays green **without regeneration**, exactly as in P2.

## API

### `GET /imports`

Cursor paginated, newest first. `imports:read`.

`ImportResource`: `id`, `original_filename`, `total_rows`, `unique_combos`,
`duplicates_skipped`, `imported_by`, `importer_name`, `searches_count`,
`created_at`.

`importer_name` rather than a nested user object: the list needs one string,
and `UserResource` would drag `email` into a screen that has no use for it.

**No coverage on the list.** Coverage is six aggregate counts per import; doing
that for every row is a per-row fan-out on a screen that only needs to identify
the import. It belongs on the detail endpoint.

### `GET /imports/{id}`

The same resource plus a `coverage` object. `imports:read`.

```
coverage: { total, searched, not_run, failed, with_images, no_images }
```

Snake case to match every other field on the wire.

### `POST /imports`

Multipart, field `csv_file`. `imports:write`. Throttled at the tightest
authenticated rate.

Delegates to the existing
[`CsvQueryImporter`](../../../app/Services/Imports/CsvQueryImporter.php),
which already enforces the caps and already logs rejections to `error_events`
under `csv_upload`. The endpoint adds no acceptance rules of its own beyond the
`create` policy check: it rejects exactly the uploads the panel rejects, with
`CsvImportException` mapped to 422.

Limits, all from `config('cars-images.*')` so the API and the panel cannot
drift: `csv_import_max_upload_kb`, `csv_import_max_combos`,
`csv_import_max_projected_images`.

Returns 201 with the created import and its (all-zero) coverage.

### `GET /searches` — extended

`ListSearchesRequest` gains the three parameters above. Existing behaviour with
none of them is unchanged, so nothing already deployed shifts.

### Authorization

Two gates, as [P2](2026-09-10-api-authorization-scoping-design.md) framed them:
the token ability says *this device may attempt it*, the policy says *this
human may*. `imports:*` is the first ability whose endpoints exist, so this is
where its policy lands.

`CsvImportPolicy` gains `viewAny`, `view` and `create`. Like the two policies
already in `app/Policies/`, it is permissive for now — every authenticated user
is an admin, exactly as the panel treats them — and the class exists so that
visibility is a decision written down rather than an accident of having no
policy. Deliberately no `delete`: nothing in this programme lets a mobile token
destroy anything, so the method's absence is the enforcement.

## Coverage service

[`Results::coverage()`](../../../app/Filament/Pages/Results.php) is a Filament
`Page` method; the API cannot call it. It moves to
`App\Services\Imports\ImportCoverage`, returning the six counts for an optional
import id.

The Filament page keeps its own concerns — the import name, and the deep-link
URLs into the filtered Search Queries list — layered on top of the shared
counts. Those URLs are `route('filament.admin...')` and have no meaning to a
mobile client.

The rebuilt-per-count structure moves across verbatim: the existing code
rebuilds the base query for each count rather than cloning, because `whereHas`
on a shared builder leaks its subquery into the counts that follow. That is a
real bug the current code avoids on purpose, and the comment travels with it.

## Screens

```
mobile/app/(app)/pipeline/_layout.tsx     Stack
mobile/app/(app)/pipeline/index.tsx       imports list
mobile/app/(app)/pipeline/[id].tsx        import detail: coverage + its queries
mobile/app/(app)/pipeline/upload.tsx      CSV upload
```

Tabs become **Search / Library / Pipeline / Review / Health** — the five P1
designed for, arriving with the screens that fill them. `pipeline/` needs its
own `_layout.tsx` with a `<Stack>` or expo-router flattens it into the tab bar.

**Import detail carries the queries inline**, filtered by the coverage chips,
rather than pushing to a separate screen. It keeps the panel's most useful
interaction — read "23 not run yet", tap it, see exactly those 23 — in one
place, and avoids a second `[id]` segment colliding with the first.

**Upload** uses `expo-document-picker` (new, inside `mobile/` only). Before the
picker opens, the screen states what a full CSV costs, using the same
config-derived figures as
[`CreateCsvImport::apiDownloadCostNote()`](../../../app/Filament/Resources/CsvImportResource/Pages/CreateCsvImport.php).
An upload commits the app to a long paced crawl later; saying so at the point
of decision is the panel's behaviour and worth keeping.

## Testing

**Laravel.** Each endpoint: happy path, ability gate (403 without it), and
validation. Coverage gets its own unit tests against the four statuses —
including the case that motivates it, `completed` with no images. The upload
tests reuse the fixtures `CsvQueryImporterTest` already builds.

`ContractFixturesTest` gains `imports` and `import` fixtures. It must stay green
on `login.json` **without regeneration** — the capture posts no `abilities`.

**Mobile.** Zod schemas for the two new payloads. The coverage chips and the
cost note are presentational and mountable. The screens use `Link`/`useRouter`
and cannot be mounted, so their logic goes in hooks and presentational
components — the pattern P1 established with `ReviewCard`.

Constraints unchanged: `render()` is synchronous, never awaited;
`jest.config.js` untouched; `gcTime: 0` for observed queries, `Infinity` when
seeding, `mutations: { gcTime: 0 }` separately.

## Risks

| Risk | Mitigation |
|---|---|
| Extending `GET /searches` changes existing behaviour | The three parameters are all `sometimes`; absent, the query is what it is today. Covered by keeping the existing tests untouched. |
| Coverage extraction changes the panel's numbers | The service is extracted verbatim, including the rebuild-per-count structure, and the panel's existing behaviour is asserted before and after. |
| A large CSV times out on upload | Unchanged from the panel: the same size and combo caps apply, and the importer is not slow — it parses and inserts, it does not crawl. |
| `imports:read` on web exposes importer names | `importer_name` is an admin's display name, already visible to any authenticated panel user. No email, no id beyond `imported_by`. |
| The fifth tab crowds the tab bar | Five is the platform maximum before iOS collapses into its own "More", so the budget is used, not exceeded. |

## Out of scope

No run verb, no chunk endpoint, no progress UI, no `search:run` — all P3b. No
import deletion: cut programme-wide, since no mobile token destroys anything.
No standalone all-queries screen.
