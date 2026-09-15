# Library and Exports Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the mobile Library to parity with `Results`, and let the native
app export what it shows as a CSV or ZIP through five-minute signed links.

**Architecture:** `GET /images` gains `csv_import_id`, and a new `GET
/images/count` answers "how many match" for the same filters, since the grid is
cursor-paginated and carries no total. `POST /exports` mints a signed URL (a ZIP
also gets a single-use cache nonce); `GET /exports/download` in `web.php` checks
the signature, consumes the nonce, recounts, and streams through the existing
exporters. The client gates export and upload on `useCan(ability)`.

**Tech Stack:** Laravel 13, Sanctum, PHPUnit; Expo 57, React Query 5,
expo-linking (present).

**Spec:** [`docs/superpowers/specs/2026-09-14-library-and-exports-design.md`](../specs/2026-09-14-library-and-exports-design.md)

## Global Constraints

- Laravel tests in the container:
  `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test`
- **`vendor/bin/pint --test` is a CI gate.**
- Mobile tests on the host from `mobile/`. **Never add a dependency to the
  repository-root `package.json`.**
- **No model factories** except `UserFactory`. Build rows with
  `ApiTestCase::search()`, `::image()` and `::csvImport()`.
- **No test may reach Wikimedia.** `Http::fake()` wherever a ZIP is built.
- `@testing-library/react-native` 13.3.3: `render()` is synchronous. **Never
  `await render(...)`.**
- **`mobile/jest.config.js` must not be modified.**
- Test `QueryClient`s: `gcTime: 0` for observed queries; `mutations: { gcTime: 0 }`
  **separately**.
- Screens using `Link`/`useRouter` cannot be mounted — logic in hooks and
  presentational components.
- **`npm run routes:types` after any route change**; **`npm run
  check:redirects` must pass.**
- **`ContractFixturesTest` must stay green on `login.json` without
  regeneration.**
- **Never write `make_confirmed` or `year_confirmed` from the client.** They
  are filters here, read-only.
- **`exports:read` stays native-only.** It is never added to
  `REQUESTED_ABILITIES.web`.

## File Structure

**New — Laravel**

| File | Responsibility |
|---|---|
| `app/Http/Requests/Api/V1/StoreExportRequest.php` | `format` plus the image filters |
| `app/Http/Controllers/Api/V1/ExportController.php` | Mint the signed URL |
| `app/Http/Controllers/ExportDownloadController.php` | Verify, consume, recount, stream |
| `app/Services/Downloads/ExportQuery.php` | One place that turns filters into the image set |
| `tests/Feature/Api/ExportTest.php` | Minting |
| `tests/Feature/ExportDownloadTest.php` | Downloading |

**New — mobile**

| File | Responsibility |
|---|---|
| `mobile/src/auth/useCan.ts` | Capability check against the requested abilities |
| `mobile/src/api/hooks/useImageCount.ts` | The advisory count |
| `mobile/src/api/hooks/useExport.ts` | Mint, then open |
| `mobile/src/ui/ExportPanel.tsx` | Presentational: count, two buttons, cap reason |

**Modified:** `routes/api.php`, `routes/web.php`,
`app/Http/Requests/Api/V1/ListImagesRequest.php`,
`app/Http/Controllers/Api/V1/ImageController.php`,
`app/Policies/CarImagePolicy.php`, `mobile/src/api/queryKeys.ts`,
`mobile/src/api/schemas.ts`, `mobile/app/(app)/library/index.tsx`,
`mobile/app/(app)/pipeline/index.tsx`, `mobile/app/(app)/pipeline/upload.tsx`.

---

### Task 1: `csv_import_id` and the count endpoint

**Files:** `ListImagesRequest.php`, `ImageController.php`, `routes/api.php`,
`tests/Feature/Api/ImagesTest.php`

**Interfaces:**
- Produces: `GET /images?csv_import_id=`; `GET /images/count` → `{ count: int }`.

- [ ] **Step 1: Write the failing tests**

```php
    public function test_it_filters_images_by_csv_import(): void
    {
        $user = $this->actingAsApiUser();
        $mine = $this->csvImport($user);
        $theirs = $this->csvImport($user);
        $wanted = $this->image($this->search($user, ['csv_import_id' => $mine->id]));
        $this->image($this->search($user, ['csv_import_id' => $theirs->id]));

        $this->getJson("/api/v1/images?csv_import_id={$mine->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_the_count_agrees_with_the_list_for_the_same_filters(): void
    {
        // The grid is cursor-paginated and carries no total, so this endpoint is
        // the only source for "N images match". It has to agree with the list
        // or the export button promises a number the export will not deliver.
        $user = $this->actingAsApiUser();
        $import = $this->csvImport($user);
        $search = $this->search($user, ['csv_import_id' => $import->id]);
        $this->image($search, ['make_confirmed' => true]);
        $this->image($search, ['make_confirmed' => true]);
        $this->image($search, ['make_confirmed' => false]);

        $query = "csv_import_id={$import->id}&make_confirmed=1";

        $listed = count($this->getJson("/api/v1/images?{$query}&per_page=100")->json('data'));

        $this->getJson("/api/v1/images/count?{$query}")
            ->assertOk()
            ->assertJsonPath('count', 2);
        $this->assertSame(2, $listed);
    }

    public function test_count_is_not_captured_as_an_image_id(): void
    {
        // GET images/{image} exists. Without a numeric constraint on {image},
        // GET images/count binds "count" as an image id and 404s.
        $this->actingAsApiUser();

        $this->getJson('/api/v1/images/count')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_counting_requires_search_read(): void
    {
        $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);

        $this->getJson('/api/v1/images/count')->assertForbidden();
    }
```

- [ ] **Step 2: Run and watch them fail**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ImagesTest`
Expected: FAIL — `csv_import_id` ignored; `/images/count` 404s.

- [ ] **Step 3: Implement**

`ListImagesRequest::rules()` gains
`'csv_import_id' => ['sometimes', 'integer', 'exists:csv_imports,id']`, and
`apply()` gains:

```php
        // Through the search, not a column on the image: an image belongs to a
        // search, and the search to an import.
        if (array_key_exists('csv_import_id', $filters)) {
            $query->whereHas('search', fn (Builder $q) => $q->where('csv_import_id', $filters['csv_import_id']));
        }
```

`ImageController` gains:

```php
    /**
     * How many images match, for the same filters as index().
     *
     * index() is cursor-paginated and cursor pagination computes no total -
     * deliberately, since a total costs a COUNT per page and cursors were chosen
     * because they neither skip nor repeat rows on insert. This answers the one
     * question the list cannot.
     */
    public function count(ListImagesRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', CarImage::class);

        return response()->json(['count' => $request->apply(CarImage::query())->count()]);
    }
```

In `routes/api.php`, add the route and constrain the binding:

```php
            Route::get('images/count', [ImageController::class, 'count'])->name('images.count');
            Route::get('images/{image}', [ImageController::class, 'show'])
                ->whereNumber('image')
                ->name('images.show');
```

`whereNumber` rather than relying on route order: a literal segment can then
never be captured as an id, whatever order future routes are added in.

- [ ] **Step 4: Run and watch them pass**, then pint, full suite, commit.

```bash
vendor/bin/pint && docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test && vendor/bin/pint --test
git add app/Http/Requests/Api/V1/ListImagesRequest.php app/Http/Controllers/Api/V1/ImageController.php routes/api.php tests/Feature/Api/ImagesTest.php
git commit -m "feat(api): filter images by import and count a filtered set"
```

---

### Task 2: The export query

Both the mint endpoint and the download route turn filters into an image set
and check it against the cap. One class, so they cannot disagree.

**Files:** `app/Services/Downloads/ExportQuery.php`,
`tests/Feature/Services/Downloads/ExportQueryTest.php`

**Interfaces:**
- Produces: `ExportQuery::count(array $filters): int`,
  `ExportQuery::images(array $filters): Collection` (with `search` loaded),
  `ExportQuery::zipCap(): int`.

- [ ] **Step 1: Write the failing test**

```php
class ExportQueryTest extends ApiTestCase
{
    public function test_it_counts_and_loads_the_same_set(): void
    {
        $user = User::factory()->create();
        $search = $this->search($user);
        $this->image($search, ['review_status' => 'approved']);
        $this->image($search, ['review_status' => 'rejected']);

        $query = app(ExportQuery::class);
        $filters = ['review_status' => 'approved'];

        $this->assertSame(1, $query->count($filters));
        $this->assertCount(1, $query->images($filters));
    }

    public function test_images_arrive_with_their_search_loaded(): void
    {
        // BatchCsvExporter reads $image->search for the transmission column;
        // without eager loading that is one query per row.
        $user = User::factory()->create();
        $this->image($this->search($user));

        $image = app(ExportQuery::class)->images([])->first();

        $this->assertTrue($image->relationLoaded('search'));
    }

    public function test_the_zip_cap_comes_from_config(): void
    {
        config()->set('cars-images.bulk_download_max_images', 7);

        $this->assertSame(7, app(ExportQuery::class)->zipCap());
    }
}
```

- [ ] **Step 2: Watch it fail**, then **Step 3: implement.**

```php
<?php

namespace App\Services\Downloads;

use App\Models\CarImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The image set an export contains.
 *
 * Shared by the endpoint that mints a link and the route that serves it, so the
 * count a user was shown and the file they receive come from one query. They
 * are computed at different moments on purpose - images can arrive in the
 * minutes between - but never by different code.
 *
 * Filters arrive already validated, by ListImagesRequest's rules at mint time
 * and by the URL signature at download time.
 */
class ExportQuery
{
    public function count(array $filters): int
    {
        return $this->query($filters)->count();
    }

    public function images(array $filters): Collection
    {
        return $this->query($filters)->with('search')->orderBy('id')->get();
    }

    /** The ZIP is built synchronously in one request, so it is capped. */
    public function zipCap(): int
    {
        return (int) config('cars-images.bulk_download_max_images', 100);
    }

    private function query(array $filters): Builder
    {
        $query = CarImage::query();

        foreach (['make', 'model', 'year', 'review_status', 'download_status'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where($column, $filters[$column]);
            }
        }

        // Verdict filters match their value only; the nullable "unknown" is
        // neither true nor false - the same rule ListImagesRequest applies.
        foreach (['make_confirmed', 'year_confirmed'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where($column, filter_var($filters[$column], FILTER_VALIDATE_BOOLEAN));
            }
        }

        if (array_key_exists('csv_import_id', $filters)) {
            $query->whereHas('search', fn (Builder $q) => $q->where('csv_import_id', $filters['csv_import_id']));
        }

        return $query;
    }
}
```

**Note on duplication:** this repeats `ListImagesRequest::apply()`'s filter
logic, because the download route has no FormRequest — its filters come from a
signed query string. If the duplication chafes, have `ListImagesRequest::apply()`
delegate to a static filter method here. Do not make the download route
construct a FormRequest from query parameters; that re-validates input the
signature already vouches for.

- [ ] **Step 4: Pass, pint, commit.**

```bash
git commit -m "feat(api): one query shared by export minting and downloading"
```

---

### Task 3: Minting the link

**Files:** `StoreExportRequest.php`, `ExportController.php`,
`app/Policies/CarImagePolicy.php`, `routes/api.php`, `routes/web.php` (route
name only — the controller lands in Task 4), `tests/Feature/Api/ExportTest.php`

**Interfaces:**
- Consumes: `ExportQuery` (Task 2).
- Produces: `POST /api/v1/exports` → 201
  `{ url, expires_at, count, format }`. Cache key `export-nonce:{nonce}` for a ZIP.

- [ ] **Step 1: Write the failing tests**

```php
class ExportTest extends ApiTestCase
{
    public function test_it_mints_a_signed_csv_link(): void
    {
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));

        $response = $this->postJson('/api/v1/exports', ['format' => 'csv'])
            ->assertCreated()
            ->assertJsonPath('format', 'csv')
            ->assertJsonPath('count', 1);

        $this->assertStringContainsString('signature=', $response->json('url'));
    }

    public function test_a_zip_link_carries_a_nonce_written_to_the_cache(): void
    {
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));

        $url = $this->postJson('/api/v1/exports', ['format' => 'zip'])->assertCreated()->json('url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        $this->assertNotEmpty($params['nonce'] ?? null);
        $this->assertTrue(Cache::has("export-nonce:{$params['nonce']}"));
    }

    public function test_a_csv_link_carries_no_nonce(): void
    {
        // Single-use is for the ZIP, whose replay costs Wikimedia fetches. A CSV
        // replay costs a query.
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));

        $url = $this->postJson('/api/v1/exports', ['format' => 'csv'])->json('url');

        $this->assertStringNotContainsString('nonce=', $url);
    }

    public function test_it_refuses_an_empty_set(): void
    {
        // A link to an empty file is a worse answer than saying so.
        $this->actingAsApiUser();

        $this->postJson('/api/v1/exports', ['format' => 'csv'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format']);
    }

    public function test_a_zip_over_the_cap_is_refused_but_a_csv_of_the_same_size_is_not(): void
    {
        // The cap exists because a ZIP fetches every image from Wikimedia
        // synchronously. A CSV touches nothing external and has no cap.
        config()->set('cars-images.bulk_download_max_images', 1);
        $user = $this->actingAsApiUser();
        $search = $this->search($user);
        $this->image($search);
        $this->image($search);

        $this->postJson('/api/v1/exports', ['format' => 'zip'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format']);

        $this->postJson('/api/v1/exports', ['format' => 'csv'])->assertCreated();
    }

    public function test_the_filters_travel_through_the_signature(): void
    {
        $user = $this->actingAsApiUser();
        $this->image($this->search($user), ['review_status' => 'approved']);

        $url = $this->postJson('/api/v1/exports', ['format' => 'csv', 'review_status' => 'approved'])
            ->json('url');

        $this->assertStringContainsString('review_status=approved', $url);
    }

    public function test_minting_requires_exports_read(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->image($this->search($user));

        $this->postJson('/api/v1/exports', ['format' => 'csv'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Watch them fail**, then **Step 3: implement.**

`CarImagePolicy` gains:

```php
    /**
     * Export a filtered set of images.
     *
     * Class-level, not instance-level, and that is correct. The P3b chunk
     * endpoint was flagged because it authorized a class while the client named
     * a specific object. An export names no single object - it acts on a
     * filtered set, so there is no instance to pass. If per-image visibility
     * ever arrives, it belongs in the filtered query, not here.
     */
    public function export(User $user): bool
    {
        return true;
    }
```

`StoreExportRequest` validates `'format' => ['required', Rule::in(['csv', 'zip'])]`
plus the same filter rules as `ListImagesRequest` — copy the rules array, since
a FormRequest cannot extend another's rules without inheritance tangles.

`ExportController::store`:

```php
    public function store(StoreExportRequest $request, ExportQuery $exports): JsonResponse
    {
        Gate::authorize('export', CarImage::class);

        $validated = $request->validated();
        $format = $validated['format'];
        $filters = Arr::except($validated, ['format']);
        $count = $exports->count($filters);

        if ($count === 0) {
            throw ValidationException::withMessages(['format' => 'No images match these filters.']);
        }

        if ($format === 'zip' && $count > $exports->zipCap()) {
            throw ValidationException::withMessages([
                'format' => "{$count} images match; a ZIP is limited to {$exports->zipCap()}. Narrow the filter.",
            ]);
        }

        $expiresAt = now()->addMinutes(5);
        $params = ['format' => $format] + $filters;

        if ($format === 'zip') {
            // Once minted a signed URL is bearer-free, and a replayed ZIP costs
            // up to a hundred Wikimedia fetches. The nonce makes it single-use.
            $nonce = Str::random(40);
            Cache::put("export-nonce:{$nonce}", true, $expiresAt);
            $params['nonce'] = $nonce;
        }

        return response()->json([
            'url' => URL::temporarySignedRoute('exports.download', $expiresAt, $params),
            'expires_at' => $expiresAt->toIso8601String(),
            'count' => $count,
            'format' => $format,
        ], 201);
    }
```

Route, in the `auth:sanctum` group:

```php
        Route::post('exports', [ExportController::class, 'store'])
            ->middleware(['ability:'.TokenAbilities::EXPORTS_READ, 'throttle:10,1'])
            ->name('exports.store');
```

`temporarySignedRoute` needs `exports.download` to exist, so register a
placeholder in `web.php` now pointing at `ExportDownloadController`, which Task
4 implements. Until then the tests only assert on the URL string.

- [ ] **Step 4: Pass, pint, full suite, commit.**

```bash
git commit -m "feat(api): mint signed export links, single-use for ZIPs"
```

---

### Task 4: Serving the link

**Files:** `app/Http/Controllers/ExportDownloadController.php`,
`routes/web.php`, `tests/Feature/ExportDownloadTest.php`

**Interfaces:**
- Consumes: `ExportQuery` (Task 2), the nonce convention (Task 3),
  `BatchCsvExporter::streamTo`, `BatchZipBuilder::buildToFile`.

- [ ] **Step 1: Write the failing tests**

```php
class ExportDownloadTest extends ApiTestCase
{
    private function mint(string $format, array $filters = []): string
    {
        return $this->postJson('/api/v1/exports', ['format' => $format] + $filters)->json('url');
    }

    public function test_a_csv_link_downloads_the_filtered_rows(): void
    {
        $user = $this->actingAsApiUser();
        $search = $this->search($user);
        $this->image($search, ['review_status' => 'approved', 'title' => 'File:Kept.jpg']);
        $this->image($search, ['review_status' => 'rejected', 'title' => 'File:Dropped.jpg']);

        $response = $this->get($this->mint('csv', ['review_status' => 'approved']));

        $response->assertOk();
        $body = $response->streamedContent();
        $this->assertStringNotContainsString('Dropped', $body);
    }

    public function test_the_download_needs_no_bearer_token(): void
    {
        // The whole point: the system browser follows this link with no token.
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));
        $url = $this->mint('csv');

        auth()->forgetGuards();

        $this->get($url)->assertOk();
    }

    public function test_a_zip_link_works_once_and_is_gone_the_second_time(): void
    {
        Http::fake(['*' => Http::response(file_get_contents(base_path('tests/fixtures/tiny.jpg')), 200)]);
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));
        $url = $this->mint('zip');

        $this->get($url)->assertOk();
        $this->get($url)->assertStatus(410);
    }

    public function test_a_tampered_link_is_rejected(): void
    {
        $user = $this->actingAsApiUser();
        $this->image($this->search($user), ['review_status' => 'approved']);
        $url = $this->mint('csv', ['review_status' => 'approved']);

        // Widening the filter by editing the URL must fail the signature.
        $this->get(str_replace('review_status=approved', 'review_status=rejected', $url))
            ->assertForbidden();
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));
        $url = $this->mint('csv');

        $this->travel(6)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_a_zip_that_grew_past_the_cap_after_minting_is_refused(): void
    {
        // Images can arrive in the five minutes between mint and download. A ZIP
        // that quietly grew past the cap would exceed max_execution_time.
        config()->set('cars-images.bulk_download_max_images', 1);
        $user = $this->actingAsApiUser();
        $search = $this->search($user);
        $this->image($search);
        $url = $this->mint('zip');

        $this->image($search);

        $this->get($url)->assertStatus(422);
    }
}
```

`tests/fixtures/tiny.jpg` must be a real decodable JPEG — `ImageResizer` runs
GD on it. Generate one in the test's `setUp` with `imagejpeg()` into a temp
file if a fixture file is unwanted; do not fake the body with arbitrary bytes,
which GD rejects and which would make the ZIP build fail for the wrong reason.

- [ ] **Step 2: Watch them fail**, then **Step 3: implement.**

```php
<?php

namespace App\Http\Controllers;

use App\Services\Downloads\BatchCsvExporter;
use App\Services\Downloads\BatchZipBuilder;
use App\Services\Downloads\ExportQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a signed export link.
 *
 * No authentication: the signature is the credential, because the system
 * browser follows this link with no bearer token. In web.php rather than
 * api.php for the same reason - a used or expired link should render as a page
 * in the browser tab, not as raw JSON.
 */
class ExportDownloadController
{
    public function __invoke(Request $request, ExportQuery $exports, BatchCsvExporter $csv, BatchZipBuilder $zip): Response
    {
        $format = $request->query('format');
        $filters = Arr::except($request->query(), ['format', 'nonce', 'expires', 'signature']);

        if ($format === 'zip') {
            // Not atomic on the file cache store: pull is a get then a forget,
            // so two requests in the same instant could both pass. The threat
            // is a leaked link replayed later, after the nonce is gone - not
            // the user racing themselves.
            if (! Cache::pull('export-nonce:'.$request->query('nonce'))) {
                abort(410, 'This download link has already been used. Export again from the app.');
            }

            // Recount rather than trust the mint: images can arrive in the
            // minutes between, and a grown ZIP would exceed max_execution_time.
            if ($exports->count($filters) > $exports->zipCap()) {
                abort(422, 'More images match than a ZIP allows now. Export again from the app with a narrower filter.');
            }
        }

        $images = $exports->images($filters);
        $stamp = now()->format('Ymd-His');

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($csv, $images) {
                $handle = fopen('php://output', 'w');
                $csv->streamTo($handle, $images);
                fclose($handle);
            }, "cars-{$stamp}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $path = tempnam(sys_get_temp_dir(), 'cars-export-');

        if ($zip->buildToFile($images, $path) === 0) {
            @unlink($path);
            abort(502, 'None of the images could be fetched from Wikimedia. Try again in a moment.');
        }

        // As Results::zipDownload() does. A side effect on a GET is ordinarily
        // wrong; the single-use nonce is what makes it fire once per mint.
        $images->toQuery()->update(['download_status' => 'downloaded']);

        return response()->download($path, "cars-{$stamp}.zip", ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}
```

In `routes/web.php`:

```php
// Signed rather than authenticated: the mobile app mints this link over the API
// and hands it to the system browser, which follows it with no bearer token.
// Here rather than in api.php so a used or expired link renders as a page.
Route::get('/exports/download', ExportDownloadController::class)
    ->middleware('signed')
    ->name('exports.download');
```

And **replace** the stale comment at the bottom of `web.php` that says bulk ZIP
and CSV downloads "need no dedicated routes or controller" — it is now false.

- [ ] **Step 4: Pass, pint, full suite, commit.**

```bash
git commit -m "feat: serve signed export links through the existing exporters"
```

---

### Task 5: Request `exports:read` on native, and gate by capability

**The step this plan first missed.** P3b left `REQUESTED_ABILITIES.native`
requesting seven abilities, none of them `exports:read`. Without adding it, the
export panel never renders on native and `POST /exports` 403s — the feature is
dead on the only build meant to have it. Every Laravel test in Tasks 3 and 4
would still pass, because `actingAsApiUser()` grants every ability; only the
client would be broken.

**Files:** `mobile/src/auth/AuthContext.tsx`,
`mobile/src/auth/__tests__/AuthContext.test.tsx`, `mobile/src/auth/useCan.ts`,
`mobile/src/auth/__tests__/useCan.test.ts`,
`mobile/app/(app)/pipeline/index.tsx`, `mobile/app/(app)/pipeline/upload.tsx`

**Interfaces:**
- Produces: `canWith(scope: readonly string[], ability: TokenAbility): boolean`
  (pure) and `useCan(ability: TokenAbility): boolean`.

- [ ] **Step 1: Write the failing tests**

In `AuthContext.test.tsx`, replace the existing "never asks for an ability no
screen uses yet" test — P4 is where `exports:read` gets its screen:

```tsx
  it('asks for exports:read on native only', () => {
    // Exports happen on the phone, after uploading in the panel. The web build
    // never exports, so the ability stays off localStorage tokens.
    expect(REQUESTED_ABILITIES.native).toContain('exports:read');
    expect(REQUESTED_ABILITIES.web).not.toContain('exports:read');
  });
```

`mobile/src/auth/__tests__/useCan.test.ts`:

```ts
import { REQUESTED_ABILITIES } from '../AuthContext';
import { canWith } from '../useCan';

describe('canWith', () => {
  it('grants an ability the scope requests', () => {
    expect(canWith(REQUESTED_ABILITIES.native, 'exports:read')).toBe(true);
  });

  it('refuses an ability the scope does not request', () => {
    // Tested through the pure function with the web scope, because this
    // jest-expo config pins Platform.OS to ios - so useCan alone could only
    // ever be observed granting, never refusing.
    expect(canWith(REQUESTED_ABILITIES.web, 'exports:read')).toBe(false);
    expect(canWith(REQUESTED_ABILITIES.web, 'imports:write')).toBe(false);
  });
});
```

- [ ] **Step 2: Watch them fail**

Run: `cd mobile && npx jest src/auth --silent`
Expected: FAIL — native lacks `exports:read`, and `canWith` does not exist.

- [ ] **Step 3: Add `exports:read` to native**

In `AuthContext.tsx`, append `'exports:read'` to `REQUESTED_ABILITIES.native`
only, and extend the docblock: exports happen on the native app after uploading
in the panel, so the ability stays off the web build.

- [ ] **Step 4: Write `useCan`**

```ts
import { Platform } from 'react-native';

import type { TokenAbility } from '@/api/schemas';
import { REQUESTED_ABILITIES } from './AuthContext';

/**
 * Pure, so refusal can be tested: this jest-expo config pins Platform.OS to
 * ios, which means useCan by itself could only ever be observed granting.
 */
export function canWith(scope: readonly string[], ability: TokenAbility): boolean {
  return scope.includes(ability);
}

/**
 * Whether this build's token carries an ability.
 *
 * A capability check, not a platform check. `Platform.OS === 'web'` would work
 * today and encode the wrong rule - "this is a browser" rather than "this token
 * cannot export" - and would rot the moment the ability split changes.
 *
 * Reads what the build requested, which equals what was granted while every
 * requested ability is valid: the server intersects with TokenAbilities::all().
 * The rigorous version persists the login response's `abilities`; deferred
 * until requested and granted can differ.
 */
export function useCan(ability: TokenAbility): boolean {
  return canWith(Platform.OS === 'web' ? REQUESTED_ABILITIES.web : REQUESTED_ABILITIES.native, ability);
}
```

Export `TokenAbility` from `schemas.ts` as `z.infer<typeof TokenAbilitySchema>`
if it is not already.

- [ ] **Step 5: Gate the upload entry points**

`pipeline/index.tsx`: pass `emptyAction` only when `useCan('imports:write')`.
Without it, the empty state says CSVs are uploaded from the admin panel.

`pipeline/upload.tsx`: when `!useCan('imports:write')`, render an explanation
that uploads happen in the admin panel instead of the picker. The route stays
reachable by URL, so the screen must say so, not just its entry point.

- [ ] **Step 6: Gate, commit.**

```bash
cd mobile && npm run typecheck && npm run lint && npm test
git commit -m "feat(mobile): request exports:read on native and gate by capability"
```

---

### Task 6: Library filters and the export panel

**Files:** `mobile/src/api/queryKeys.ts`, `mobile/src/api/schemas.ts`,
`mobile/src/api/hooks/useImageCount.ts`, `mobile/src/api/hooks/useExport.ts`,
`mobile/src/ui/ExportPanel.tsx`, `mobile/src/ui/__tests__/ExportPanel.test.tsx`,
`mobile/app/(app)/library/index.tsx`

**Interfaces:**
- Consumes: `GET /images/count` (Task 1), `POST /exports` (Task 3), `useCan` (Task 5).
- Produces: `ImageFilters` gains `csv_import_id?: number`;
  `ExportPanel({ count, zipCap, pending, onExport })`.

- [ ] **Step 1: Write the failing component test**

```tsx
describe('<ExportPanel />', () => {
  it('states what it will export before anything is pressed', () => {
    render(<ExportPanel count={63} zipCap={100} pending={null} onExport={jest.fn()} />);

    expect(screen.getByText(/63 images match/)).toBeTruthy();
  });

  it('disables a ZIP over the cap and says why', () => {
    // Not left pressable so the server can say no - the reason belongs beside
    // the button before it is pressed.
    render(<ExportPanel count={140} zipCap={100} pending={null} onExport={jest.fn()} />);

    expect(screen.getByLabelText('Export ZIP').props.accessibilityState.disabled).toBe(true);
    expect(screen.getByText(/limited to 100/)).toBeTruthy();
  });

  it('leaves the CSV available over the ZIP cap', () => {
    // The cap is a Wikimedia-fetch bound; a CSV fetches nothing.
    render(<ExportPanel count={140} zipCap={100} pending={null} onExport={jest.fn()} />);

    expect(screen.getByLabelText('Export CSV').props.accessibilityState.disabled).toBe(false);
  });

  it('warns that a ZIP leaves the app for the browser', () => {
    render(<ExportPanel count={10} zipCap={100} pending={null} onExport={jest.fn()} />);

    expect(screen.getByText(/browser/i)).toBeTruthy();
  });

  it('offers nothing to export when nothing matches', () => {
    render(<ExportPanel count={0} zipCap={100} pending={null} onExport={jest.fn()} />);

    expect(screen.getByLabelText('Export CSV').props.accessibilityState.disabled).toBe(true);
  });
});
```

- [ ] **Step 2: Implement**

`useImageCount(filters)` is a `useQuery` on `/images/count` keyed by the filters.
`useExport()` is a mutation posting to `/exports`, then
`Linking.openURL(result.url)` from `expo-linking`.

`ExportPanel` renders the count, two `Button`s and the cap reason. The ZIP cap
comes from a constant matching `bulk_download_max_images`; the server enforces
the real value, so a drifted constant only mislabels, never permits.

- [ ] **Step 3: Wire the Library**

Add chips for **make match** and **year match** (`matched` / `not matched`, the
`VerdictBadge` wording) and a **CSV import** picker, beside the existing make
field and review-status chips. Render `<ExportPanel>` only when
`useCan('exports:read')`.

- [ ] **Step 4: Full gate, commit.**

```bash
cd mobile && npm run routes:types && npm run typecheck && npm run lint && npm test && npm run build:web && npm run check:redirects
git commit -m "feat(mobile): filter the library like Results and export what it shows"
```

---

### Task 7: Verification

- [ ] **Step 1: Both stacks**

```bash
vendor/bin/pint --test
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test
cd mobile && npm run routes:types && npm run typecheck && npm run lint && npm test && npm run build:web && npm run check:redirects
```

- [ ] **Step 2: `exports:read` never reached the web build**

```bash
cd mobile && node -e "const s=require('fs').readFileSync('src/auth/AuthContext.tsx','utf8'); const web=s.slice(s.indexOf('web:'), s.indexOf('native:')); if (web.includes('exports:read')) throw new Error('exports:read leaked onto web'); console.log('ok: exports:read is native-only')"
```

- [ ] **Step 3: The login contract did not move**

```bash
git diff --stat origin/main -- mobile/src/api/__fixtures__/login.json   # expect empty
```

- [ ] **Step 4: Report** which commands passed, with output.

---

## Self-Review

**Spec coverage.** `csv_import_id` → Task 1. `GET /images/count` → Task 1.
Shared export query → Task 2. `POST /exports` with empty-set and over-cap
refusal, signed URL, ZIP nonce → Task 3. `CarImagePolicy::export`, class-level
with the reason → Task 3. Signed download with no auth, 410 on reuse, recount,
tamper and expiry rejection, both exporters, `download_status` update → Task 4.
Stale `web.php` comment replaced → Task 4. `useCan` → Task 5. Upload hidden on
web, entry point and route → Task 5. Library filter parity → Task 6. Export
panel with count, cap reason, browser warning → Task 6. `exports:read`
native-only → Task 7 Step 2.

**Two corrections made while planning, both verified against the code.**
The spec first said the export count came "from the grid's own query"; `GET
/images` is cursor-paginated and carries no total, so the spec was amended to
add `GET /images/count` before this plan was written. And `GET images/{image}`
already exists, so `GET images/count` would bind "count" as an image id — the
collision P3b's plan flagged wrongly is real here, and Task 1 constrains the
binding with `whereNumber` rather than relying on route order.

**A gap found in self-review, before committing.** The first draft never added
`exports:read` to `REQUESTED_ABILITIES.native` — P3b left native with seven
abilities, none of them that one. Every Laravel test would still have passed,
because `actingAsApiUser()` grants every ability; the feature would have been
dead only on the client, and only discovered by using it. Task 5 now adds it
first. The same draft's `useCan` "refuses" test asserted `true` for an ability
native does request — testing the opposite of its name — and could never have
observed a refusal at all, since Jest pins `Platform.OS` to ios. `canWith` is
split out as a pure function so refusal is testable with the web scope.

**A risk worth watching in Task 4.** The ZIP test needs a real decodable JPEG,
because `ImageResizer` runs GD on the fetched body. Faking it with arbitrary
bytes would make the build fail for the wrong reason and could pass a 502 off
as a working test. The task says so.

**Type consistency.** `ExportQuery::count/images/zipCap` is declared in Task 2
and used in Tasks 3 and 4. The nonce cache key `export-nonce:{nonce}` is written
in Task 3 and pulled in Task 4. `ImageFilters.csv_import_id` in Task 6 matches
the `csv_import_id` parameter from Task 1. `useCan(TokenAbility)` in Task 5 is
used in Tasks 5 and 6.

**Placeholder scan.** Clean.
