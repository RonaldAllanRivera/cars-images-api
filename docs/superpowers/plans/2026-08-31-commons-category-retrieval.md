# Commons Category Retrieval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Wikimedia full-text search with Commons category-tree retrieval so every stored image is a photograph of the requested make, model, and exact model year — or nothing is stored.

**Architecture:** Two pure units carry the hard logic and need no network (`CommonsCategoryResolver` generates category-name candidates; `ModelYearMatcher` extracts a model year from a file title). One I/O unit (`CommonsCategoryLocator`) walks candidates and persists the winner. `WikimediaClient` loses full-text search and gains two category methods. `CarImageSearchService` fetches a whole category once per model, then filters by exact year.

**Tech Stack:** Laravel 13, Filament 5, MySQL 8, PHPUnit, Pint. `QUEUE_CONNECTION=sync` — no queue worker, no cron; all work is synchronous inside a Filament request.

**Spec:** `docs/superpowers/specs/2026-08-31-commons-category-retrieval-design.md`

## Global Constraints

- **Exact-year only.** A file whose title names a range (`1997-1999`, `1998-99`, `'98-'99`) or no year is never stored. Zero images is a correct outcome.
- **`images_per_year` is applied AFTER year filtering, never as the API fetch limit.** Fetch the whole category first. `srlimit=10` on `Category:Cadillac STS` returns 10 of 56 files and finds none of its 6 exact matches.
- **Category-lookup writes happen outside the search transaction.** A resolution is cache state, not search state, and must survive a later failure in the same run.
- **Commons API contract (verified 2026-08-31):** `generator=search`, `gsrsearch=deepcategory:"<category>"`, `gsrnamespace=6`, `prop=imageinfo`. Pagination continues on `continue.gsroffset`, passed back as `gsroffset`.
- **Run tests with** `docker compose exec -T app php artisan test`. **Format with** `docker compose exec -T app ./vendor/bin/pint <paths>`.
- **Behaviour change, accepted:** `color`, `transmission` and `transparent_background` stop influencing retrieval — a category name cannot express them. They remain stored metadata on `CarSearch`.

---

### Task 1: `ModelYearMatcher`

Extracts the model year a Commons file title asserts. Pure, no I/O. The order of its three steps is load-bearing: stripping photo dates must happen before anything else, or `1997 Acura CL -- 01-28-2010.jpg` is read as a 2010 car.

**Files:**
- Create: `app/Services/Images/ModelYearMatcher.php`
- Test: `tests/Unit/Services/Images/ModelYearMatcherTest.php`

**Interfaces:**
- Consumes: nothing
- Produces: `ModelYearMatcher::modelYear(string $title, string $make): ?int`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Images;

use App\Services\Images\ModelYearMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Seeded with the real titles of Category:Acura CL YA1, which is where
 * every trap in this problem actually lives.
 */
class ModelYearMatcherTest extends TestCase
{
    private ModelYearMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new ModelYearMatcher;
    }

    public function test_a_leading_year_is_the_model_year(): void
    {
        $this->assertSame(1999, $this->matcher->modelYear('File:1999 Acura CL 3.0.jpg', 'Acura'));
        $this->assertSame(1999, $this->matcher->modelYear('File:1999 Acura CL.jpg', 'Acura'));
        $this->assertSame(1996, $this->matcher->modelYear('File:1996 Acura 3.0 CL 2017.1.23.jpg', 'Acura'));
    }

    public function test_a_trailing_photo_date_is_not_the_model_year(): void
    {
        // The single most damaging failure mode: 01-28-2010 is when the
        // photograph was taken. Reading it as a model year files a 1997
        // Acura CL under 2010.
        $this->assertSame(1997, $this->matcher->modelYear('File:1997 Acura CL -- 01-28-2010.jpg', 'Acura'));
        $this->assertSame(1997, $this->matcher->modelYear('File:1997 Acura CL, rear 8.2.20.jpg', 'Acura'));
    }

    public function test_a_year_range_is_not_an_exact_year(): void
    {
        $this->assertNull($this->matcher->modelYear('File:1997-1999 Acura 3.0CL — 04-25-2026.jpg', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:1998-1999 Acura CL -- 04-11-2012 1.JPG', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:1998-99 Acura CL.JPG', 'Acura'));
        $this->assertNull($this->matcher->modelYear("File:'98-'99 Acura CL.jpg", 'Acura'));
    }

    public function test_a_title_with_no_year_yields_null(): void
    {
        $this->assertNull($this->matcher->modelYear('File:1st gen Acura CL.JPG', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:First Acura CL.JPG', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:1st-Acura-CL-1.jpg', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:Clx.jpg', 'Acura'));
    }

    public function test_a_year_immediately_before_the_make_counts(): void
    {
        $this->assertSame(2005, $this->matcher->modelYear('File:Blue 2005 Cadillac STS.jpg', 'Cadillac'));
    }

    public function test_implausible_years_are_rejected(): void
    {
        $this->assertNull($this->matcher->modelYear('File:1023 Acura CL.jpg', 'Acura'));
        $this->assertNull($this->matcher->modelYear('File:3500 Acura CL.jpg', 'Acura'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test tests/Unit/Services/Images/ModelYearMatcherTest.php`
Expected: FAIL — `Class "App\Services\Images\ModelYearMatcher" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services\Images;

class ModelYearMatcher
{
    /**
     * Dates a photograph was taken, in the two forms Commons filenames use:
     * "01-28-2010", "8.2.20" and "2017.1.23". Stripped before anything else
     * looks for a year — otherwise the photo date wins over the model year.
     */
    private const PHOTO_DATE = '/(?<!\d)(?:\d{1,2}[-.\/]\d{1,2}[-.\/]\d{2,4}|\d{4}[-.\/]\d{1,2}[-.\/]\d{1,2})(?!\d)/';

    /**
     * "1997-1999", "1998-99", "'98-'99". A range names no single model year,
     * so under the exact-year policy the whole title is disqualified.
     */
    private const YEAR_RANGE = "/(?<!\d)(?:\d{4}|'\d{2})\s*[-\x{2013}\x{2014}]\s*'?\d{2,4}(?!\d)/u";

    private const EARLIEST = 1885;

    public function modelYear(string $title, string $make): ?int
    {
        $text = preg_replace('/^File:/i', '', $title);
        $text = preg_replace(self::PHOTO_DATE, ' ', $text);

        if (preg_match(self::YEAR_RANGE, $text) === 1) {
            return null;
        }

        if (preg_match('/^\s*(\d{4})\b/', $text, $m) === 1) {
            return $this->plausible((int) $m[1]);
        }

        if (preg_match('/(?<!\d)(\d{4})\s+'.preg_quote($make, '/').'/iu', $text, $m) === 1) {
            return $this->plausible((int) $m[1]);
        }

        return null;
    }

    /**
     * Four consecutive digits are not necessarily a year — "1023" and "3500"
     * are model designations. Bound to the era of the motor car.
     */
    private function plausible(int $year): ?int
    {
        return $year >= self::EARLIEST && $year <= (int) date('Y') + 2 ? $year : null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T app php artisan test tests/Unit/Services/Images/ModelYearMatcherTest.php`
Expected: PASS, 6 tests

- [ ] **Step 5: Commit**

```bash
docker compose exec -T app ./vendor/bin/pint app/Services/Images/ModelYearMatcher.php tests/Unit/Services/Images/ModelYearMatcherTest.php
git add app/Services/Images/ModelYearMatcher.php tests/Unit/Services/Images/ModelYearMatcherTest.php
git commit -m "feat(images): extract the model year a Commons file title asserts"
```

---

### Task 2: `CommonsCategoryResolver`

Turns a CSV `(make, model)` into an ordered list of candidate Commons category names, most specific first. Pure, no I/O. This is the component that lifts category resolution from 5/30 to 22/30.

**Files:**
- Create: `app/Services/Images/CommonsCategoryResolver.php`
- Test: `tests/Unit/Services/Images/CommonsCategoryResolverTest.php`

**Interfaces:**
- Consumes: `ModelSearchTermNormalizer::normalize(string $model): string` (existing, unchanged)
- Produces: `CommonsCategoryResolver::candidates(string $make, string $model): array<int, string>`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Images;

use App\Services\Images\CommonsCategoryResolver;
use PHPUnit\Framework\TestCase;

/**
 * Commons category names carry a make and a model and nothing else.
 * The CSV carries EPA trim, drivetrain and body qualifiers on top:
 * "Santa Fe XL AWD", "F150 Pickup 2WD FFV", "Cooper Hardtop 2 door".
 * Those extra tokens are why the old normalizer resolved 5 of 30 models.
 */
class CommonsCategoryResolverTest extends TestCase
{
    private CommonsCategoryResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new CommonsCategoryResolver;
    }

    public function test_candidates_run_most_specific_first(): void
    {
        $candidates = $this->resolver->candidates('Hyundai', 'Santa Fe XL AWD');

        $this->assertSame('Hyundai Santa Fe XL AWD', $candidates[0]);
        $this->assertContains('Hyundai Santa Fe XL', $candidates);
        $this->assertContains('Hyundai Santa Fe', $candidates);
        $this->assertLessThan(
            array_search('Hyundai Santa Fe', $candidates, true),
            array_search('Hyundai Santa Fe XL', $candidates, true),
            'A longer, more specific category must be probed before a shorter one.'
        );
    }

    public function test_the_engine_displacement_prefix_is_normalized_away(): void
    {
        // Category:Acura 2.3CL/3.0CL does not exist; Category:Acura CL does.
        $this->assertSame(['Acura CL'], $this->resolver->candidates('Acura', '2.3CL/3.0CL'));
    }

    public function test_drivetrain_and_body_qualifiers_are_stripped(): void
    {
        $this->assertContains('Ford F150', $this->resolver->candidates('Ford', 'F150 Pickup 2WD FFV'));
        $this->assertContains('BMW 328i', $this->resolver->candidates('BMW', '328i xDrive'));
        $this->assertContains('Cadillac STS', $this->resolver->candidates('Cadillac', 'STS AWD'));
    }

    public function test_parentheticals_are_dropped(): void
    {
        $this->assertContains('BMW i4', $this->resolver->candidates('BMW', 'i4 eDrive35 Gran Coupe (18 inch Wheels)'));
    }

    public function test_a_bare_make_is_never_a_candidate(): void
    {
        // Category:Mitsubishi is the whole brand. Probing it would attach
        // arbitrary Mitsubishis to a search for a specific truck.
        $this->assertNotContains('Mitsubishi', $this->resolver->candidates('Mitsubishi', 'Truck 2WD'));
        $this->assertNotContains('MINI', $this->resolver->candidates('MINI', 'Cooper Hardtop 2 door'));
    }

    public function test_a_single_token_model_yields_one_candidate(): void
    {
        $this->assertSame(['Saturn L200'], $this->resolver->candidates('Saturn', 'L200'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test tests/Unit/Services/Images/CommonsCategoryResolverTest.php`
Expected: FAIL — `Class "App\Services\Images\CommonsCategoryResolver" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services\Images;

class CommonsCategoryResolver
{
    /**
     * Tokens the EPA vehicle CSV carries that Commons category names never do.
     * Drivetrain, body style and powertrain qualifiers: a category is
     * "Ford F150", never "Ford F150 Pickup 2WD FFV".
     *
     * @var array<int, string>
     */
    private const QUALIFIERS = [
        'AWD', '4WD', '2WD', 'FWD', 'RWD', 'xDrive', 'sDrive', 'quattro', '4MATIC',
        'FFV', 'MHEV', 'PHEV', 'EcoDiesel', 'LWB', 'SWB', 'Pickup', 'Truck', 'Van',
        'Wagon', 'Convertible', 'Cabriolet', 'Roadster', 'Coupe', 'Sedan',
        'Hatchback', 'Hardtop', 'Gran Turismo', 'Gran Coupe', 'New',
    ];

    public function __construct(
        protected ModelSearchTermNormalizer $normalizer = new ModelSearchTermNormalizer,
    ) {}

    /**
     * Candidate Commons category names, most specific first.
     *
     * The caller probes them in order and takes the first that exists, so
     * ordering is the whole contract: a shorter name is a broader category,
     * and reaching it first would attach the wrong photographs.
     *
     * @return array<int, string>
     */
    public function candidates(string $make, string $model): array
    {
        $base = $this->collapse(preg_replace('/\([^)]*\)/', ' ', $this->normalizer->normalize($model)));
        $stripped = $this->stripQualifiers($base);

        $names = [];
        foreach ([$base, $stripped] as $candidate) {
            $this->push($names, $candidate);
        }

        $tokens = $stripped === '' ? [] : explode(' ', $stripped);

        // Shrink the token prefix, but never to nothing: one model token must
        // remain, so the bare make is never probed.
        for ($length = count($tokens) - 1; $length >= 1; $length--) {
            $this->push($names, implode(' ', array_slice($tokens, 0, $length)));
        }

        return array_map(static fn (string $name): string => trim($make).' '.$name, $names);
    }

    /**
     * @param  array<int, string>  $names
     */
    private function push(array &$names, string $candidate): void
    {
        $candidate = trim($this->collapse($candidate), " -");

        if ($candidate !== '' && ! in_array($candidate, $names, true)) {
            $names[] = $candidate;
        }
    }

    private function stripQualifiers(string $value): string
    {
        $alternatives = implode('|', array_map(
            static fn (string $q): string => preg_quote($q, '/'),
            self::QUALIFIERS,
        ));

        $value = preg_replace('/\b(?:'.$alternatives.')\b/iu', ' ', $value);
        $value = preg_replace('/\b\d+\s*(?:door|inch\s+Wheels)\b/iu', ' ', $value);

        return $this->collapse($value);
    }

    private function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T app php artisan test tests/Unit/Services/Images/CommonsCategoryResolverTest.php`
Expected: PASS, 6 tests

- [ ] **Step 5: Commit**

```bash
docker compose exec -T app ./vendor/bin/pint app/Services/Images/CommonsCategoryResolver.php tests/Unit/Services/Images/CommonsCategoryResolverTest.php
git add app/Services/Images/CommonsCategoryResolver.php tests/Unit/Services/Images/CommonsCategoryResolverTest.php
git commit -m "feat(images): map CSV make/model strings to Commons category candidates"
```

---

### Task 3: Schema, model and config for the lookup cache

Persistent cache so a model is resolved at most once. Without it, every year of every model re-probes Commons.

**Files:**
- Create: `database/migrations/2026_08_31_190000_create_commons_category_lookups_table.php`
- Create: `database/migrations/2026_08_31_190100_add_commons_category_to_car_searches_table.php`
- Create: `app/Models/CommonsCategoryLookup.php`
- Modify: `config/images.php`
- Modify: `app/Models/CarSearch.php` (add `commons_category` to `$fillable`)

**Interfaces:**
- Produces: `CommonsCategoryLookup` with `make`, `model`, `category` (nullable), `checked_at` (Carbon); config keys `images.wikimedia.category_miss_ttl_days` (30), `category_max_files` (500), `category_page_size` (200)

- [ ] **Step 1: Write the migrations**

`database/migrations/2026_08_31_190000_create_commons_category_lookups_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent cache of "which Commons category holds this model".
     *
     * Resolution costs roughly five API calls per make/model, and the CSV
     * holds 5,136 distinct pairs. Without a durable cache every year of
     * every model re-probes Commons.
     *
     * Kept separate from `car_models`, which holds the curated catalogue
     * keyed on catalogue names; this table is keyed on the raw CSV string
     * exactly as imported, qualifiers and all.
     */
    public function up(): void
    {
        Schema::create('commons_category_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('make');
            $table->string('model');
            // Null records a known miss, so a model with no category is
            // probed once rather than on every run.
            $table->string('category')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->unique(['make', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commons_category_lookups');
    }
};
```

`database/migrations/2026_08_31_190100_add_commons_category_to_car_searches_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which Commons category this search actually read.
     *
     * Two thirds of CSV rows store no image, for two different reasons:
     * no category could be resolved from the model string (27%), or the
     * category exists but holds no photograph naming that year (30%).
     * Those call for different follow-up and are otherwise indistinguishable.
     */
    public function up(): void
    {
        Schema::table('car_searches', function (Blueprint $table) {
            $table->string('commons_category')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('car_searches', function (Blueprint $table) {
            $table->dropColumn('commons_category');
        });
    }
};
```

- [ ] **Step 2: Write the model**

`app/Models/CommonsCategoryLookup.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommonsCategoryLookup extends Model
{
    protected $fillable = [
        'make',
        'model',
        'category',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 3: Add the config keys**

In `config/images.php`, inside the `wikimedia` array, after `'maxlag'`:

```php
        // A resolved category never stops existing, so hits are cached
        // forever. Misses expire, because Commons categories are created
        // over time and a model without one today may have one later.
        'category_miss_ttl_days' => env('WIKIMEDIA_CATEGORY_MISS_TTL_DAYS', 30),

        // Guards a synchronous request against a pathologically large tree.
        'category_max_files' => env('WIKIMEDIA_CATEGORY_MAX_FILES', 500),
        'category_page_size' => env('WIKIMEDIA_CATEGORY_PAGE_SIZE', 200),
```

- [ ] **Step 4: Add `commons_category` to `CarSearch::$fillable`**

In `app/Models/CarSearch.php`, add `'commons_category',` to `$fillable` immediately after `'model',`.

- [ ] **Step 5: Migrate and verify the suite is still green**

Run: `docker compose exec -T app php artisan migrate --force && docker compose exec -T app php artisan test`
Expected: migrations run; all existing tests still PASS (nothing consumes the new schema yet)

- [ ] **Step 6: Commit**

```bash
docker compose exec -T app ./vendor/bin/pint app/Models/CommonsCategoryLookup.php app/Models/CarSearch.php config/images.php database/migrations/
git add app/Models/CommonsCategoryLookup.php app/Models/CarSearch.php config/images.php database/migrations/2026_08_31_190000_create_commons_category_lookups_table.php database/migrations/2026_08_31_190100_add_commons_category_to_car_searches_table.php
git commit -m "feat(images): add the Commons category lookup cache and search provenance"
```

---

### Task 4: `WikimediaClient` category methods

Add category retrieval alongside the existing full-text methods. Additive — the old path still works and every existing test stays green. Deletion happens in Task 7.

**Files:**
- Modify: `app/Services/Images/WikimediaClient.php`
- Test: `tests/Feature/Services/Images/CategoryRetrievalTest.php`

**Interfaces:**
- Produces: `WikimediaClient::categoryExists(string $category): bool`, `WikimediaClient::filesInCategory(string $category): Collection`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services\Images;

use App\Services\Images\WikimediaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CategoryRetrievalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function page(int $id, string $title, string $mime = 'image/jpeg'): array
    {
        return [
            'pageid' => $id,
            'title' => $title,
            'imageinfo' => [[
                'url' => "https://example.com/{$id}.jpg",
                'thumburl' => "https://example.com/{$id}-thumb.jpg",
                'width' => 800,
                'height' => 600,
                'mime' => $mime,
                'extmetadata' => [],
            ]],
        ];
    }

    public function test_category_exists_reports_presence(): void
    {
        Http::fake(function ($request) {
            $titles = $request->data()['titles'] ?? '';

            return Http::response(['query' => ['pages' => [
                str_contains($titles, 'Acura CL')
                    ? ['title' => $titles, 'pageid' => 1]
                    : ['title' => $titles, 'missing' => true],
            ]]], 200);
        });

        $client = app(WikimediaClient::class);

        $this->assertTrue($client->categoryExists('Acura CL'));
        $this->assertFalse($client->categoryExists('Acura Nonexistent'));
    }

    public function test_files_in_category_are_returned_with_image_info(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [
                $this->page(1, 'File:1997 Acura CL.jpg'),
            ]]], 200),
        ]);

        $files = app(WikimediaClient::class)->filesInCategory('Acura CL');

        $this->assertCount(1, $files);
        $this->assertSame('File:1997 Acura CL.jpg', $files->first()['title']);
        $this->assertSame('https://example.com/1.jpg', $files->first()['source_url']);
    }

    public function test_the_query_asks_for_the_deep_category(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => []]], 200)]);

        app(WikimediaClient::class)->filesInCategory('Acura CL');

        Http::assertSent(fn ($request) => ($request->data()['gsrsearch'] ?? '') === 'deepcategory:"Acura CL"');
    }

    public function test_non_image_files_are_excluded(): void
    {
        // Commons categories contain PDFs and DjVu documents.
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [
                $this->page(1, 'File:Acura brochure.pdf', 'application/pdf'),
                $this->page(2, 'File:1997 Acura CL.jpg'),
            ]]], 200),
        ]);

        $files = app(WikimediaClient::class)->filesInCategory('Acura CL');

        $this->assertCount(1, $files);
        $this->assertSame('File:1997 Acura CL.jpg', $files->first()['title']);
    }

    public function test_pagination_follows_the_continue_cursor(): void
    {
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            $offset = (int) ($request->data()['gsroffset'] ?? 0);

            if ($offset === 0) {
                return Http::response([
                    'continue' => ['gsroffset' => 1],
                    'query' => ['pages' => [$this->page(1, 'File:1997 Acura CL.jpg')]],
                ], 200);
            }

            return Http::response(['query' => ['pages' => [$this->page(2, 'File:1999 Acura CL.jpg')]]], 200);
        });

        $files = app(WikimediaClient::class)->filesInCategory('Acura CL');

        $this->assertCount(2, $files, 'A truncated first page must be followed.');
        $this->assertSame(2, $calls);
    }

    public function test_results_are_cached_per_category(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => [
            $this->page(1, 'File:1997 Acura CL.jpg'),
        ]]], 200)]);

        $client = app(WikimediaClient::class);
        $client->filesInCategory('Acura CL');
        $client->filesInCategory('Acura CL');

        Http::assertSentCount(1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test tests/Feature/Services/Images/CategoryRetrievalTest.php`
Expected: FAIL — `Method App\Services\Images\WikimediaClient::categoryExists does not exist`

- [ ] **Step 3: Extract the shared request/block handling FIRST**

The two new methods call `request()`, so it has to exist before they do.

`searchImages()` currently inlines the HTTP call, the 429/403/503 block detection and the retry policy. Extract that into `request()` and have `searchImages()` call it, so block handling stays identical across every Commons call. Move the body of `searchImages()` from `Http::withHeaders(...)` through `$response->throw();` into:

```php
    /**
     * One Commons API call, with the shared retry, block detection and
     * etiquette headers. Every request to Commons goes through here so a
     * 429 is handled identically no matter which method triggered it.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function request(array $params): array
    {
        $baseUrl = config('images.wikimedia.base_url');
        $timeout = (int) config('images.wikimedia.timeout', 10);
        $retryTimes = (int) config('images.wikimedia.retry_times', 3);
        $retrySleep = (int) config('images.wikimedia.retry_sleep_ms', 200);
        $userAgent = (string) config('images.wikimedia.user_agent', 'CarsImagesApi/1.0 (Laravel)');

        $blockStatuses = [429, 403, 503];

        $response = Http::withHeaders([
            'User-Agent' => $userAgent,
        ])->timeout($timeout)
            ->retry($retryTimes, function (int $attempt) use ($retrySleep) {
                return $retrySleep * (2 ** ($attempt - 1));
            }, function ($exception, $request) use ($blockStatuses) {
                if ($exception instanceof RequestException) {
                    return ! in_array($exception->response->status(), $blockStatuses, true);
                }

                return true;
            }, false)
            ->get($baseUrl, array_merge([
                'format' => 'json',
                'formatversion' => 2,
                'origin' => '*',
                'maxlag' => config('images.wikimedia.maxlag', 5),
            ], $params));

        if (in_array($response->status(), $blockStatuses, true)) {
            throw new WikimediaBlockedException(
                statusCode: $response->status(),
                retryAfterSeconds: $response->header('Retry-After') !== ''
                    ? (int) $response->header('Retry-After')
                    : null,
                responseExcerpt: mb_substr($response->body(), 0, 1024),
            );
        }

        $response->throw();

        return $response->json() ?? [];
    }
```

Then rewrite `searchImages()` to call `request()` and keep its existing mapping and filtering. Run `docker compose exec -T app php artisan test` — every existing test must still pass before continuing.

- [ ] **Step 4: Add the two category methods to `WikimediaClient`**

Add to `app/Services/Images/WikimediaClient.php` (keep every existing method for now):

```php
    /**
     * Whether a Commons category page exists.
     *
     * `CommonsCategoryLocator` probes candidates with this, most specific
     * first, so a false answer must mean "absent" and never "request failed" —
     * a network error propagates rather than being reported as a miss and
     * cached as one.
     */
    public function categoryExists(string $category): bool
    {
        $response = $this->request([
            'action' => 'query',
            'titles' => 'Category:'.$category,
        ]);

        $page = $response['query']['pages'][0] ?? null;

        return $page !== null && ! ($page['missing'] ?? false);
    }

    /**
     * Every image file in a category and its subcategories.
     *
     * Returns the WHOLE category, deliberately: the caller filters by model
     * year afterwards, and `images_per_year` is applied to what survives that
     * filter. Truncating here instead would hand the year filter an arbitrary
     * slice — Category:Cadillac STS holds 56 files of which 6 name 2005, so a
     * 10-file fetch finds none of them.
     */
    public function filesInCategory(string $category): Collection
    {
        $ttl = (int) config('images.wikimedia.cache_ttl', 3600);

        return Cache::remember(
            'wikimedia_category_'.md5($category),
            $ttl,
            fn () => $this->fetchCategoryFiles($category),
        );
    }

    protected function fetchCategoryFiles(string $category): Collection
    {
        $max = (int) config('images.wikimedia.category_max_files', 500);
        $pageSize = (int) config('images.wikimedia.category_page_size', 200);

        $files = collect();
        $offset = 0;

        do {
            $response = $this->request([
                'action' => 'query',
                'prop' => 'imageinfo',
                'generator' => 'search',
                'gsrsearch' => 'deepcategory:"'.$category.'"',
                'gsrnamespace' => 6,
                'gsrlimit' => min($pageSize, $max - $files->count()),
                'gsroffset' => $offset,
                'iiprop' => 'url|size|mime|extmetadata',
                'iiurlwidth' => 1200,
            ]);

            $files = $files->merge(
                collect($response['query']['pages'] ?? [])
                    ->map(fn (array $page) => $this->mapPageToImage($page))
                    ->filter(fn (array $image) => $image['source_url'] !== null
                        && str_starts_with((string) ($image['mime'] ?? ''), 'image/'))
            );

            $offset = $response['continue']['gsroffset'] ?? null;
        } while ($offset !== null && $files->count() < $max);

        return $files->take($max)->values();
    }
```

- [ ] **Step 5: Run the whole suite**

Run: `docker compose exec -T app php artisan test`
Expected: PASS — the six new tests plus every pre-existing test, since the full-text path is unchanged in behaviour

- [ ] **Step 6: Commit**

```bash
docker compose exec -T app ./vendor/bin/pint app/Services/Images/WikimediaClient.php tests/Feature/Services/Images/CategoryRetrievalTest.php
git add app/Services/Images/WikimediaClient.php tests/Feature/Services/Images/CategoryRetrievalTest.php
git commit -m "feat(images): read whole Commons categories, with shared request handling"
```

---

### Task 5: `CommonsCategoryLocator`

Walks the candidates against the API and persists the winner — or the miss.

**Files:**
- Create: `app/Services/Images/CommonsCategoryLocator.php`
- Test: `tests/Feature/Services/Images/CommonsCategoryLocatorTest.php`

**Interfaces:**
- Consumes: `CommonsCategoryResolver::candidates()`, `WikimediaClient::categoryExists()`, `CommonsCategoryLookup`
- Produces: `CommonsCategoryLocator::locate(string $make, string $model): ?string`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services\Images;

use App\Models\CommonsCategoryLookup;
use App\Services\Images\CommonsCategoryLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommonsCategoryLocatorTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, string> $existing */
    private function fakeExistingCategories(array $existing): void
    {
        Http::fake(function ($request) use ($existing) {
            $titles = $request->data()['titles'] ?? '';
            $name = str_replace('Category:', '', $titles);

            return Http::response(['query' => ['pages' => [
                in_array($name, $existing, true)
                    ? ['title' => $titles, 'pageid' => 1]
                    : ['title' => $titles, 'missing' => true],
            ]]], 200);
        });
    }

    public function test_it_returns_the_most_specific_category_that_exists(): void
    {
        $this->fakeExistingCategories(['Hyundai Santa Fe']);

        $category = app(CommonsCategoryLocator::class)->locate('Hyundai', 'Santa Fe XL AWD');

        $this->assertSame('Hyundai Santa Fe', $category);
    }

    public function test_a_resolved_category_is_persisted_and_not_re_probed(): void
    {
        $this->fakeExistingCategories(['Acura CL']);

        $locator = app(CommonsCategoryLocator::class);
        $this->assertSame('Acura CL', $locator->locate('Acura', '2.3CL/3.0CL'));

        $callsAfterFirst = count(Http::recorded());

        $this->assertSame('Acura CL', $locator->locate('Acura', '2.3CL/3.0CL'));
        $this->assertCount($callsAfterFirst, Http::recorded(), 'A cached hit must cost no API calls.');

        $this->assertDatabaseHas('commons_category_lookups', [
            'make' => 'Acura', 'model' => '2.3CL/3.0CL', 'category' => 'Acura CL',
        ]);
    }

    public function test_a_miss_is_recorded_and_not_re_probed(): void
    {
        $this->fakeExistingCategories([]);

        $locator = app(CommonsCategoryLocator::class);
        $this->assertNull($locator->locate('Saturn', 'L200'));

        $callsAfterFirst = count(Http::recorded());

        $this->assertNull($locator->locate('Saturn', 'L200'));
        $this->assertCount($callsAfterFirst, Http::recorded(), 'A known miss must cost no API calls.');

        $this->assertDatabaseHas('commons_category_lookups', [
            'make' => 'Saturn', 'model' => 'L200', 'category' => null,
        ]);
    }

    public function test_a_stale_miss_is_re_probed(): void
    {
        // Commons categories are created over time, so a miss expires.
        CommonsCategoryLookup::create([
            'make' => 'Acura', 'model' => '2.3CL/3.0CL',
            'category' => null, 'checked_at' => now()->subDays(31),
        ]);

        $this->fakeExistingCategories(['Acura CL']);

        $this->assertSame('Acura CL', app(CommonsCategoryLocator::class)->locate('Acura', '2.3CL/3.0CL'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test tests/Feature/Services/Images/CommonsCategoryLocatorTest.php`
Expected: FAIL — `Target class [App\Services\Images\CommonsCategoryLocator] does not exist`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services\Images;

use App\Models\CommonsCategoryLookup;

class CommonsCategoryLocator
{
    public function __construct(
        protected CommonsCategoryResolver $resolver,
        protected WikimediaClient $wikimedia,
    ) {}

    /**
     * The Commons category holding photographs of this model, or null.
     *
     * Resolution costs roughly one API call per candidate, so the answer is
     * cached in the database rather than only in the request cache: the CSV
     * holds 5,136 distinct models and every year of each one asks again.
     */
    public function locate(string $make, string $model): ?string
    {
        $lookup = CommonsCategoryLookup::query()
            ->where('make', $make)
            ->where('model', $model)
            ->first();

        if ($lookup !== null && ! $this->isStaleMiss($lookup)) {
            return $lookup->category;
        }

        $category = null;

        foreach ($this->resolver->candidates($make, $model) as $candidate) {
            if ($this->wikimedia->categoryExists($candidate)) {
                $category = $candidate;
                break;
            }
        }

        CommonsCategoryLookup::updateOrCreate(
            ['make' => $make, 'model' => $model],
            ['category' => $category, 'checked_at' => now()],
        );

        return $category;
    }

    /**
     * A hit never expires — a category that exists does not stop existing.
     * A miss does, because Commons categories are created over time.
     */
    private function isStaleMiss(CommonsCategoryLookup $lookup): bool
    {
        if ($lookup->category !== null) {
            return false;
        }

        $days = (int) config('images.wikimedia.category_miss_ttl_days', 30);

        return $lookup->checked_at === null
            || $lookup->checked_at->lt(now()->subDays($days));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T app php artisan test tests/Feature/Services/Images/CommonsCategoryLocatorTest.php`
Expected: PASS, 4 tests

- [ ] **Step 5: Commit**

```bash
docker compose exec -T app ./vendor/bin/pint app/Services/Images/CommonsCategoryLocator.php tests/Feature/Services/Images/CommonsCategoryLocatorTest.php
git add app/Services/Images/CommonsCategoryLocator.php tests/Feature/Services/Images/CommonsCategoryLocatorTest.php
git commit -m "feat(images): resolve and cache the Commons category for a model"
```

---

### Task 6: Switch `CarImageSearchService` to category retrieval

The behaviour change. Obsolete tests are retired here, in the same commit that removes the behaviour they pin.

**Files:**
- Modify: `app/Services/Images/CarImageSearchService.php`
- Modify: `app/Services/Images/WikimediaClient.php` (add `forgetCategory()`, remove `clearSearchCache()`)
- Test: `tests/Feature/Services/Images/ExactYearImagesTest.php` (create)
- Delete: `tests/Feature/Services/Images/WikimediaRecallFallbackTest.php`
- Delete: `tests/Feature/Services/Images/YearRelaxedFallbackTest.php`
- Delete: `tests/Feature/Services/Images/OffMakeFilteringTest.php`
- Delete: `tests/Feature/Services/Search/CsvSearchQueryConstructionTest.php`

**Interfaces:**
- Consumes: `CommonsCategoryLocator::locate()`, `WikimediaClient::filesInCategory()`, `ModelYearMatcher::modelYear()`
- Produces: `CarImageSearchService::fetchAndStoreForYear(CarSearch $search, int $year, int $limit): Collection` (signature unchanged)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services\Images;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\User;
use App\Services\Images\CarImageSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The reported defect, end to end.
 *
 * Searching Acura 2.3CL/3.0CL for 1997, 1998 and 1999 stored one photograph
 * three times — and it was File:Clx.jpg, the Acura CL-X *concept car*, found
 * by a year-less full-text query that returned the same result for every year.
 *
 * Category:Acura CL YA1 holds the real cars. 1997 and 1999 are named exactly;
 * 1998 exists only inside "1998-1999" ranges, which the exact-year policy
 * excludes. So 1998 stores nothing, on purpose.
 */
class ExactYearImagesTest extends TestCase
{
    use RefreshDatabase;

    /** Titles as they really appear in Category:Acura CL YA1. */
    private const CL_FILES = [
        [1, 'File:1997 Acura CL -- 01-28-2010.jpg'],
        [2, 'File:1997 Acura CL, rear 8.2.20.jpg'],
        [3, 'File:1998-1999 Acura CL -- 04-11-2012 1.JPG'],
        [4, "File:'98-'99 Acura CL.jpg"],
        [5, 'File:1999 Acura CL 3.0.jpg'],
        [6, 'File:1999 Acura CL.jpg'],
        [7, 'File:1st gen Acura CL.JPG'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Http::fake(function ($request) {
            $data = $request->data();

            if (isset($data['titles'])) {
                $exists = $data['titles'] === 'Category:Acura CL';

                return Http::response(['query' => ['pages' => [
                    $exists ? ['title' => $data['titles'], 'pageid' => 1]
                            : ['title' => $data['titles'], 'missing' => true],
                ]]], 200);
            }

            return Http::response(['query' => ['pages' => array_map(
                fn (array $f) => [
                    'pageid' => $f[0],
                    'title' => $f[1],
                    'imageinfo' => [[
                        'url' => "https://example.com/{$f[0]}.jpg",
                        'thumburl' => "https://example.com/{$f[0]}-thumb.jpg",
                        'width' => 800, 'height' => 600,
                        'mime' => 'image/jpeg', 'extmetadata' => [],
                    ]],
                ],
                self::CL_FILES,
            )]], 200);
        });
    }

    private function runYear(int $year): CarSearch
    {
        $search = app(CarImageSearchService::class)->createSearch(
            User::factory()->create(), 'Acura', '2.3CL/3.0CL', $year, $year, null, null, false, 10
        );

        app(CarImageSearchService::class)->runSearch($search);

        return $search->fresh();
    }

    public function test_each_year_gets_only_photographs_naming_that_year(): void
    {
        $search = $this->runYear(1997);

        $titles = CarImage::where('car_search_id', $search->id)->pluck('title')->all();

        sort($titles);
        $this->assertSame([
            'File:1997 Acura CL -- 01-28-2010.jpg',
            'File:1997 Acura CL, rear 8.2.20.jpg',
        ], $titles);
    }

    public function test_a_year_named_only_inside_a_range_stores_nothing(): void
    {
        $search = $this->runYear(1998);

        $this->assertSame(0, CarImage::where('car_search_id', $search->id)->count());
        $this->assertSame('completed', $search->status, 'An empty result is a completed search, not a failed one.');
        $this->assertSame('Acura CL', $search->commons_category, 'The category resolved; it simply had no 1998 photograph.');
    }

    public function test_adjacent_years_never_share_a_photograph(): void
    {
        $first = $this->runYear(1997);
        $second = $this->runYear(1999);

        $a = CarImage::where('car_search_id', $first->id)->pluck('provider_image_id');
        $b = CarImage::where('car_search_id', $second->id)->pluck('provider_image_id');

        $this->assertNotEmpty($a);
        $this->assertNotEmpty($b);
        $this->assertEmpty($a->intersect($b), 'A title naming 1997 cannot also name 1999.');
    }

    public function test_the_concept_car_is_no_longer_reachable(): void
    {
        $search = $this->runYear(1999);

        $this->assertNotContains(
            'File:Clx.jpg',
            CarImage::where('car_search_id', $search->id)->pluck('title')->all()
        );
    }

    public function test_images_per_year_does_not_truncate_retrieval(): void
    {
        // Regression guard: applying the limit to the fetch instead of to the
        // filtered result would hand the year filter an arbitrary slice.
        $search = app(CarImageSearchService::class)->createSearch(
            User::factory()->create(), 'Acura', '2.3CL/3.0CL', 1999, 1999, null, null, false, 1
        );

        app(CarImageSearchService::class)->runSearch($search);

        $this->assertSame(1, CarImage::where('car_search_id', $search->id)->count());
        $this->assertStringContainsString(
            '1999',
            (string) CarImage::where('car_search_id', $search->id)->value('title'),
            'The one stored image must still be a 1999 car, not an arbitrary first file.'
        );
    }

    public function test_a_model_with_no_category_stores_nothing(): void
    {
        $search = app(CarImageSearchService::class)->createSearch(
            User::factory()->create(), 'Saturn', 'L200', 2003, 2003, null, null, false, 10
        );

        app(CarImageSearchService::class)->runSearch($search);

        $this->assertSame(0, CarImage::where('car_search_id', $search->id)->count());
        $this->assertNull($search->fresh()->commons_category);
        $this->assertSame('completed', $search->fresh()->status);
    }

    public function test_stored_images_are_marked_year_confirmed(): void
    {
        $search = $this->runYear(1997);

        $this->assertTrue(
            CarImage::where('car_search_id', $search->id)->get()
                ->every(fn (CarImage $i) => $i->year_confirmed === true)
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T app php artisan test tests/Feature/Services/Images/ExactYearImagesTest.php`
Expected: FAIL — searches still use the full-text path, so 1998 stores an image and `commons_category` is null

- [ ] **Step 3: Rewrite the service**

In `app/Services/Images/CarImageSearchService.php`:

Replace the constructor:

```php
    public function __construct(
        protected WikimediaClient $wikimedia,
        protected DatabaseManager $db,
        protected CommonsCategoryLocator $locator,
        protected ModelYearMatcher $yearMatcher = new ModelYearMatcher,
        protected MakeRelevanceChecker $makeChecker = new MakeRelevanceChecker,
    ) {}
```

Replace `runSearch()`:

```php
    public function runSearch(CarSearch $search): Collection
    {
        // Resolve before opening the transaction. The lookup row is cache
        // state, not search state: if the search fails later in this run the
        // resolution must survive, not roll back with it.
        $this->locator->locate($search->make, $search->model);

        return $this->db->transaction(fn () => $this->executeSearch($search));
    }
```

Replace `executeSearch()`:

```php
    private function executeSearch(CarSearch $search): Collection
    {
        $results = collect();

        $search->update([
            'status' => 'running',
            'commons_category' => $this->locator->locate($search->make, $search->model),
        ]);

        foreach (range($search->from_year, $search->to_year) as $year) {
            $results = $results->merge(
                $this->fetchAndStoreForYear($search, $year, $search->images_per_year)
            );
        }

        $search->update(['status' => 'completed']);

        return $results;
    }
```

Replace `fetchAndStoreForYear()` and delete `relevantImages()`, `transmissionForQuery()` and `knownMakes()` along with the `FALLBACK_MAKES` constant:

```php
    public function fetchAndStoreForYear(CarSearch $search, int $year, int $limit): Collection
    {
        $category = $this->locator->locate($search->make, $search->model);

        if ($category === null) {
            return collect();
        }

        // The whole category, then the year filter, then the limit. Applying
        // the limit to the fetch would hand the filter an arbitrary slice.
        return $this->wikimedia->filesInCategory($category)
            ->filter(fn (array $image) => $this->yearMatcher->modelYear(
                (string) ($image['title'] ?? ''),
                $search->make,
            ) === $year)
            ->take($limit)
            ->values()
            ->map(function (array $image) use ($search, $year) {
                $categories = $this->categoriesOf($image);

                return CarImage::updateOrCreate(
                    [
                        'car_search_id' => $search->id,
                        'year' => $year,
                        'provider' => $image['provider'],
                        'provider_image_id' => $image['provider_image_id'],
                    ],
                    [
                        'make' => $search->make,
                        'model' => $search->model,
                        'color' => $search->color,
                        'transparent_background' => $search->transparent_background,
                        'title' => $image['title'],
                        'description' => $image['description'],
                        'source_url' => $image['source_url'],
                        'thumbnail_url' => $image['thumbnail_url'],
                        'width' => $image['width'],
                        'height' => $image['height'],
                        'license' => $image['license'],
                        'attribution' => $image['attribution'],
                        'make_confirmed' => $this->makeChecker->isConfirmed(
                            $search->make,
                            $image['title'],
                            $image['description'],
                            $categories,
                        ),
                        // Exact-year by construction: the file's own title
                        // names this year, or it was not selected.
                        'year_confirmed' => true,
                        'download_status' => 'not_downloaded',
                        'download_path' => null,
                        'metadata' => $image['metadata'],
                    ]
                );
            });
    }
```

Replace the cache-eviction loop in `refreshSearch()` — there is no longer a per-year query to forget, only the category:

```php
    public function refreshSearch(CarSearch $search): Collection
    {
        $category = $this->locator->locate($search->make, $search->model);

        if ($category !== null) {
            $this->wikimedia->forgetCategory($category);
        }

        try {
            return $this->db->transaction(function () use ($search) {
                CarImage::where('car_search_id', $search->id)->delete();

                return $this->executeSearch($search->fresh());
            });
        } catch (Throwable $e) {
            $this->markFailed($search);

            throw $e;
        }
    }
```

Add to `WikimediaClient`, replacing `clearSearchCache()`:

```php
    public function forgetCategory(string $category): void
    {
        Cache::forget('wikimedia_category_'.md5($category));
    }
```

- [ ] **Step 4: Delete the tests that pin the removed behaviour**

```bash
git rm tests/Feature/Services/Images/WikimediaRecallFallbackTest.php \
       tests/Feature/Services/Images/YearRelaxedFallbackTest.php \
       tests/Feature/Services/Images/OffMakeFilteringTest.php \
       tests/Feature/Services/Search/CsvSearchQueryConstructionTest.php
```

`CsvSearchQueryConstructionTest` asserted that transmission never reaches `gsrsearch`. That is now structurally impossible — a category name has no transmission — and model normalization is covered by `CommonsCategoryResolverTest`.

- [ ] **Step 5: Run the whole suite and fix fallout**

Run: `docker compose exec -T app php artisan test`
Expected: PASS. `RefreshSearchTest`, `SharedImageOwnershipTest` and the Filament tests will need their `Http::fake` bodies updated to answer category probes and category listings rather than full-text searches; update them to the shape used in `ExactYearImagesTest::setUp()`.

- [ ] **Step 6: Commit**

```bash
docker compose exec -T app ./vendor/bin/pint app/Services/Images/ tests/
git add -A
git commit -m "feat(search): source images from Commons categories, exact model year only"
```

---

### Task 7: Delete the full-text path

**Files:**
- Modify: `app/Services/Images/WikimediaClient.php`
- Modify: `tests/Feature/WikimediaBlockHandlingTest.php`
- Modify: `tests/Feature/Services/Images/WikimediaImageFilterTest.php`

`ModelSearchTermNormalizer` and its test are **retained** — it is `CommonsCategoryResolver`'s first step, not part of the full-text path.

- [ ] **Step 1: Retarget the two tests that still call the old API**

In `tests/Feature/WikimediaBlockHandlingTest.php`, replace each `$client->searchCars('Toyota', 'RAV4', 1997, null, null, false, 5)` with `$client->filesInCategory('Toyota RAV4')`. The assertions about 429/403, User-Agent and `maxlag` are unchanged — they now cover `request()`, through which every Commons call passes.

In `tests/Feature/Services/Images/WikimediaImageFilterTest.php`, replace the `searchCars(...)` call with `filesInCategory('Toyota Camry')`. The MIME-filter assertions are unchanged.

- [ ] **Step 2: Delete the dead methods**

From `app/Services/Images/WikimediaClient.php` remove: `searchCars()`, `buildQuery()`, `cachedSearch()`, `searchImages()`, `cacheKey()`, `isCarImage()`, and the now-unused `ModelSearchTermNormalizer` constructor dependency (the resolver owns it).

From `app/Services/Images/CarImageSearchService.php` remove the `use App\Models\CarMake;` and `use App\Models\User;`-adjacent imports that are no longer referenced, and confirm `MakeRelevanceChecker::isOffMake()` has no remaining call site.

- [ ] **Step 3: Run the whole suite**

Run: `docker compose exec -T app php artisan test`
Expected: PASS

- [ ] **Step 4: Verify nothing references the removed methods**

Run: `grep -rn "searchCars\|isOffMake\|clearSearchCache\|isCarImage\|buildQuery" app/ tests/`
Expected: no output except `MakeRelevanceChecker::isOffMake()`'s own definition and `MakeRelevanceCheckerTest`. Delete both if the method has no call site.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T app ./vendor/bin/pint app/ tests/
git add -A
git commit -m "refactor(images): delete the Wikimedia full-text search path"
```

---

### Task 8: Surface the resolved category in the admin UI

Two thirds of rows store nothing, for two different reasons. The admin needs to tell them apart without opening a console.

**Files:**
- Modify: `app/Filament/Resources/SearchQueryResource.php`

- [ ] **Step 1: Add the column**

In the table columns of `app/Filament/Resources/SearchQueryResource.php`, after the `model` column:

```php
                Tables\Columns\TextColumn::make('commons_category')
                    ->label('Commons category')
                    ->placeholder('none found')
                    ->toggleable()
                    ->tooltip('The Commons category this search read. Empty means no category could be resolved from the model string; set with zero images means the category holds no photograph naming that year.'),
```

- [ ] **Step 2: Run the Filament tests**

Run: `docker compose exec -T app php artisan test tests/Feature/Filament/`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
docker compose exec -T app ./vendor/bin/pint app/Filament/Resources/SearchQueryResource.php
git add app/Filament/Resources/SearchQueryResource.php
git commit -m "feat(admin): show which Commons category each search read"
```

---

## Rollout (after all tasks)

Legacy rows still hold the concept car. They are identified by `year_confirmed` being false or null:

```sql
DELETE FROM car_images WHERE year_confirmed IS NULL OR year_confirmed = 0;
```

Then re-run the affected searches through the admin UI. The lookup cache warms as they go; no bulk sweep is needed, which matters because there is no queue worker to run one.
