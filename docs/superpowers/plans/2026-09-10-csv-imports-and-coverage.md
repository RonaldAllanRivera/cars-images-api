# CSV Imports and Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the mobile client the CSV pipeline's read half plus upload — see
what was imported, how much of it has actually been searched, and add a new CSV
— behind a fifth tab.

**Architecture:** `Results::coverage()` moves into an `ImportCoverage` service
that the Filament page and a new `ImportController` both consume. `GET /searches`
gains `source`, `csv_import_id` and `coverage` filters rather than a nested
route, which also repairs the ad-hoc/CSV merge P1 identified. Clients start
declaring their abilities at login so `defaultScope()` can stay frozen.

**Tech Stack:** Laravel 13, Sanctum, PHPUnit; Expo 57, expo-router, React Query
5, Zod 4, NativeWind 4.

**Spec:** [`docs/superpowers/specs/2026-09-10-csv-imports-and-coverage-design.md`](../specs/2026-09-10-csv-imports-and-coverage-design.md)

## Global Constraints

- Laravel tests run in the container:
  `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test`
- **`vendor/bin/pint --test` is a CI gate.** Run it, not just the tests.
- Mobile tests run on the host from `mobile/`. **Never add a dependency to the
  repository-root `package.json`** — use `npx expo install` inside `mobile/`.
- `@testing-library/react-native` is pinned to 13.3.3: `render()` is
  synchronous. **Never `await render(...)`.**
- **`mobile/jest.config.js` must not be modified.** Use a per-file
  `/** @jest-environment jsdom */` docblock.
- Test `QueryClient`s: `gcTime: 0` for observed queries; `gcTime: Infinity` when
  seeding the cache directly; `mutations: { gcTime: 0 }` **separately**.
- Screens using `Link`/`useRouter` cannot be mounted — put logic in hooks and
  presentational components, as `ReviewCard` does.
- A new route file under a tab directory flattens it into the tab bar unless
  that directory has its own `_layout.tsx` with a `<Stack>`.
- **`npm run routes:types` must be run after any route change** before
  `npm run typecheck` means anything locally. CI does it automatically.
- **`npm run check:redirects` must pass** — every new dynamic route needs a
  `netlify.toml` rewrite.
- **`ContractFixturesTest` must stay green on `login.json` without
  regeneration.** New fixtures are added deliberately; the login one must not
  move.
- **Never write `make_confirmed` or `year_confirmed` from the client.**
- Nothing here may start a crawl. No `search:run`, no chunk endpoint.
- **There are no model factories.** `database/factories/` holds only
  `UserFactory`. Tests build rows through `ApiTestCase::search(User, $overrides)`
  and `ApiTestCase::image(CarSearch, $overrides)`, which use `Model::create()`.
  A `CsvImport` has no helper yet — Task 1 adds one. Never write
  `CarSearch::factory()`; it does not exist, and the resulting failure looks
  like a missing class rather than a missing feature.

## File Structure

**New — Laravel**

| File | Responsibility |
|---|---|
| `app/Services/Imports/ImportCoverage.php` | The six coverage counts for an optional import |
| `app/Policies/CsvImportPolicy.php` | `viewAny`, `view`, `create` — auto-discovered, no registration |
| `app/Http/Resources/Api/V1/ImportResource.php` | The import as the client sees it |
| `app/Http/Controllers/Api/V1/ImportController.php` | `index`, `show`, `store` |
| `app/Http/Requests/Api/V1/ListImportsRequest.php` | Pagination only |
| `app/Http/Requests/Api/V1/StoreImportRequest.php` | The multipart upload rules |

**New — mobile**

| File | Responsibility |
|---|---|
| `mobile/src/api/hooks/useImports.ts` | `useImports()`, `useImport(id)` |
| `mobile/src/api/hooks/useUploadImport.ts` | The multipart mutation |
| `mobile/src/ui/CoveragePanel.tsx` | Presentational; the six counts as tappable tiles |
| `mobile/app/(app)/pipeline/_layout.tsx` | Stack |
| `mobile/app/(app)/pipeline/index.tsx` | Imports list |
| `mobile/app/(app)/pipeline/[id].tsx` | Import detail: coverage + its queries |
| `mobile/app/(app)/pipeline/upload.tsx` | CSV upload |

**Modified:** `routes/api.php`, `app/Filament/Pages/Results.php`,
`app/Http/Requests/Api/V1/ListSearchesRequest.php`,
`app/Http/Controllers/Api/V1/SearchController.php`,
`tests/Feature/Api/ContractFixturesTest.php`, `mobile/src/api/schemas.ts`,
`mobile/src/api/queryKeys.ts`, `mobile/src/auth/AuthContext.tsx`,
`mobile/app/(app)/_layout.tsx`, `mobile/app/(app)/search/runs/index.tsx`,
`mobile/netlify.toml`, `mobile/package.json`.

---

### Task 1: The coverage service

`Results::coverage()` is a Filament `Page` method, so the API cannot call it.
Extracting it is what stops the panel and the app answering the same question
with two implementations.

**Files:**
- Create: `app/Services/Imports/ImportCoverage.php`
- Create: `tests/Feature/Services/Imports/ImportCoverageTest.php`
- Modify: `app/Filament/Pages/Results.php`

**Interfaces:**
- Produces: `ImportCoverage::for(?int $importId): array` returning
  `['total' => int, 'searched' => int, 'not_run' => int, 'failed' => int,
  'with_images' => int, 'no_images' => int]`, or `null` when `total` is 0.

- [ ] **Step 0: Add a `csvImport` helper beside the existing ones**

`ApiTestCase` already carries `search()` and `image()`; imports need the same.
Add to `tests/Feature/Api/ApiTestCase.php`:

```php
    /**
     * `imported_by` is NOT NULL, so an import always has an importer. Callers
     * that do not care about who uploaded get one made for them.
     */
    protected function csvImport(?User $importer = null, array $overrides = []): CsvImport
    {
        return CsvImport::create(array_merge([
            'original_filename' => 'queries.csv',
            'total_rows' => 0,
            'unique_combos' => 0,
            'duplicates_skipped' => 0,
            'imported_by' => ($importer ?? User::factory()->create())->id,
        ], $overrides));
    }
```

`ImportCoverageTest` extends `ApiTestCase` for these helpers, not for anything
HTTP. It lives under `tests/Feature/Services/` because it tests a service, but
`ApiTestCase` is where the only row-builders in this project live.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services\Imports;

use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use App\Services\Imports\ImportCoverage;
use Tests\Feature\Api\ApiTestCase;

/**
 * Extends ApiTestCase for its search()/image()/csvImport() helpers, not for
 * anything HTTP - there are no model factories in this project, and these are
 * the only builders that supply the non-null columns.
 */
class ImportCoverageTest extends ApiTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function csvSearch(CsvImport $import, string $status, bool $withImage = false): CarSearch
    {
        $search = $this->search($this->user, [
            'csv_import_id' => $import->id,
            'status' => $status,
        ]);

        if ($withImage) {
            $this->image($search);
        }

        return $search;
    }

    public function test_it_separates_ran_and_found_nothing_from_never_ran(): void
    {
        // The whole reason this exists. `status` cannot tell them apart: a
        // search that ran and found nothing is `completed`, exactly like one
        // that found five images.
        $import = $this->csvImport();
        $this->csvSearch($import, 'completed', withImage: true);
        $this->csvSearch($import, 'completed');
        $this->csvSearch($import, 'pending');

        $coverage = app(ImportCoverage::class)->for($import->id);

        $this->assertSame(3, $coverage['total']);
        $this->assertSame(2, $coverage['searched']);
        $this->assertSame(1, $coverage['not_run']);
        $this->assertSame(1, $coverage['with_images']);
        $this->assertSame(1, $coverage['no_images']);
    }

    public function test_it_counts_failures_separately(): void
    {
        $import = $this->csvImport();
        $this->csvSearch($import, 'failed');
        $this->csvSearch($import, 'completed', withImage: true);

        $coverage = app(ImportCoverage::class)->for($import->id);

        $this->assertSame(1, $coverage['failed']);
        // A failed search still counts as searched: it ran, it just did not
        // finish. Treating it as not-run would tell the operator to run it
        // again with no hint that it already broke once.
        $this->assertSame(2, $coverage['searched']);
    }

    public function test_running_counts_as_not_run(): void
    {
        // `running` on this host means "a request died mid-search": there is
        // no worker, so nothing is advancing it. It is work still to do.
        $import = $this->csvImport();
        $this->csvSearch($import, 'running');

        $this->assertSame(1, app(ImportCoverage::class)->for($import->id)['not_run']);
    }

    public function test_it_ignores_searches_from_other_imports(): void
    {
        $mine = $this->csvImport();
        $theirs = $this->csvImport();
        $this->csvSearch($mine, 'completed', withImage: true);
        $this->csvSearch($theirs, 'completed', withImage: true);

        $this->assertSame(1, app(ImportCoverage::class)->for($mine->id)['total']);
    }

    public function test_a_null_import_covers_every_csv_derived_search(): void
    {
        $import = $this->csvImport();
        $this->csvSearch($import, 'completed', withImage: true);
        // Ad-hoc: no csv_import_id, and never part of coverage.
        $this->search($this->user, ['csv_import_id' => null, 'status' => 'completed']);

        $this->assertSame(1, app(ImportCoverage::class)->for(null)['total']);
    }

    public function test_it_returns_null_when_there_is_nothing_to_describe(): void
    {
        // Null hides the panel rather than rendering six zeroes, which read as
        // a broken import rather than an empty one.
        $this->assertNull(app(ImportCoverage::class)->for($this->csvImport()->id));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ImportCoverageTest`
Expected: FAIL — `Class "App\Services\Imports\ImportCoverage" not found`.

**If it fails on `Call to undefined method CsvImport::factory()` instead,** the
test was written against factories that do not exist. Rewrite it against the
`ApiTestCase` helpers and the new `csvImport()` helper from Step 0.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Imports;

use App\Models\CarSearch;
use Illuminate\Database\Eloquent\Builder;

/**
 * How much of a CSV import has actually been searched.
 *
 * The images table can only show images that exist, so a run that stopped
 * early and a run that finished having found little look identical. Counting
 * the *searches* behind those rows separates the two - how many never ran, and
 * how many ran and came back empty.
 *
 * Extracted from App\Filament\Pages\Results so the panel and the API answer
 * this question with one implementation. The page keeps what is its own: the
 * import's name, and the deep links into the filtered Search Queries list.
 */
class ImportCoverage
{
    /**
     * Null when there is nothing to describe, which hides the panel rather
     * than rendering six zeroes.
     *
     * @return array{total: int, searched: int, not_run: int, failed: int, with_images: int, no_images: int}|null
     */
    public function for(?int $importId): ?array
    {
        /*
         * Re-built per count rather than cloned: `whereHas` on a shared
         * builder leaks its subquery into the counts that follow, so the
         * cheaper-looking clone silently corrupts every total after the first.
         */
        $searches = fn (): Builder => CarSearch::query()
            ->whereNotNull('csv_import_id')
            ->when($importId !== null, fn (Builder $q) => $q->where('csv_import_id', $importId));

        $total = $searches()->count();

        if ($total === 0) {
            return null;
        }

        // `running` counts as not-run: there is no queue worker on this host,
        // so a row left `running` is a request that died mid-search, not work
        // in progress.
        $notRun = $searches()->whereIn('status', ['pending', 'running'])->count();

        return [
            'total' => $total,
            'searched' => $total - $notRun,
            'not_run' => $notRun,
            'failed' => $searches()->where('status', 'failed')->count(),
            'with_images' => $searches()->whereHas('images')->count(),
            'no_images' => $searches()->where('status', 'completed')->whereDoesntHave('images')->count(),
        ];
    }
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ImportCoverageTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Point the Filament page at the service**

In `app/Filament/Pages/Results.php`, `coverage()` keeps its signature and its
`importName` / `notRunUrl` / `noImagesUrl` keys — the view depends on them —
but delegates the counting:

```php
    public function coverage(): ?array
    {
        $importId = $this->coverageImportId();
        $counts = app(ImportCoverage::class)->for($importId);

        if ($counts === null) {
            return null;
        }

        // The page's own concerns, layered on the shared counts: the import's
        // name, and deep links into the filtered Search Queries list. Neither
        // means anything to an API client, which is why they stayed here.
        return $counts + [
            'importName' => $importId === null
                ? null
                : CsvImport::whereKey($importId)->value('original_filename'),
            'notRunUrl' => $this->searchQueriesUrl($importId, 'not_run'),
            'noImagesUrl' => $this->searchQueriesUrl($importId, 'no_images'),
        ];
    }
```

**The view reads the old camelCase keys.** `resources/views/filament/pages/results-coverage.blade.php` uses `$coverage['notRun']`, `$coverage['withImages']` and `$coverage['noImages']`; the service returns snake_case. Grep the Blade file and rename those three reads to `not_run`, `with_images`, `no_images`. Do not add a translation layer — one spelling on the wire and in the view is the point.

Run: `grep -rn 'notRun\|withImages\|noImages' resources/views/` to find every one.

- [ ] **Step 6: Unify the key casing, and expect one test to need updating**

The counts move to snake_case to match the wire, which leaves `coverage()`
returning a mix — snake counts beside the page's camelCase `importName`,
`notRunUrl` and `noImagesUrl`. Rename those three too, in `Results.php`, the
Blade, and the test. One array, one convention.

`RunCoverageTest::test_coverage_separates_never_ran_from_found_nothing`
asserts the key names directly, so it **does** need editing — the casing is
part of `coverage()`'s contract with its only consumer, and this task changes
it deliberately. That is the one edit; every other Filament test stays
untouched, because the numbers are identical.

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test`
Expected: PASS.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test
vendor/bin/pint --test
git add app/Services/Imports/ImportCoverage.php tests/Feature/Services/Imports/ImportCoverageTest.php app/Filament/Pages/Results.php resources/views/
git commit -m "refactor: extract the import coverage counts into a service"
```

---

### Task 2: Read the imports

**Files:**
- Create: `app/Policies/CsvImportPolicy.php`, `app/Http/Resources/Api/V1/ImportResource.php`,
  `app/Http/Requests/Api/V1/ListImportsRequest.php`,
  `app/Http/Controllers/Api/V1/ImportController.php`
- Create: `tests/Feature/Api/ImportTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Consumes: `ImportCoverage::for()` (Task 1), `TokenAbilities::IMPORTS_READ` (P2).
- Produces: `GET /api/v1/imports`, `GET /api/v1/imports/{import}`.
  `ImportResource` fields: `id`, `original_filename`, `total_rows`,
  `unique_combos`, `duplicates_skipped`, `imported_by`, `importer_name`,
  `searches_count`, `created_at`, and on `show` only, `coverage`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;

class ImportTest extends ApiTestCase
{
    public function test_it_lists_imports_newest_first(): void
    {
        $user = $this->actingAsApiUser();
        $older = $this->csvImport(null, ['original_filename' => 'older.csv']);
        $newer = $this->csvImport(null, ['original_filename' => 'newer.csv']);

        $this->getJson('/api/v1/imports')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_it_names_the_importer_without_leaking_their_email(): void
    {
        $this->actingAsApiUser();
        $importer = User::factory()->create(['name' => 'Allan', 'email' => 'allan@example.com']);
        $this->csvImport(null, ['imported_by' => $importer->id]);

        $response = $this->getJson('/api/v1/imports')->assertOk();

        $response->assertJsonPath('data.0.importer_name', 'Allan');
        $this->assertStringNotContainsString('allan@example.com', $response->getContent());
    }

    public function test_the_list_carries_no_coverage(): void
    {
        // Six aggregate counts per row is a per-row fan-out on a screen that
        // only needs to identify the import.
        $this->actingAsApiUser();
        $this->csvImport();

        $this->getJson('/api/v1/imports')
            ->assertOk()
            ->assertJsonMissingPath('data.0.coverage');
    }

    public function test_the_detail_carries_coverage(): void
    {
        $user = $this->actingAsApiUser();
        $import = $this->csvImport();
        $ran = $this->search($user, ['csv_import_id' => $import->id, 'status' => 'completed']);
        $this->image($ran);
        $this->search($user, ['csv_import_id' => $import->id, 'status' => 'pending']);

        $this->getJson("/api/v1/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.coverage.total', 2)
            ->assertJsonPath('data.coverage.with_images', 1)
            ->assertJsonPath('data.coverage.not_run', 1);
    }

    public function test_coverage_is_null_for_an_import_with_no_searches(): void
    {
        $this->actingAsApiUser();
        $import = $this->csvImport();

        $this->getJson("/api/v1/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.coverage', null);
    }

    public function test_it_counts_the_import_searches(): void
    {
        $user = $this->actingAsApiUser();
        $import = $this->csvImport();
        foreach (range(1, 3) as $i) { $this->search($user, ['csv_import_id' => $import->id]); };

        $this->getJson('/api/v1/imports')
            ->assertOk()
            ->assertJsonPath('data.0.searches_count', 3);
    }

    public function test_reading_imports_requires_the_imports_read_ability(): void
    {
        // The token ability gate, distinct from the policy: this token may not
        // even attempt it.
        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->csvImport();

        $this->getJson('/api/v1/imports')->assertForbidden();
    }

    public function test_imports_require_authentication(): void
    {
        $this->getJson('/api/v1/imports')->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ImportTest`
Expected: FAIL — 404, the routes do not exist.

- [ ] **Step 3: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\CsvImport;
use App\Models\User;

/**
 * Deliberately permissive - see CarImagePolicy. Every authenticated user is an
 * admin, exactly as the panel treats them, and the class exists so that
 * visibility is a decision written down here rather than an accident of having
 * no policy.
 *
 * The token ability and this policy answer different questions: the ability
 * says this DEVICE may attempt it, the policy says this HUMAN may. Both gate
 * every import route.
 *
 * No `delete`. Nothing in this programme lets a mobile token destroy anything,
 * and the method's absence is what enforces that.
 */
class CsvImportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CsvImport $import): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
```

Laravel auto-discovers this — `AppServiceProvider::boot()` is empty and the
existing policies are found by naming convention. Do not register it.

- [ ] **Step 4: Write the resource**

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `importer_name` rather than a nested user object: the list needs one string,
 * and UserResource would drag `email` into a screen with no use for it.
 *
 * `coverage` is present only where the controller loaded it - the list omits
 * it, because six aggregate counts per row is a fan-out on a screen that only
 * needs to identify the import.
 */
class ImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'total_rows' => $this->total_rows,
            'unique_combos' => $this->unique_combos,
            'duplicates_skipped' => $this->duplicates_skipped,
            'imported_by' => $this->imported_by,
            'importer_name' => $this->whenLoaded('importer', fn () => $this->importer?->name),
            'searches_count' => $this->whenCounted('searches'),
            'coverage' => $this->when($this->additional['coverage'] ?? false, fn () => $this->additional['coverage']),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

**Note for the implementer:** `$this->additional` is not how `JsonResource`
exposes additional data — it is a protected property set by `->additional()`
and merged at the *top* level, not into `data`. Coverage must instead be a
constructor-style property on the resource. Use a public property set by the
controller:

```php
    public ?array $coverage = null;
    // ...
            'coverage' => $this->when($request->routeIs('api.v1.imports.show'), fn () => $this->coverage),
```

Simpler and less magical: give the resource an explicit
`withCoverage(?array $coverage): static` that sets the property and returns
`$this`, and have `toArray` emit `'coverage' => $this->coverage` only when the
route is `show`. Decide during implementation; the test asserts the observable
behaviour either way.

- [ ] **Step 5: Write the request and controller**

`ListImportsRequest` is `PaginatesWithCursor` plus `authorize(): true` and
`rules(): $this->paginationRules()` — the same shape as `ListSearchesRequest`.

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListImportsRequest;
use App\Http\Resources\Api\V1\ImportResource;
use App\Models\CsvImport;
use App\Services\Imports\ImportCoverage;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ImportController extends Controller
{
    public function index(ListImportsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CsvImport::class);

        $imports = CsvImport::query()
            ->with('importer')
            ->withCount('searches')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ImportResource::collection($imports);
    }

    public function show(CsvImport $import, ImportCoverage $coverage): ImportResource
    {
        Gate::authorize('view', $import);

        return ImportResource::make($import->load('importer')->loadCount('searches'))
            ->withCoverage($coverage->for($import->id));
    }
}
```

- [ ] **Step 6: Route them**

In `routes/api.php`, inside the `auth:sanctum` group, add a block mirroring the
existing `search:read` one:

```php
        Route::middleware(['ability:'.TokenAbilities::IMPORTS_READ, 'throttle:120,1'])->group(function () {
            Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
            Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
        });
```

- [ ] **Step 7: Run the tests and watch them pass**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ImportTest`
Expected: PASS, 8 tests.

- [ ] **Step 8: Pint, full suite, commit**

```bash
vendor/bin/pint
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test
git add app/Policies/CsvImportPolicy.php app/Http/Resources/Api/V1/ImportResource.php app/Http/Requests/Api/V1/ListImportsRequest.php app/Http/Controllers/Api/V1/ImportController.php routes/api.php tests/Feature/Api/ImportTest.php
git commit -m "feat(api): expose CSV imports and their coverage"
```

---

### Task 3: Upload a CSV

**Files:**
- Create: `app/Http/Requests/Api/V1/StoreImportRequest.php`
- Modify: `app/Http/Controllers/Api/V1/ImportController.php`, `routes/api.php`,
  `tests/Feature/Api/ImportTest.php`

**Interfaces:**
- Consumes: `CsvQueryImporter::import(UploadedFile, User): CsvImportResult`,
  `TokenAbilities::IMPORTS_WRITE`.
- Produces: `POST /api/v1/imports` → 201 with the created import.

- [ ] **Step 1: Write the failing tests**

```php
    public function test_it_imports_an_uploaded_csv(): void
    {
        $this->actingAsApiUser();
        $csv = UploadedFile::fake()->createWithContent(
            'queries.csv',
            "Make,Model,Year\nToyota,Corolla,1998\nHonda,Civic,1999\n",
        );

        $this->postJson('/api/v1/imports', ['csv_file' => $csv])
            ->assertCreated()
            ->assertJsonPath('data.original_filename', 'queries.csv')
            ->assertJsonPath('data.unique_combos', 2);

        $this->assertSame(2, CarSearch::whereNotNull('csv_import_id')->count());
    }

    public function test_it_records_who_uploaded(): void
    {
        $user = $this->actingAsApiUser();
        $csv = UploadedFile::fake()->createWithContent('q.csv', "Make,Model,Year\nKia,Rio,2010\n");

        $this->postJson('/api/v1/imports', ['csv_file' => $csv])->assertCreated();

        $this->assertSame($user->id, CsvImport::sole()->imported_by);
    }

    public function test_it_rejects_a_csv_missing_required_columns(): void
    {
        // CsvQueryImporter throws CsvImportException; the endpoint must turn
        // that into a 422 the client can show, not a 500.
        $this->actingAsApiUser();
        $csv = UploadedFile::fake()->createWithContent('bad.csv', "Make,Year\nToyota,1998\n");

        $this->postJson('/api/v1/imports', ['csv_file' => $csv])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['csv_file']);

        $this->assertSame(0, CsvImport::count());
    }

    public function test_it_requires_a_file(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/v1/imports', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['csv_file']);
    }

    public function test_uploading_requires_the_imports_write_ability(): void
    {
        // imports:read is not enough - this is the verb that seeds hundreds of
        // queries and it is deliberately absent from the web build's scope.
        $this->actingAsApiUser([TokenAbilities::IMPORTS_READ]);
        $csv = UploadedFile::fake()->createWithContent('q.csv', "Make,Model,Year\nKia,Rio,2010\n");

        $this->postJson('/api/v1/imports', ['csv_file' => $csv])->assertForbidden();

        $this->assertSame(0, CsvImport::count());
    }
```

Add `use Illuminate\Http\UploadedFile;` to the test file.

- [ ] **Step 2: Run them and watch them fail**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ImportTest`
Expected: FAIL — 405 or 404 on the POST.

- [ ] **Step 3: Write the request**

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The same caps the panel's upload form applies, read from config so the
     * two cannot drift. The row and projected-image ceilings are enforced
     * inside CsvQueryImporter, which every caller passes through.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'max:'.(int) config('cars-images.csv_import_max_upload_kb'),
            ],
        ];
    }
}
```

- [ ] **Step 4: Add the controller action**

```php
    public function store(StoreImportRequest $request, CsvQueryImporter $importer, ImportCoverage $coverage): JsonResponse
    {
        Gate::authorize('create', CsvImport::class);

        try {
            $result = $importer->import($request->file('csv_file'), $request->user());
        } catch (CsvImportException $e) {
            // The importer already logged this to error_events under
            // csv_upload. Surfacing it as a 422 on the field the client sent
            // means the message lands under the file picker rather than in a
            // generic banner.
            throw ValidationException::withMessages(['csv_file' => $e->getMessage()]);
        }

        $import = $result->csvImport->load('importer')->loadCount('searches');

        return ImportResource::make($import)
            ->withCoverage($coverage->for($import->id))
            ->response()
            ->setStatusCode(201);
    }
```

- [ ] **Step 5: Route it**

```php
        // Seeds up to csv_import_max_combos searches, so the tightest
        // authenticated limit - and imports:write is deliberately outside the
        // web build's requested scope.
        Route::post('imports', [ImportController::class, 'store'])
            ->middleware(['ability:'.TokenAbilities::IMPORTS_WRITE, 'throttle:10,1'])
            ->name('imports.store');
```

- [ ] **Step 6: Run the tests and watch them pass**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ImportTest`
Expected: PASS, 13 tests.

- [ ] **Step 7: Pint, full suite, commit**

```bash
vendor/bin/pint
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test
git add app/Http/Requests/Api/V1/StoreImportRequest.php app/Http/Controllers/Api/V1/ImportController.php routes/api.php tests/Feature/Api/ImportTest.php
git commit -m "feat(api): accept a CSV upload over the API"
```

---

### Task 4: Split ad-hoc from CSV-derived searches

Repairs a defect P1 identified and left: `GET /searches` returns both kinds
together, losing a distinction the panel makes deliberately.

**Files:**
- Modify: `app/Http/Requests/Api/V1/ListSearchesRequest.php`,
  `app/Http/Controllers/Api/V1/SearchController.php`
- Modify: `tests/Feature/Api/SearchTest.php` (or wherever `/searches` is tested)

**Interfaces:**
- Produces: `GET /searches` accepting `source` (`csv`|`adhoc`),
  `csv_import_id` (int), `coverage` (`with_images`|`no_images`|`not_run`), all
  `sometimes`. `ListSearchesRequest::apply(Builder): Builder`, mirroring
  `ListImagesRequest::apply()`.

- [ ] **Step 1: Write the failing tests**

```php
    public function test_it_filters_to_csv_derived_searches(): void
    {
        // The panel splits these into two resources on exactly this column;
        // the API returned them merged, so the mobile Runs list showed both.
        $user = $this->actingAsApiUser();
        $import = $this->csvImport();
        $fromCsv = $this->search($user, ['csv_import_id' => $import->id]);
        $this->search($user, ['csv_import_id' => null]);

        $this->getJson('/api/v1/searches?source=csv')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fromCsv->id);
    }

    public function test_it_filters_to_ad_hoc_searches(): void
    {
        $user = $this->actingAsApiUser();
        $import = $this->csvImport();
        $this->search($user, ['csv_import_id' => $import->id]);
        $adHoc = $this->search($user, ['csv_import_id' => null]);

        $this->getJson('/api/v1/searches?source=adhoc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $adHoc->id);
    }

    public function test_it_filters_by_import(): void
    {
        $user = $this->actingAsApiUser();
        $mine = $this->csvImport();
        $theirs = $this->csvImport();
        $wanted = $this->search($user, ['csv_import_id' => $mine->id]);
        $this->search($user, ['csv_import_id' => $theirs->id]);

        $this->getJson("/api/v1/searches?csv_import_id={$mine->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_coverage_no_images_finds_searches_that_ran_and_found_nothing(): void
    {
        // The question `status` cannot answer: both of these are `completed`.
        $user = $this->actingAsApiUser();
        $found = $this->search($user, ['status' => 'completed']);
        $this->image($found);
        $empty = $this->search($user, ['status' => 'completed']);

        $this->getJson('/api/v1/searches?coverage=no_images')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $empty->id);
    }

    public function test_coverage_not_run_includes_running(): void
    {
        // No worker on this host: a row left `running` is a dead request, not
        // work in progress.
        $user = $this->actingAsApiUser();
        $pending = $this->search($user, ['status' => 'pending']);
        $running = $this->search($user, ['status' => 'running']);
        $this->search($user, ['status' => 'completed']);

        $response = $this->getJson('/api/v1/searches?coverage=not_run')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$pending->id, $running->id],
            array_column($response->json('data'), 'id'),
        );
    }

    public function test_an_unfiltered_list_still_returns_everything(): void
    {
        // The three new parameters are all `sometimes`; nothing already
        // deployed shifts because they were added.
        $user = $this->actingAsApiUser();
        $import = $this->csvImport();
        $this->search($user, ['csv_import_id' => $import->id]);
        $this->search($user, ['csv_import_id' => null]);

        $this->getJson('/api/v1/searches')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_it_rejects_an_unknown_coverage_value(): void
    {
        $this->actingAsApiUser();

        $this->getJson('/api/v1/searches?coverage=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['coverage']);
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=SearchTest`
Expected: FAIL — the filters are ignored, so each count assertion sees both rows.

- [ ] **Step 3: Implement the filters**

In `ListSearchesRequest`:

```php
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['pending', 'running', 'completed', 'failed'])],
            'source' => ['sometimes', Rule::in(['csv', 'adhoc'])],
            'csv_import_id' => ['sometimes', 'integer', 'exists:csv_imports,id'],
            'coverage' => ['sometimes', Rule::in(['with_images', 'no_images', 'not_run'])],
        ] + $this->paginationRules();
    }

    /**
     * Narrow a CarSearch query by every filter present.
     *
     * On the request rather than the controller, matching ListImagesRequest.
     *
     * `coverage` answers what `status` cannot: a search that ran and found
     * nothing is `completed`, exactly like one that found five images. The
     * three cases are copied from the panel's own coverage filter so the two
     * clients classify a row identically.
     */
    public function apply(Builder $query): Builder
    {
        $filters = $this->validated();

        if (array_key_exists('status', $filters)) {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('source', $filters)) {
            $filters['source'] === 'csv'
                ? $query->whereNotNull('csv_import_id')
                : $query->whereNull('csv_import_id');
        }

        if (array_key_exists('csv_import_id', $filters)) {
            $query->where('csv_import_id', $filters['csv_import_id']);
        }

        return match ($filters['coverage'] ?? null) {
            'with_images' => $query->whereHas('images'),
            'no_images' => $query->where('status', 'completed')->whereDoesntHave('images'),
            'not_run' => $query->whereIn('status', ['pending', 'running']),
            default => $query,
        };
    }
```

In `SearchController::index`, replace the inline `when($status ...)` with
`$request->apply(CarSearch::query())`, keeping `withCount('images')`,
`orderByDesc('id')` and the cursor pagination exactly as they are.

- [ ] **Step 4: Run the tests and watch them pass**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=SearchTest`
Expected: PASS, including every pre-existing test **unedited**.

- [ ] **Step 5: Pint, full suite, commit**

```bash
vendor/bin/pint
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test
git add app/Http/Requests/Api/V1/ListSearchesRequest.php app/Http/Controllers/Api/V1/SearchController.php tests/Feature/Api/
git commit -m "feat(api): filter searches by source, import and coverage"
```

---

### Task 5: Contract fixtures and client schemas

**Files:**
- Modify: `tests/Feature/Api/ContractFixturesTest.php`
- Create: `mobile/src/api/__fixtures__/imports.json`, `import.json` (generated)
- Modify: `mobile/src/api/schemas.ts`, `mobile/src/api/__tests__/schemas.test.ts`

**Interfaces:**
- Produces: `ImportSchema`, `CoverageSchema` exported from `schemas.ts`.

- [ ] **Step 1: Capture the two new fixtures**

In `ContractFixturesTest`, create a `CsvImport` alongside the existing fixture
data, attach the existing `$search` to it, and add to the returned array:

```php
            'imports' => $this->getJson('/api/v1/imports?per_page=1')->assertOk()->json(),
            'import' => $this->getJson("/api/v1/imports/{$import->id}")->assertOk()->json(),
```

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 -e UPDATE_CONTRACT_FIXTURES=1 php artisan test --filter=ContractFixturesTest`

Then confirm the login fixture did **not** move:

Run: `git diff --stat mobile/src/api/__fixtures__/login.json`
Expected: empty. If it changed, the default issuance widened — stop and fix
that, do not commit the regenerated file.

- [ ] **Step 2: Write the failing schema test**

```ts
  it('parses the imports list', () => {
    const parsed = cursorPage(ImportSchema).parse(importsFixture);

    expect(parsed.data[0]?.original_filename).toBeTruthy();
  });

  it('parses an import with its coverage', () => {
    const parsed = single(ImportSchema).parse(importFixture);

    // `searched` is derived, not stored: total minus not_run. A client that
    // recomputed it would drift from the panel.
    expect(parsed.data.coverage?.searched).toBeDefined();
  });

  it('accepts an import whose coverage is null', () => {
    // Null hides the panel rather than rendering six zeroes, which read as a
    // broken import rather than an empty one.
    expect(() =>
      single(ImportSchema).parse({ data: { ...importFixture.data, coverage: null } }),
    ).not.toThrow();
  });
```

- [ ] **Step 3: Add the schemas**

```ts
export const CoverageSchema = z.object({
  total: z.number().int(),
  searched: z.number().int(),
  not_run: z.number().int(),
  failed: z.number().int(),
  with_images: z.number().int(),
  no_images: z.number().int(),
});
export type Coverage = z.infer<typeof CoverageSchema>;

export const ImportSchema = z.object({
  id: z.number().int(),
  original_filename: z.string(),
  total_rows: z.number().int().nullable(),
  unique_combos: z.number().int().nullable(),
  duplicates_skipped: z.number().int().nullable(),
  imported_by: z.number().int().nullable(),
  importer_name: z.string().nullable().optional(),
  searches_count: z.number().int().optional(),
  // Absent on the list, present-and-nullable on the detail.
  coverage: CoverageSchema.nullable().optional(),
  created_at: z.string().nullable(),
});
export type Import = z.infer<typeof ImportSchema>;
```

- [ ] **Step 4: Run both suites**

```bash
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ContractFixturesTest
cd mobile && npx jest src/api/__tests__/schemas --silent
```

Expected: both PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Api/ContractFixturesTest.php mobile/src/api/__fixtures__/ mobile/src/api/schemas.ts mobile/src/api/__tests__/schemas.test.ts
git commit -m "test: pin the imports contract and add its client schemas"
```

---

### Task 6: Clients declare their abilities

**Files:**
- Modify: `mobile/src/auth/AuthContext.tsx`, `mobile/src/auth/__tests__/AuthContext.test.tsx`

**Interfaces:**
- Produces: `signIn` posts an `abilities` array. Web requests the four legacy
  plus `imports:read`; native adds `imports:write`.

- [ ] **Step 1: Write the failing test**

```tsx
  it('asks for imports:read but never imports:write on web', () => {
    // The Pipeline tab has to work on the public demo, so imports:read
    // reaches localStorage. imports:write seeds hundreds of queries and does
    // not.
    expect(REQUESTED_ABILITIES.web).toContain('imports:read');
    expect(REQUESTED_ABILITIES.web).not.toContain('imports:write');
  });

  it('asks for the upload ability on native', () => {
    expect(REQUESTED_ABILITIES.native).toContain('imports:write');
  });

  it('never asks for an ability no screen uses yet', () => {
    // search:run and exports:read arrive with P3b and P4. Requesting them
    // early would put them in a token before anything checks them.
    for (const scope of Object.values(REQUESTED_ABILITIES)) {
      expect(scope).not.toContain('search:run');
      expect(scope).not.toContain('exports:read');
    }
  });
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd mobile && npx jest src/auth/__tests__/AuthContext --silent`
Expected: FAIL — `REQUESTED_ABILITIES` is not exported.

- [ ] **Step 3: Implement**

In `AuthContext.tsx`:

```tsx
/**
 * What each build asks for at login.
 *
 * The server's defaultScope() is frozen as a compatibility floor for clients
 * that predate scoping; every current client declares what it needs, so the
 * ability set can grow without the login response changing for anyone.
 *
 * imports:read reaches the web build because a Pipeline tab that 403s on the
 * public demo is worse than no tab. imports:write does not: it is the verb
 * that seeds hundreds of queries, and the web build keeps its token in
 * localStorage.
 */
export const REQUESTED_ABILITIES = {
  web: ['search:read', 'search:write', 'review:write', 'errors:read', 'imports:read'],
  native: [
    'search:read',
    'search:write',
    'review:write',
    'errors:read',
    'imports:read',
    'imports:write',
  ],
} as const;
```

and in `signIn`, add
`abilities: Platform.OS === 'web' ? [...REQUESTED_ABILITIES.web] : [...REQUESTED_ABILITIES.native]`
to the request body. Import `Platform` from `react-native`.

- [ ] **Step 4: Run the suite**

Run: `cd mobile && npm test`
Expected: PASS. The existing AuthContext tests must be green **unedited** — the
request body gained a field, the flow did not change.

- [ ] **Step 5: Commit**

```bash
cd mobile && npm run typecheck && npm run lint
git add mobile/src/auth/
git commit -m "feat(mobile): request the abilities each build actually needs"
```

---

### Task 7: The Pipeline tab

**Files:**
- Create: `mobile/src/api/hooks/useImports.ts`, `mobile/src/ui/CoveragePanel.tsx`,
  `mobile/src/ui/__tests__/CoveragePanel.test.tsx`,
  `mobile/app/(app)/pipeline/_layout.tsx`, `pipeline/index.tsx`, `pipeline/[id].tsx`
- Modify: `mobile/src/api/queryKeys.ts`, `mobile/app/(app)/_layout.tsx`,
  `mobile/app/(app)/search/runs/index.tsx`, `mobile/netlify.toml`,
  `mobile/app/(app)/__tests__/layout.test.tsx`

**Interfaces:**
- Consumes: `ImportSchema` (Task 5), the `/imports` endpoints (Task 2),
  `source=adhoc` (Task 4).
- Produces: `useImports()`, `useImport(id)`,
  `CoveragePanel({ coverage, onSelect })` where `onSelect` receives
  `'not_run' | 'no_images' | 'with_images'`.

- [ ] **Step 1: Write the failing component test**

```tsx
describe('<CoveragePanel />', () => {
  const coverage = {
    total: 58, searched: 35, not_run: 23, failed: 2, with_images: 30, no_images: 5,
  };

  it('shows what ran and found nothing separately from what never ran', () => {
    render(<CoveragePanel coverage={coverage} onSelect={jest.fn()} />);

    expect(screen.getByText('23')).toBeTruthy();
    expect(screen.getByText('5')).toBeTruthy();
  });

  it('reports which slice was tapped', () => {
    const onSelect = jest.fn();
    render(<CoveragePanel coverage={coverage} onSelect={onSelect} />);

    fireEvent.press(screen.getByLabelText(/not run/i));

    expect(onSelect).toHaveBeenCalledWith('not_run');
  });

  it('renders nothing when there is no coverage', () => {
    // Null means the import has no searches; six zeroes would read as broken
    // rather than empty.
    render(<CoveragePanel coverage={null} onSelect={jest.fn()} />);

    expect(screen.queryByLabelText(/not run/i)).toBeNull();
  });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd mobile && npx jest src/ui/__tests__/CoveragePanel --silent`
Expected: FAIL — module not found.

- [ ] **Step 3: Build the hooks, the panel and the screens**

`useImports.ts` follows `useSearches.ts` exactly: `useInfiniteQuery` with
`cursorPage(ImportSchema)` for the list, `useQuery` with `single(ImportSchema)`
for one. Add `imports` keys to `queryKeys.ts` in the existing style.

`CoveragePanel` renders the counts as `StatTile`s with `onPress`, each with an
`accessibilityLabel` naming the slice in words ("23 not run yet"). Three tiles
are tappable — `not_run`, `no_images`, `with_images`; `total`, `searched` and
`failed` are not, because there is no query for them.

`pipeline/index.tsx` is an `InfiniteGrid` of imports; each row shows the
filename, `unique_combos`, `searches_count` and `created_at`, linking to
`/(app)/pipeline/[id]`. Empty state: "No CSV imports yet" with an action
pushing to `pipeline/upload`.

`pipeline/[id].tsx` renders the import header, `CoveragePanel`, and below it the
import's queries via `useSearches({ source: 'csv', csv_import_id: id, coverage })`
where `coverage` is the chip the panel set. Tapping a coverage tile sets that
filter — the panel's "23 not run yet → exactly those 23" interaction.

`pipeline/_layout.tsx` mirrors `library/_layout.tsx`, titles Pipeline / Import.

- [ ] **Step 4: Add the fifth tab**

In `mobile/app/(app)/_layout.tsx`, add `pipeline` between `library` and
`review`, `headerShown: false` (it nests a Stack), icon `layers`. Update
`layout.test.tsx`'s tab list to the five and its icon count to 5.

- [ ] **Step 5: Make the Search tab ad-hoc only**

In `mobile/app/(app)/search/runs/index.tsx`, pass `source: 'adhoc'` to
`useSearches`. This is the P1 intent finishing: ad-hoc runs on the Search tab,
CSV-derived ones under Pipeline.

- [ ] **Step 6: Add the rewrite and regenerate route types**

`mobile/netlify.toml` gains, before `/search/:id`:

```toml
[[redirects]]
  from = "/pipeline/:id"
  to = "/pipeline/[id].html"
  status = 200
```

```bash
cd mobile && npm run routes:types && npm run typecheck && npm run build:web && npm run check:redirects
```

Expected: `netlify.toml covers all 4 dynamic routes.`

- [ ] **Step 7: Full gate and commit**

```bash
cd mobile && npm run typecheck && npm run lint && npm test
git add mobile/src mobile/app mobile/netlify.toml
git commit -m "feat(mobile): add the Pipeline tab with imports and coverage"
```

---

### Task 8: The upload screen

**Files:**
- Install: `expo-document-picker`
- Create: `mobile/src/api/hooks/useUploadImport.ts`, `mobile/app/(app)/pipeline/upload.tsx`,
  `mobile/src/api/hooks/__tests__/useUploadImport.test.tsx`
- Modify: `mobile/src/api/client.ts` if it cannot send `FormData`

**Interfaces:**
- Consumes: `POST /imports` (Task 3).
- Produces: `useUploadImport()` — a mutation taking
  `{ uri: string; name: string; mimeType?: string }`.

- [ ] **Step 1: Install the picker**

```bash
cd mobile && npx expo install expo-document-picker
cd .. && git diff --name-only package.json   # expect empty: root must not change
```

- [ ] **Step 2: Write the failing mutation test**

```tsx
  it('sends the file as multipart, not JSON', () => {
    // The endpoint takes a real upload; a JSON body would arrive with no file
    // and 422 on csv_file, which reads to the user as "your CSV is invalid".
    const spy = jest.spyOn(client, 'apiRequest').mockResolvedValue(importFixture);
    // ... render the hook, mutate, then:
    expect(spy.mock.calls[0]?.[1]?.body).toBeInstanceOf(FormData);
  });
```

Use `mutations: { gcTime: 0 }` in the test `QueryClient` — the `queries`
default does not reach the MutationCache.

- [ ] **Step 3: Implement**

`apiRequest` currently JSON-encodes its `body`. Give it a pass-through: when
`body instanceof FormData`, send it unchanged and **do not set Content-Type** —
the runtime must set it, because the multipart boundary is generated there and
a hand-written header omits it.

`useUploadImport` builds the `FormData` from the picker result and invalidates
the imports query on success.

- [ ] **Step 4: Build the screen**

`pipeline/upload.tsx`: the cost note first, then a Button opening
`DocumentPicker.getDocumentAsync({ type: 'text/comma-separated-values' })`, the
chosen filename, and an upload Button. On success, push to the new import's
detail. On a 422, show the message under the picker via `ApiValidationError`.

The cost note states, from the same config figures the panel uses, what a full
CSV commits the app to. An upload is a promise to crawl later; saying so at the
point of decision is the panel's behaviour.

- [ ] **Step 5: Route types, gate, commit**

```bash
cd mobile && npm run routes:types && npm run typecheck && npm run lint && npm test && npm run build:web && npm run check:redirects
git add mobile/
git commit -m "feat(mobile): upload a CSV from the Pipeline tab"
```

---

### Task 9: Verification

- [ ] **Step 1: Both suites, both linters**

```bash
vendor/bin/pint --test
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test
cd mobile && npm run routes:types && npm run typecheck && npm run lint && npm test && npm run build:web && npm run check:redirects
```

Expected: all seven exit 0.

- [ ] **Step 2: The login contract did not move**

```bash
git diff --stat origin/main -- mobile/src/api/__fixtures__/login.json
```

Expected: empty. Clients now request abilities explicitly, but the fixture
capture posts none, so the default issuance is untouched.

- [ ] **Step 3: Report**

State which commands passed, with output. Do not claim completion for any that
were not run.

---

## Self-Review

**Spec coverage.** Coverage service → Task 1. `GET /imports` and
`GET /imports/{id}` with coverage → Task 2. `CsvImportPolicy` → Task 2 Step 3.
`POST /imports` → Task 3. `source`/`csv_import_id`/`coverage` on `/searches` →
Task 4. Search tab becomes `source=adhoc` → Task 7 Step 5. Contract fixtures and
Zod schemas → Task 5. Frozen `defaultScope()` with clients declaring abilities →
Task 6. Three screens plus the fifth tab → Tasks 7 and 8. `expo-document-picker`
inside `mobile/` only → Task 8 Step 1. Cost note before upload → Task 8 Step 4.

**Gaps found and handled.** Three things the spec did not name, all verified
against the code before this plan was committed:

1. The Blade view reads `notRun`/`withImages`/`noImages` in camelCase and must
   follow the service's snake_case — confirmed at
   `resources/views/filament/pages/results-coverage.blade.php:6,28,30`
   (Task 1 Step 5).
2. `client.ts:89,94` unconditionally sets `Content-Type: application/json` and
   `JSON.stringify`s the body, so it cannot send an upload as written
   (Task 8 Step 3).
3. **There are no model factories.** `database/factories/` holds only
   `UserFactory`; every other row is built through `ApiTestCase::search()` and
   `::image()`. The first draft of this plan used `CarSearch::factory()`
   throughout, which would have failed at the "watch it fail" step of five
   separate tasks — and failed as a missing class, disguising whether the real
   assertion was sound. Every test here now uses the helpers, and Task 1 Step 0
   adds the missing `csvImport()` one.

**Uncertainty flagged rather than guessed.** Task 2 Step 4 records that
`JsonResource::$additional` does not do what the first sketch assumed and gives
the alternative, leaving the final shape to implementation — the test asserts
observable behaviour either way.

**Type consistency.** `ImportCoverage::for(?int): ?array` is declared in Task 1
and consumed in Tasks 2 and 3. `ImportSchema` / `CoverageSchema` are declared in
Task 5 and used in Task 7. `REQUESTED_ABILITIES` is declared in Task 6 and used
nowhere else. `CoveragePanel({ coverage, onSelect })` is declared in Task 7 and
used only there.

**Placeholder scan.** Clean.
