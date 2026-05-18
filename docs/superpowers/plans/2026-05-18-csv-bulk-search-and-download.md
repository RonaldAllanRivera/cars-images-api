# CSV Bulk Search and Download Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a three-page Filament workflow (Upload CSV → Search Queries → Results) that lets an admin bulk-import car search rows, run them against Wikimedia one-by-one or in capped bulk batches with a loader, and download images individually or as ZIP/CSV exports with `"YEAR MAKE MODEL"` filenames.

**Architecture:** Foreground-only — no queue worker, no cron. CSV upload imports rows into `car_searches` (scoped via a new `csv_import_id` FK). The admin manually runs queries from the Search Queries page; each click runs synchronously via the existing `CarImageSearchService::runSearch()`. Bulk runs cap at 50 queries or 50 seconds. Wikimedia 429/403/503 responses raise a typed exception, log to `wikimedia_block_events`, mark the failing query `failed`, and stop bulk loops immediately.

**Tech Stack:** Laravel 12, Filament 4, MySQL 8.0, PHPUnit 11, Laravel HTTP client (`Http::fake()` for tests), Maatwebsite/ZipStream behaviour via Laravel's `Response::stream()` for ZIP, native `fputcsv` for CSV manifest.

**Working directory:** `/home/allan/code/laravel/cars-images-api`. **Branch:** `main`.

**Existing context the implementer must know:**
- `CarImageSearchService::runSearch(CarSearch $search): Collection` is the synchronous entry point that calls Wikimedia and persists `CarImage` rows.
- Status taxonomy on `car_searches.status`: existing code uses `pending`, `running`, `completed`. This plan adds `failed` as a fourth value (no migration needed — column is `string(32)`).
- `WikimediaClient` wraps Laravel's `Http::` facade. Tests use `Http::fake()` rather than real network calls.
- Filament 4's resource conventions: `app/Filament/Resources/{Name}Resource.php` + `Pages/` subdirectory.
- Existing `CarSearchResource` is for ad-hoc single searches (year range, color, transmission). The new `SearchQueryResource` is a separate resource scoped to CSV-imported queries. The two coexist without conflict.
- Commits follow `type(scope): subject` with a `Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>` trailer.

---

## Task 1: Config file and `.env` keys

**Files:**
- Create: `config/cars-images.php`
- Modify: `.env`
- Modify: `.env.example`

- [ ] **Step 1: Create the config file**

Create `config/cars-images.php`:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CSV Import
    |--------------------------------------------------------------------------
    */

    'csv_import_max_combos' => env('CSV_IMPORT_MAX_COMBOS', 1000),

    'csv_import_default_images_per_year' => env('CSV_IMPORT_DEFAULT_IMAGES_PER_YEAR', 5),

    /*
    |--------------------------------------------------------------------------
    | Bulk run pacing
    |--------------------------------------------------------------------------
    */

    'bulk_run_max_queries_per_chunk' => env('CARS_BULK_RUN_MAX_QUERIES', 50),

    'bulk_run_max_seconds_per_chunk' => env('CARS_BULK_RUN_MAX_SECONDS', 50),

    'bulk_run_sleep_seconds_between_queries' => env('CARS_BULK_RUN_SLEEP_SECONDS', 1),
];
```

- [ ] **Step 2: Update `.env`**

Add these lines after the existing `WIKIMEDIA_*` block (keep existing values, change the four marked):

```
WIKIMEDIA_USER_AGENT="CarsImagesApi/1.0 (https://cars-search.artworkwebsite.com; jaeron.rivera@gmail.com)"  # CHANGED
WIKIMEDIA_TIMEOUT=10
WIKIMEDIA_RETRY_TIMES=5  # CHANGED from 3
WIKIMEDIA_RETRY_SLEEP_MS=2000  # CHANGED from 200
WIKIMEDIA_CACHE_TTL=86400  # CHANGED from 3600
WIKIMEDIA_MAXLAG=5  # NEW

CSV_IMPORT_MAX_COMBOS=1000
CSV_IMPORT_DEFAULT_IMAGES_PER_YEAR=5
CARS_BULK_RUN_MAX_QUERIES=50
CARS_BULK_RUN_MAX_SECONDS=50
CARS_BULK_RUN_SLEEP_SECONDS=1
```

Locate the existing `WIKIMEDIA_USER_AGENT`, `WIKIMEDIA_RETRY_TIMES`, `WIKIMEDIA_RETRY_SLEEP_MS`, and `WIKIMEDIA_CACHE_TTL` lines and replace them with the values above. Add `WIKIMEDIA_MAXLAG=5` and the five `CSV_IMPORT_*` / `CARS_BULK_RUN_*` lines.

- [ ] **Step 3: Mirror changes in `.env.example`**

Same replacements in `.env.example`. The existing block uses `KEY=""` placeholders for some values — for our changed lines, use concrete values (we want fresh clones to boot with safe defaults):

```
WIKIMEDIA_USER_AGENT="CarsImagesApi/1.0 (https://cars-search.artworkwebsite.com; jaeron.rivera@gmail.com)"
WIKIMEDIA_RETRY_TIMES=5
WIKIMEDIA_RETRY_SLEEP_MS=2000
WIKIMEDIA_CACHE_TTL=86400
WIKIMEDIA_MAXLAG=5
CSV_IMPORT_MAX_COMBOS=1000
CSV_IMPORT_DEFAULT_IMAGES_PER_YEAR=5
CARS_BULK_RUN_MAX_QUERIES=50
CARS_BULK_RUN_MAX_SECONDS=50
CARS_BULK_RUN_SLEEP_SECONDS=1
```

- [ ] **Step 4: Verify**

Run:
```bash
docker compose exec app php artisan config:show cars-images
docker compose exec app php artisan tinker --execute="echo config('cars-images.csv_import_max_combos');"
```

Expected: `1000` printed.

- [ ] **Step 5: Commit**

```bash
git add config/cars-images.php .env.example
git commit -m "$(cat <<'EOF'
feat(config): add cars-images config + Wikimedia etiquette env defaults

Adds csv_import_max_combos, default images per year, and bulk-run
pacing knobs. Bumps Wikimedia retry/cache TTL and introduces maxlag=5
and contactable User-Agent per Wikimedia API:Etiquette.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

Note: `.env` is gitignored. Only `.env.example` and the new config file are committed.

---

## Task 2: Database migrations

**Files:**
- Create: `database/migrations/{ts}_create_csv_imports_table.php`
- Create: `database/migrations/{ts}_create_wikimedia_block_events_table.php`
- Create: `database/migrations/{ts}_add_csv_import_id_to_car_searches_table.php`

- [ ] **Step 1: Generate migration files**

```bash
docker compose exec app php artisan make:migration create_csv_imports_table
docker compose exec app php artisan make:migration create_wikimedia_block_events_table
docker compose exec app php artisan make:migration add_csv_import_id_to_car_searches_table
```

This produces three timestamped files in `database/migrations/`.

- [ ] **Step 2: Fill in `create_csv_imports_table`**

Replace the generated content of the `create_csv_imports_table` file with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('csv_imports', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename');
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('unique_combos');
            $table->unsignedInteger('duplicates_skipped');
            $table->foreignId('imported_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_imports');
    }
};
```

- [ ] **Step 3: Fill in `create_wikimedia_block_events_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wikimedia_block_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_search_id')->nullable()->constrained('car_searches')->nullOnDelete();
            $table->foreignId('csv_import_id')->nullable()->constrained('csv_imports')->nullOnDelete();
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->text('response_excerpt');
            $table->timestamp('occurred_at')->useCurrent();
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wikimedia_block_events');
    }
};
```

- [ ] **Step 4: Fill in `add_csv_import_id_to_car_searches_table`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('car_searches', function (Blueprint $table) {
            $table->foreignId('csv_import_id')
                ->nullable()
                ->after('requested_by')
                ->constrained('csv_imports')
                ->nullOnDelete();

            $table->index('csv_import_id');
        });
    }

    public function down(): void
    {
        Schema::table('car_searches', function (Blueprint $table) {
            $table->dropForeign(['csv_import_id']);
            $table->dropIndex(['csv_import_id']);
            $table->dropColumn('csv_import_id');
        });
    }
};
```

- [ ] **Step 5: Run and verify**

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan tinker --execute="echo \Schema::hasColumn('car_searches', 'csv_import_id') ? 'YES' : 'NO';"
docker compose exec app php artisan tinker --execute="echo \Schema::hasTable('csv_imports') ? 'YES' : 'NO';"
docker compose exec app php artisan tinker --execute="echo \Schema::hasTable('wikimedia_block_events') ? 'YES' : 'NO';"
```

Expected: three `YES` outputs.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/
git commit -m "$(cat <<'EOF'
feat(db): add csv_imports, wikimedia_block_events, and csv_import_id FK

csv_imports parents bulk-imported queries. wikimedia_block_events
captures every 429/403/503 from Wikimedia for visibility. The new FK
on car_searches links each query back to its CSV upload.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Models

**Files:**
- Create: `app/Models/CsvImport.php`
- Create: `app/Models/WikimediaBlockEvent.php`
- Modify: `app/Models/CarSearch.php`

- [ ] **Step 1: Create `CsvImport` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CsvImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'original_filename',
        'total_rows',
        'unique_combos',
        'duplicates_skipped',
        'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'unique_combos' => 'integer',
            'duplicates_skipped' => 'integer',
        ];
    }

    public function searches(): HasMany
    {
        return $this->hasMany(CarSearch::class, 'csv_import_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function blockEvents(): HasMany
    {
        return $this->hasMany(WikimediaBlockEvent::class);
    }
}
```

- [ ] **Step 2: Create `WikimediaBlockEvent` model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WikimediaBlockEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'car_search_id',
        'csv_import_id',
        'status_code',
        'retry_after_seconds',
        'response_excerpt',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'retry_after_seconds' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function carSearch(): BelongsTo
    {
        return $this->belongsTo(CarSearch::class);
    }

    public function csvImport(): BelongsTo
    {
        return $this->belongsTo(CsvImport::class);
    }
}
```

- [ ] **Step 3: Modify `CarSearch` model**

Open `app/Models/CarSearch.php` and:

1. Add `'csv_import_id'` to the `$fillable` array (append after `'requested_by'`).
2. Add these two methods inside the class (after the existing `requester()` method):

```php
    public function csvImport(): BelongsTo
    {
        return $this->belongsTo(CsvImport::class);
    }

    public function blockEvents(): HasMany
    {
        return $this->hasMany(WikimediaBlockEvent::class);
    }
```

The existing `use` statements at the top already include `HasMany` and `BelongsTo`, so no import changes are needed.

- [ ] **Step 4: Verify model wiring with tinker**

```bash
docker compose exec app php artisan tinker --execute="\App\Models\CsvImport::query()->count(); echo 'OK CsvImport';"
docker compose exec app php artisan tinker --execute="\App\Models\WikimediaBlockEvent::query()->count(); echo 'OK WikimediaBlockEvent';"
docker compose exec app php artisan tinker --execute="echo (new \App\Models\CarSearch())->csvImport() ? 'OK CarSearch relation' : 'FAIL';"
```

Expected: three `OK` lines.

- [ ] **Step 5: Commit**

```bash
git add app/Models/
git commit -m "$(cat <<'EOF'
feat(models): add CsvImport, WikimediaBlockEvent, link CarSearch

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: WikimediaBlockedException + WikimediaClient updates

**Files:**
- Create: `app/Exceptions/WikimediaBlockedException.php`
- Modify: `app/Services/Images/WikimediaClient.php`
- Test: `tests/Feature/WikimediaBlockHandlingTest.php`

- [ ] **Step 1: Create the exception class**

```php
<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class WikimediaBlockedException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?int $retryAfterSeconds,
        public readonly string $responseExcerpt,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Wikimedia returned HTTP %d (Retry-After: %s)', $statusCode, $retryAfterSeconds ?? 'n/a'),
            0,
            $previous,
        );
    }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/WikimediaBlockHandlingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Exceptions\WikimediaBlockedException;
use App\Services\Images\WikimediaClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WikimediaBlockHandlingTest extends TestCase
{
    public function test_throws_wikimedia_blocked_exception_on_429(): void
    {
        Http::fake([
            '*' => Http::response('Rate limit exceeded', 429, ['Retry-After' => '60']),
        ]);

        /** @var WikimediaClient $client */
        $client = app(WikimediaClient::class);

        try {
            $client->searchCars('Toyota', 'RAV4', 1997, null, null, false, 5);
            $this->fail('Expected WikimediaBlockedException');
        } catch (WikimediaBlockedException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertSame(60, $e->retryAfterSeconds);
            $this->assertStringContainsString('Rate limit', $e->responseExcerpt);
        }
    }

    public function test_throws_wikimedia_blocked_exception_on_403(): void
    {
        Http::fake([
            '*' => Http::response('Forbidden', 403),
        ]);

        /** @var WikimediaClient $client */
        $client = app(WikimediaClient::class);

        $this->expectException(WikimediaBlockedException::class);

        $client->searchCars('Toyota', 'RAV4', 1997, null, null, false, 5);
    }

    public function test_user_agent_includes_contact_info(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['search' => []]], 200),
        ]);

        /** @var WikimediaClient $client */
        $client = app(WikimediaClient::class);

        $client->searchCars('Toyota', 'RAV4', 1997, null, null, false, 5);

        Http::assertSent(function ($request) {
            $ua = $request->header('User-Agent')[0] ?? '';

            return str_contains($ua, 'CarsImagesApi/1.0')
                && (str_contains($ua, 'http') || str_contains($ua, '@'));
        });
    }

    public function test_request_includes_maxlag_parameter(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['search' => []]], 200),
        ]);

        /** @var WikimediaClient $client */
        $client = app(WikimediaClient::class);

        $client->searchCars('Toyota', 'RAV4', 1997, null, null, false, 5);

        Http::assertSent(function ($request) {
            return ($request->data()['maxlag'] ?? null) == 5
                || str_contains($request->url(), 'maxlag=5');
        });
    }
}
```

- [ ] **Step 3: Run tests — verify they fail**

```bash
docker compose exec app php artisan test --filter=WikimediaBlockHandlingTest
```

Expected: tests fail (some will fail with "expected exception not thrown", others on user-agent / maxlag assertions). All four should be red.

- [ ] **Step 4: Read the current `WikimediaClient`**

```bash
docker compose exec app cat app/Services/Images/WikimediaClient.php
```

Identify where `Http::` is called and where the User-Agent is set. The current UA is read from `config('services.wikimedia.user_agent')` or similar.

- [ ] **Step 5: Modify `WikimediaClient`**

Make three changes:

**5a. Add `maxlag` to query parameters.** Find the array of query parameters sent to the MediaWiki API (typically named `$params` or similar inside a method like `searchImages` or `fetchImageInfo`). Add this line where the parameters are built:

```php
$params['maxlag'] = (int) env('WIKIMEDIA_MAXLAG', 5);
```

If the file uses `config()` for env access, prefer adding `'maxlag' => env('WIKIMEDIA_MAXLAG', 5)` directly to the param array literal.

**5b. Detect block responses and throw.** Wherever the response is received (look for `->throw()`, `->successful()`, or `Http::retry(...)->get(...)`), wrap the response handling to catch 429/403/503 BEFORE other error handling:

Replace the existing response handling pattern. Where you see:

```php
$response = Http::withHeaders([...])->retry(...)->get($url, $params);

if (! $response->successful()) {
    throw new \RuntimeException(...);
}
```

…with:

```php
$response = Http::withHeaders([...])->retry(...)->get($url, $params);

if (in_array($response->status(), [429, 403, 503], true)) {
    throw new \App\Exceptions\WikimediaBlockedException(
        statusCode: $response->status(),
        retryAfterSeconds: $response->header('Retry-After') !== ''
            ? (int) $response->header('Retry-After')
            : null,
        responseExcerpt: mb_substr($response->body(), 0, 1024),
    );
}

if (! $response->successful()) {
    throw new \RuntimeException(...);
}
```

Add `use App\Exceptions\WikimediaBlockedException;` at the top of the file.

**5c. Update the User-Agent.** The UA is already configurable via `WIKIMEDIA_USER_AGENT` env. Task 1 already set the contact-info value in `.env`. No code change needed if the client reads `env('WIKIMEDIA_USER_AGENT')` or a config value sourced from that env. Verify by inspecting the client; if the UA is hardcoded, replace with `env('WIKIMEDIA_USER_AGENT', 'CarsImagesApi/1.0 (https://cars-search.artworkwebsite.com; jaeron.rivera@gmail.com)')`.

- [ ] **Step 6: Run tests — verify they pass**

```bash
docker compose exec app php artisan test --filter=WikimediaBlockHandlingTest
```

Expected: 4/4 pass.

If `test_user_agent_includes_contact_info` fails, check that the `.env` change from Task 1 was applied and config cache cleared: `docker compose exec app php artisan config:clear`.

- [ ] **Step 7: Run the full test suite — no regressions**

```bash
docker compose exec app php artisan test
```

Expected: all green. Existing `ExampleTest` and `WikimediaBlockHandlingTest` pass.

- [ ] **Step 8: Commit**

```bash
git add app/Exceptions/WikimediaBlockedException.php app/Services/Images/WikimediaClient.php tests/Feature/WikimediaBlockHandlingTest.php
git commit -m "$(cat <<'EOF'
feat(wikimedia): typed exception on 429/403/503 + maxlag + UA fix

Wikimedia 429/403/503 now raises WikimediaBlockedException carrying
status, Retry-After, and a 1KB response excerpt. Adds maxlag=5 to
every request per API:Etiquette. Bulk-run handlers (next task)
catch this to stop loops and write block events.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: FilenameBuilder service (pure logic, TDD)

**Files:**
- Create: `app/Services/Downloads/FilenameBuilder.php`
- Test: `tests/Unit/Services/Downloads/FilenameBuilderTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/Downloads/FilenameBuilderTest.php`:

```php
<?php

namespace Tests\Unit\Services\Downloads;

use App\Services\Downloads\FilenameBuilder;
use PHPUnit\Framework\TestCase;

class FilenameBuilderTest extends TestCase
{
    private FilenameBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FilenameBuilder();
    }

    public function test_builds_basic_filename(): void
    {
        $name = $this->builder->build(1997, 'Toyota', 'RAV4', 'jpg');

        $this->assertSame('1997 Toyota RAV4.jpg', $name);
    }

    public function test_replaces_slash_with_dash(): void
    {
        $name = $this->builder->build(1998, 'Acura', '2.2CL/3.0CL', 'jpg');

        $this->assertSame('1998 Acura 2.2CL - 3.0CL.jpg', $name);
    }

    public function test_replaces_all_unsafe_chars(): void
    {
        $name = $this->builder->build(2024, 'Make', 'A:B*C?D"E<F>G|H\\I', 'png');

        $this->assertStringNotContainsString(':', $name);
        $this->assertStringNotContainsString('*', $name);
        $this->assertStringNotContainsString('?', $name);
        $this->assertStringNotContainsString('"', $name);
        $this->assertStringNotContainsString('<', $name);
        $this->assertStringNotContainsString('>', $name);
        $this->assertStringNotContainsString('|', $name);
        $this->assertStringNotContainsString('\\', $name);
        $this->assertStringEndsWith('.png', $name);
    }

    public function test_collapses_multiple_spaces(): void
    {
        $name = $this->builder->build(2020, 'Make  Name', 'Model   X', 'jpg');

        $this->assertSame('2020 Make Name Model X.jpg', $name);
    }

    public function test_caps_length_at_200_chars_plus_extension(): void
    {
        $longModel = str_repeat('A', 500);
        $name = $this->builder->build(2024, 'Toyota', $longModel, 'jpg');

        // Base (before extension) must be ≤ 200 chars
        $base = preg_replace('/\.jpg$/', '', $name);
        $this->assertLessThanOrEqual(200, mb_strlen($base));
    }

    public function test_dedup_returns_base_for_first_occurrence(): void
    {
        $used = [];
        $name = $this->builder->buildUnique(1997, 'Toyota', 'RAV4', 'jpg', $used);

        $this->assertSame('1997 Toyota RAV4.jpg', $name);
        $this->assertArrayHasKey('1997 Toyota RAV4.jpg', $used);
    }

    public function test_dedup_appends_counter_on_collision(): void
    {
        $used = ['1997 Toyota RAV4.jpg' => true];
        $name = $this->builder->buildUnique(1997, 'Toyota', 'RAV4', 'jpg', $used);

        $this->assertSame('1997 Toyota RAV4 2.jpg', $name);
    }

    public function test_dedup_continues_counting(): void
    {
        $used = [
            '1997 Toyota RAV4.jpg' => true,
            '1997 Toyota RAV4 2.jpg' => true,
            '1997 Toyota RAV4 3.jpg' => true,
        ];
        $name = $this->builder->buildUnique(1997, 'Toyota', 'RAV4', 'jpg', $used);

        $this->assertSame('1997 Toyota RAV4 4.jpg', $name);
    }

    public function test_extension_defaults_to_jpg_when_empty(): void
    {
        $name = $this->builder->build(2015, 'Mitsubishi', 'Mirage', '');

        $this->assertSame('2015 Mitsubishi Mirage.jpg', $name);
    }

    public function test_trim_leading_trailing_whitespace(): void
    {
        $name = $this->builder->build(2020, '  Toyota  ', '  RAV4  ', 'jpg');

        $this->assertSame('2020 Toyota RAV4.jpg', $name);
    }
}
```

- [ ] **Step 2: Run tests — verify they fail**

```bash
docker compose exec app php artisan test --filter=FilenameBuilderTest
```

Expected: all fail with "Class FilenameBuilder not found".

- [ ] **Step 3: Implement `FilenameBuilder`**

Create `app/Services/Downloads/FilenameBuilder.php`:

```php
<?php

namespace App\Services\Downloads;

class FilenameBuilder
{
    private const UNSAFE_CHARS = ['/', '\\', ':', '*', '?', '"', '<', '>', '|'];
    private const MAX_BASE_LENGTH = 200;
    private const DEFAULT_EXTENSION = 'jpg';

    /**
     * Generate a deterministic, filesystem-safe base filename.
     *
     * Output: "YEAR MAKE MODEL.ext", e.g. "1997 Toyota RAV4.jpg".
     */
    public function build(int $year, string $make, string $model, string $extension): string
    {
        $base = sprintf('%d %s %s', $year, $make, $model);
        $base = str_replace(self::UNSAFE_CHARS, ' - ', $base);
        $base = preg_replace('/\s+/', ' ', $base);
        $base = trim($base);
        $base = mb_substr($base, 0, self::MAX_BASE_LENGTH);

        $extension = $extension === '' ? self::DEFAULT_EXTENSION : strtolower($extension);
        $extension = preg_replace('/[^a-z0-9]/', '', $extension);
        if ($extension === '') {
            $extension = self::DEFAULT_EXTENSION;
        }

        return $base . '.' . $extension;
    }

    /**
     * Generate a filename guaranteed unique within $usedNames.
     * Mutates $usedNames to track issued names.
     *
     * Collision pattern: "BASE.ext", then "BASE 2.ext", "BASE 3.ext", ...
     */
    public function buildUnique(
        int $year,
        string $make,
        string $model,
        string $extension,
        array &$usedNames
    ): string {
        $candidate = $this->build($year, $make, $model, $extension);

        if (! isset($usedNames[$candidate])) {
            $usedNames[$candidate] = true;
            return $candidate;
        }

        // Split base from extension to insert counter before extension
        $extPos = strrpos($candidate, '.');
        $base = substr($candidate, 0, $extPos);
        $ext = substr($candidate, $extPos);

        $counter = 2;
        do {
            $candidate = "{$base} {$counter}{$ext}";
            $counter++;
        } while (isset($usedNames[$candidate]));

        $usedNames[$candidate] = true;

        return $candidate;
    }
}
```

- [ ] **Step 4: Run tests — verify they pass**

```bash
docker compose exec app php artisan test --filter=FilenameBuilderTest
```

Expected: 10/10 pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Downloads/FilenameBuilder.php tests/Unit/Services/Downloads/FilenameBuilderTest.php
git commit -m "$(cat <<'EOF'
feat(downloads): FilenameBuilder service for safe car-image filenames

Sanitizes year/make/model into "1997 Toyota RAV4.jpg" style names,
replaces filesystem-unsafe chars, caps length at 200, and provides
buildUnique() for ZIP-style duplicate suffix counters.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: CsvQueryImporter service

**Files:**
- Create: `app/Services/Imports/CsvQueryImporter.php`
- Create: `app/Services/Imports/CsvImportResult.php`
- Create: `app/Services/Imports/CsvImportException.php`
- Test: `tests/Feature/Services/Imports/CsvQueryImporterTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/Imports/CsvQueryImporterTest.php`:

```php
<?php

namespace Tests\Feature\Services\Imports;

use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use App\Services\Imports\CsvImportException;
use App\Services\Imports\CsvQueryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CsvQueryImporterTest extends TestCase
{
    use RefreshDatabase;

    private function makeCsv(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'sample.csv', 'text/csv', null, true);
    }

    public function test_imports_unique_year_make_model_rows(): void
    {
        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<CSV
        Make,Model,Year,Transmission
        Toyota,RAV4,1997,Automatic 4-spd
        Toyota,Camry,1998,Manual 5-spd
        Mitsubishi,Mirage,2015,Automatic
        CSV);

        $importer = app(CsvQueryImporter::class);
        $result = $importer->import($csv, $user);

        $this->assertInstanceOf(CsvImport::class, $result->csvImport);
        $this->assertSame(3, $result->csvImport->total_rows);
        $this->assertSame(3, $result->csvImport->unique_combos);
        $this->assertSame(0, $result->csvImport->duplicates_skipped);
        $this->assertSame(3, CarSearch::count());
        $this->assertSame(3, CarSearch::where('csv_import_id', $result->csvImport->id)->count());
    }

    public function test_deduplicates_by_year_make_model_ignoring_transmission(): void
    {
        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<CSV
        Make,Model,Year,Transmission
        Toyota,RAV4,1997,Automatic 4-spd
        Toyota,RAV4,1997,Manual 5-spd
        Toyota,RAV4,1997,Automatic 4-spd
        CSV);

        $result = app(CsvQueryImporter::class)->import($csv, $user);

        $this->assertSame(3, $result->csvImport->total_rows);
        $this->assertSame(1, $result->csvImport->unique_combos);
        $this->assertSame(2, $result->csvImport->duplicates_skipped);
        $this->assertSame(1, CarSearch::count());
    }

    public function test_sets_from_year_and_to_year_to_same_value(): void
    {
        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<CSV
        Make,Model,Year,Transmission
        Honda,Civic,2010,Manual
        CSV);

        app(CsvQueryImporter::class)->import($csv, $user);

        $search = CarSearch::first();
        $this->assertSame(2010, $search->from_year);
        $this->assertSame(2010, $search->to_year);
        $this->assertSame('Honda', $search->make);
        $this->assertSame('Civic', $search->model);
        $this->assertSame('pending', $search->status);
        $this->assertSame(5, $search->images_per_year); // from cars-images.csv_import_default_images_per_year
    }

    public function test_rejects_when_unique_combos_exceeds_max(): void
    {
        config(['cars-images.csv_import_max_combos' => 2]);

        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<CSV
        Make,Model,Year,Transmission
        Toyota,RAV4,1997,A
        Toyota,Camry,1998,B
        Honda,Civic,2010,C
        CSV);

        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessageMatches('/exceeds.*2/i');

        app(CsvQueryImporter::class)->import($csv, $user);
    }

    public function test_skips_rows_with_invalid_year(): void
    {
        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<CSV
        Make,Model,Year,Transmission
        Toyota,RAV4,1997,A
        Toyota,Camry,abc,B
        Honda,Civic,1800,C
        CSV);

        $result = app(CsvQueryImporter::class)->import($csv, $user);

        $this->assertSame(1, CarSearch::count());
    }

    public function test_rejects_missing_required_columns(): void
    {
        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<CSV
        Make,Year,Transmission
        Toyota,1997,A
        CSV);

        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessageMatches('/Model/');

        app(CsvQueryImporter::class)->import($csv, $user);
    }
}
```

- [ ] **Step 2: Run tests — verify they fail**

```bash
docker compose exec app php artisan test --filter=CsvQueryImporterTest
```

Expected: all fail with class-not-found errors.

- [ ] **Step 3: Create the exception**

```php
<?php

namespace App\Services\Imports;

use RuntimeException;

class CsvImportException extends RuntimeException
{
}
```

- [ ] **Step 4: Create the result DTO**

`app/Services/Imports/CsvImportResult.php`:

```php
<?php

namespace App\Services\Imports;

use App\Models\CsvImport;

class CsvImportResult
{
    public function __construct(
        public readonly CsvImport $csvImport,
        public readonly int $skippedInvalidRows,
    ) {
    }
}
```

- [ ] **Step 5: Implement `CsvQueryImporter`**

`app/Services/Imports/CsvQueryImporter.php`:

```php
<?php

namespace App\Services\Imports;

use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CsvQueryImporter
{
    private const REQUIRED_COLUMNS = ['Make', 'Model', 'Year'];

    public function import(UploadedFile $file, User $user): CsvImportResult
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new CsvImportException('Unable to open uploaded CSV.');
        }

        try {
            $headers = fgetcsv($handle);
            if ($headers === false) {
                throw new CsvImportException('CSV is empty.');
            }

            $headers = array_map('trim', $headers);
            $missing = array_diff(self::REQUIRED_COLUMNS, $headers);
            if (! empty($missing)) {
                throw new CsvImportException(
                    'Missing required columns: ' . implode(', ', $missing) . '. Required: Make, Model, Year.'
                );
            }

            $columnIndex = array_flip($headers);
            $makeIdx = $columnIndex['Make'];
            $modelIdx = $columnIndex['Model'];
            $yearIdx = $columnIndex['Year'];

            $maxYear = (int) date('Y') + 1;
            $minYear = 1900;
            $totalRows = 0;
            $skippedInvalid = 0;
            $uniqueCombos = []; // key: "year|make|model" → first occurrence row data

            while (($row = fgetcsv($handle)) !== false) {
                $totalRows++;
                $make = trim($row[$makeIdx] ?? '');
                $model = trim($row[$modelIdx] ?? '');
                $year = trim($row[$yearIdx] ?? '');

                if ($make === '' || $model === '' || $year === '' || ! ctype_digit($year)) {
                    $skippedInvalid++;
                    continue;
                }

                $yearInt = (int) $year;
                if ($yearInt < $minYear || $yearInt > $maxYear) {
                    $skippedInvalid++;
                    continue;
                }

                $key = $yearInt . '|' . $make . '|' . $model;
                if (! isset($uniqueCombos[$key])) {
                    $uniqueCombos[$key] = [
                        'year' => $yearInt,
                        'make' => $make,
                        'model' => $model,
                    ];
                }
            }

            $uniqueCount = count($uniqueCombos);
            $maxCombos = (int) config('cars-images.csv_import_max_combos');
            if ($uniqueCount > $maxCombos) {
                throw new CsvImportException(
                    "CSV produces {$uniqueCount} unique queries, which exceeds the limit of {$maxCombos}. Split the CSV externally and retry."
                );
            }

            $imagesPerYear = (int) config('cars-images.csv_import_default_images_per_year');

            return DB::transaction(function () use ($file, $user, $totalRows, $uniqueCount, $uniqueCombos, $imagesPerYear, $skippedInvalid) {
                $csvImport = CsvImport::create([
                    'original_filename' => $file->getClientOriginalName(),
                    'total_rows' => $totalRows,
                    'unique_combos' => $uniqueCount,
                    'duplicates_skipped' => $totalRows - $uniqueCount - $skippedInvalid,
                    'imported_by' => $user->id,
                ]);

                $now = now();
                $rows = [];
                foreach ($uniqueCombos as $combo) {
                    $rows[] = [
                        'make' => $combo['make'],
                        'model' => $combo['model'],
                        'from_year' => $combo['year'],
                        'to_year' => $combo['year'],
                        'color' => null,
                        'transmission' => null,
                        'transparent_background' => false,
                        'images_per_year' => $imagesPerYear,
                        'status' => 'pending',
                        'requested_by' => $user->id,
                        'csv_import_id' => $csvImport->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                // Bulk insert in chunks to keep memory steady
                foreach (array_chunk($rows, 500) as $chunk) {
                    CarSearch::insert($chunk);
                }

                return new CsvImportResult($csvImport, $skippedInvalid);
            });
        } finally {
            fclose($handle);
        }
    }
}
```

- [ ] **Step 6: Run tests — verify they pass**

```bash
docker compose exec app php artisan test --filter=CsvQueryImporterTest
```

Expected: 6/6 pass.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Imports/ tests/Feature/Services/Imports/
git commit -m "$(cat <<'EOF'
feat(imports): CsvQueryImporter parses CSV into pending car_searches

Streams CSV via fgetcsv (memory-safe), deduplicates by (Year, Make,
Model), enforces csv_import_max_combos, and bulk-inserts in chunks
of 500. Returns a CsvImportResult with the parent CsvImport row.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: RunSearchQueryAction service

**Files:**
- Create: `app/Services/Search/RunSearchQueryAction.php`
- Test: `tests/Feature/Services/Search/RunSearchQueryActionTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/Services/Search/RunSearchQueryActionTest.php`:

```php
<?php

namespace Tests\Feature\Services\Search;

use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use App\Models\WikimediaBlockEvent;
use App\Services\Search\RunSearchQueryAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunSearchQueryActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeImportedSearch(): CarSearch
    {
        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 'test.csv',
            'total_rows' => 1,
            'unique_combos' => 1,
            'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        return CarSearch::create([
            'make' => 'Toyota',
            'model' => 'RAV4',
            'from_year' => 1997,
            'to_year' => 1997,
            'color' => null,
            'transmission' => null,
            'transparent_background' => false,
            'images_per_year' => 5,
            'status' => 'pending',
            'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]);
    }

    public function test_marks_search_completed_on_success(): void
    {
        Http::fake([
            '*' => Http::response([
                'query' => ['search' => []],
            ], 200),
        ]);

        $search = $this->makeImportedSearch();

        app(RunSearchQueryAction::class)->execute($search);

        $this->assertSame('completed', $search->fresh()->status);
        $this->assertSame(0, WikimediaBlockEvent::count());
    }

    public function test_marks_search_failed_and_logs_block_event_on_429(): void
    {
        Http::fake([
            '*' => Http::response('Rate limit exceeded', 429, ['Retry-After' => '120']),
        ]);

        $search = $this->makeImportedSearch();

        $threw = false;
        try {
            app(RunSearchQueryAction::class)->execute($search);
        } catch (\App\Exceptions\WikimediaBlockedException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Action should re-throw WikimediaBlockedException for bulk loops to catch');
        $this->assertSame('failed', $search->fresh()->status);
        $this->assertSame(1, WikimediaBlockEvent::count());

        $event = WikimediaBlockEvent::first();
        $this->assertSame(429, $event->status_code);
        $this->assertSame(120, $event->retry_after_seconds);
        $this->assertSame($search->id, $event->car_search_id);
        $this->assertSame($search->csv_import_id, $event->csv_import_id);
        $this->assertStringContainsString('Rate limit', $event->response_excerpt);
    }

    public function test_marks_search_failed_on_generic_runtime_exception(): void
    {
        Http::fake([
            '*' => Http::response('Internal server error', 500),
        ]);

        $search = $this->makeImportedSearch();

        try {
            app(RunSearchQueryAction::class)->execute($search);
        } catch (\Throwable $e) {
            // Expected — generic failure is also re-thrown
        }

        $this->assertSame('failed', $search->fresh()->status);
        $this->assertSame(0, WikimediaBlockEvent::count(), 'Generic 500 should not create a block event');
    }
}
```

- [ ] **Step 2: Run tests — verify they fail**

```bash
docker compose exec app php artisan test --filter=RunSearchQueryActionTest
```

Expected: fails on missing class.

- [ ] **Step 3: Implement `RunSearchQueryAction`**

```php
<?php

namespace App\Services\Search;

use App\Exceptions\WikimediaBlockedException;
use App\Models\CarSearch;
use App\Models\WikimediaBlockEvent;
use App\Services\Images\CarImageSearchService;
use Throwable;

class RunSearchQueryAction
{
    public function __construct(
        protected CarImageSearchService $searchService,
    ) {
    }

    /**
     * Run a single CarSearch synchronously.
     *
     * Marks the search as `completed` on success, `failed` on any throw.
     * On WikimediaBlockedException, records a wikimedia_block_events row
     * before re-throwing so the bulk-run caller can stop the loop.
     */
    public function execute(CarSearch $search): void
    {
        try {
            $this->searchService->runSearch($search);
        } catch (WikimediaBlockedException $e) {
            $this->markFailed($search);

            WikimediaBlockEvent::create([
                'car_search_id' => $search->id,
                'csv_import_id' => $search->csv_import_id,
                'status_code' => $e->statusCode,
                'retry_after_seconds' => $e->retryAfterSeconds,
                'response_excerpt' => $e->responseExcerpt,
                'occurred_at' => now(),
            ]);

            throw $e;
        } catch (Throwable $e) {
            $this->markFailed($search);

            throw $e;
        }
    }

    private function markFailed(CarSearch $search): void
    {
        // CarImageSearchService wraps runSearch in a DB transaction, so a
        // throw inside rolls back the status='running' update — the row is
        // back at 'pending' here. Force it to 'failed' explicitly.
        $search->forceFill(['status' => 'failed'])->save();
    }
}
```

- [ ] **Step 4: Run tests — verify they pass**

```bash
docker compose exec app php artisan test --filter=RunSearchQueryActionTest
```

Expected: 3/3 pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Search/RunSearchQueryAction.php tests/Feature/Services/Search/RunSearchQueryActionTest.php
git commit -m "$(cat <<'EOF'
feat(search): RunSearchQueryAction wraps runSearch with block logging

Single-query foreground entry point. On WikimediaBlockedException,
writes a wikimedia_block_events row (with car_search_id and
csv_import_id) and re-throws so bulk-run loops can halt. On any
failure, the query status is forced to 'failed'.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: BatchZipBuilder and BatchCsvExporter services

**Files:**
- Create: `app/Services/Downloads/BatchZipBuilder.php`
- Create: `app/Services/Downloads/BatchCsvExporter.php`
- Test: `tests/Feature/Services/Downloads/BatchZipBuilderTest.php`
- Test: `tests/Feature/Services/Downloads/BatchCsvExporterTest.php`

These two services share the dedup logic from `FilenameBuilder` so naming stays consistent between exports.

- [ ] **Step 1: Write the BatchCsvExporter test**

`tests/Feature/Services/Downloads/BatchCsvExporterTest.php`:

```php
<?php

namespace Tests\Feature\Services\Downloads;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use App\Services\Downloads\BatchCsvExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BatchCsvExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_selected_images_with_renamed_filenames_and_metadata(): void
    {
        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 'test.csv',
            'total_rows' => 1,
            'unique_combos' => 1,
            'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        $search = CarSearch::create([
            'make' => 'Toyota',
            'model' => 'RAV4',
            'from_year' => 1997,
            'to_year' => 1997,
            'color' => null,
            'transmission' => 'Automatic 4-spd',
            'transparent_background' => false,
            'images_per_year' => 5,
            'status' => 'completed',
            'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]);

        $img1 = CarImage::create([
            'car_search_id' => $search->id,
            'provider' => 'wikimedia',
            'provider_image_id' => 'A',
            'make' => 'Toyota',
            'model' => 'RAV4',
            'year' => 1997,
            'title' => 'A',
            'description' => null,
            'source_url' => 'https://example.com/a.jpg',
            'thumbnail_url' => 'https://example.com/a-thumb.jpg',
            'width' => 800,
            'height' => 600,
            'license' => null,
            'attribution' => null,
            'download_status' => 'not_downloaded',
            'metadata' => null,
        ]);

        $img2 = CarImage::create([
            'car_search_id' => $search->id,
            'provider' => 'wikimedia',
            'provider_image_id' => 'B',
            'make' => 'Toyota',
            'model' => 'RAV4',
            'year' => 1997,
            'title' => 'B',
            'description' => null,
            'source_url' => 'https://example.com/b.png',
            'thumbnail_url' => 'https://example.com/b-thumb.png',
            'width' => 800,
            'height' => 600,
            'license' => null,
            'attribution' => null,
            'download_status' => 'not_downloaded',
            'metadata' => null,
        ]);

        $exporter = app(BatchCsvExporter::class);
        $rows = $exporter->buildRows(collect([$img1, $img2]));

        $this->assertCount(3, $rows); // header + 2 data rows
        $this->assertSame(
            ['Year', 'Make', 'Model', 'Transmission', 'Filename', 'SourceUrl', 'SearchId', 'ImageId'],
            $rows[0]
        );

        $this->assertSame('1997', $rows[1][0]);
        $this->assertSame('Toyota', $rows[1][1]);
        $this->assertSame('RAV4', $rows[1][2]);
        $this->assertSame('Automatic 4-spd', $rows[1][3]);
        $this->assertSame('1997 Toyota RAV4.jpg', $rows[1][4]);
        $this->assertSame('https://example.com/a.jpg', $rows[1][5]);

        $this->assertSame('1997 Toyota RAV4 2.png', $rows[2][4]);
    }
}
```

- [ ] **Step 2: Write the BatchZipBuilder test**

`tests/Feature/Services/Downloads/BatchZipBuilderTest.php`:

```php
<?php

namespace Tests\Feature\Services\Downloads;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use App\Services\Downloads\BatchZipBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class BatchZipBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_zip_with_renamed_entries_and_duplicate_suffix(): void
    {
        Http::fake([
            'https://example.com/a.jpg' => Http::response('AAAA', 200),
            'https://example.com/b.png' => Http::response('BBBB', 200),
        ]);

        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 'test.csv',
            'total_rows' => 1,
            'unique_combos' => 1,
            'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        $search = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 1997, 'to_year' => 1997,
            'color' => null, 'transmission' => null,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]);

        $img1 = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'A', 'make' => 'Toyota', 'model' => 'RAV4',
            'year' => 1997, 'title' => 'A', 'source_url' => 'https://example.com/a.jpg',
            'thumbnail_url' => 'https://example.com/a.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);

        $img2 = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'B', 'make' => 'Toyota', 'model' => 'RAV4',
            'year' => 1997, 'title' => 'B', 'source_url' => 'https://example.com/b.png',
            'thumbnail_url' => 'https://example.com/b.png',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'zip');

        $builder = app(BatchZipBuilder::class);
        $builder->buildToFile(collect([$img1, $img2]), $tmpFile);

        $zip = new ZipArchive();
        $opened = $zip->open($tmpFile);
        $this->assertTrue($opened === true, 'ZIP should open successfully');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);

        $this->assertSame(['1997 Toyota RAV4 2.png', '1997 Toyota RAV4.jpg'], $names);

        $this->assertSame('AAAA', $zip->getFromName('1997 Toyota RAV4.jpg'));
        $this->assertSame('BBBB', $zip->getFromName('1997 Toyota RAV4 2.png'));

        $zip->close();
        unlink($tmpFile);
    }
}
```

- [ ] **Step 3: Run tests — verify they fail**

```bash
docker compose exec app php artisan test --filter="BatchZipBuilderTest|BatchCsvExporterTest"
```

Expected: both fail with class-not-found.

- [ ] **Step 4: Implement `BatchCsvExporter`**

```php
<?php

namespace App\Services\Downloads;

use App\Models\CarImage;
use Illuminate\Support\Collection;

class BatchCsvExporter
{
    public function __construct(
        protected FilenameBuilder $filenames,
    ) {
    }

    public const HEADER = ['Year', 'Make', 'Model', 'Transmission', 'Filename', 'SourceUrl', 'SearchId', 'ImageId'];

    /**
     * Return an array of CSV rows: [header, row1, row2, ...].
     * Filenames are deduped across the collection so they match the ZIP output.
     */
    public function buildRows(Collection $images): array
    {
        $rows = [self::HEADER];
        $usedNames = [];

        foreach ($images as $image) {
            /** @var CarImage $image */
            $search = $image->carSearch;
            $extension = $this->extensionFromUrl($image->source_url);

            $filename = $this->filenames->buildUnique(
                (int) $image->year,
                (string) $image->make,
                (string) $image->model,
                $extension,
                $usedNames,
            );

            $rows[] = [
                (string) $image->year,
                (string) $image->make,
                (string) $image->model,
                (string) ($search?->transmission ?? ''),
                $filename,
                (string) $image->source_url,
                (string) $image->car_search_id,
                (string) $image->id,
            ];
        }

        return $rows;
    }

    /**
     * Stream CSV rows to a PHP stream resource (for StreamedResponse).
     */
    public function streamTo($handle, Collection $images): void
    {
        foreach ($this->buildRows($images) as $row) {
            fputcsv($handle, $row);
        }
    }

    private function extensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        return pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg';
    }
}
```

- [ ] **Step 5: Implement `BatchZipBuilder`**

```php
<?php

namespace App\Services\Downloads;

use App\Models\CarImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class BatchZipBuilder
{
    public function __construct(
        protected FilenameBuilder $filenames,
    ) {
    }

    /**
     * Build a ZIP at $targetPath containing each image renamed.
     * Image binaries are fetched from CarImage::source_url via HTTP.
     */
    public function buildToFile(Collection $images, string $targetPath): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException("Cannot open ZIP at {$targetPath}: code {$opened}");
        }

        $usedNames = [];

        foreach ($images as $image) {
            /** @var CarImage $image */
            $extension = $this->extensionFromUrl($image->source_url);

            $filename = $this->filenames->buildUnique(
                (int) $image->year,
                (string) $image->make,
                (string) $image->model,
                $extension,
                $usedNames,
            );

            $response = Http::timeout(30)->get($image->source_url);
            if (! $response->successful()) {
                // Skip individual fetch failures rather than aborting the whole ZIP.
                continue;
            }

            $zip->addFromString($filename, $response->body());
        }

        $zip->close();
    }

    private function extensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        return pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg';
    }
}
```

- [ ] **Step 6: Run tests — verify they pass**

```bash
docker compose exec app php artisan test --filter="BatchZipBuilderTest|BatchCsvExporterTest"
```

Expected: 2/2 pass.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Downloads/BatchZipBuilder.php app/Services/Downloads/BatchCsvExporter.php tests/Feature/Services/Downloads/
git commit -m "$(cat <<'EOF'
feat(downloads): BatchZipBuilder and BatchCsvExporter

ZIP entries and CSV Filename column are produced by the same
FilenameBuilder, so the two exports stay in sync. Naming includes
deterministic duplicate-suffix counters within each export.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: BulkDownloadController and routes

**Files:**
- Create: `app/Http/Controllers/CarImageBulkDownloadController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/BulkDownloadTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/BulkDownloadTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BulkDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function setupBatchWithTwoImages(): array
    {
        Http::fake([
            'https://example.com/a.jpg' => Http::response('AAAA', 200),
            'https://example.com/b.jpg' => Http::response('BBBB', 200),
        ]);

        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 'test.csv',
            'total_rows' => 1, 'unique_combos' => 1, 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        $search = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]);

        $img1 = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'A', 'make' => 'Toyota', 'model' => 'RAV4',
            'year' => 1997, 'title' => 'A', 'source_url' => 'https://example.com/a.jpg',
            'thumbnail_url' => 'https://example.com/a.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);

        $img2 = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'B', 'make' => 'Toyota', 'model' => 'RAV4',
            'year' => 1997, 'title' => 'B', 'source_url' => 'https://example.com/b.jpg',
            'thumbnail_url' => 'https://example.com/b.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);

        return [$user, [$img1, $img2]];
    }

    public function test_zip_endpoint_returns_zip_with_renamed_files(): void
    {
        [$user, $images] = $this->setupBatchWithTwoImages();

        $response = $this->actingAs($user)->post('/batch-downloads/zip', [
            'image_ids' => [$images[0]->id, $images[1]->id],
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.zip', $disposition);
    }

    public function test_csv_endpoint_returns_manifest_with_renamed_filenames(): void
    {
        [$user, $images] = $this->setupBatchWithTwoImages();

        $response = $this->actingAs($user)->post('/batch-downloads/csv', [
            'image_ids' => [$images[0]->id, $images[1]->id],
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $response->streamedContent();
        $this->assertStringContainsString('Year,Make,Model,Transmission,Filename,SourceUrl,SearchId,ImageId', $body);
        $this->assertStringContainsString('1997 Toyota RAV4.jpg', $body);
        $this->assertStringContainsString('1997 Toyota RAV4 2.jpg', $body);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->postJson('/batch-downloads/zip', ['image_ids' => [1]])->assertStatus(401);
        $this->postJson('/batch-downloads/csv', ['image_ids' => [1]])->assertStatus(401);
    }

    public function test_endpoints_reject_empty_selection(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/batch-downloads/zip', ['image_ids' => []])->assertStatus(422);
        $this->actingAs($user)->postJson('/batch-downloads/csv', ['image_ids' => []])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run tests — verify they fail**

```bash
docker compose exec app php artisan test --filter=BulkDownloadTest
```

Expected: all fail with 404 / route not defined.

- [ ] **Step 3: Implement the controller**

`app/Http/Controllers/CarImageBulkDownloadController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CarImage;
use App\Services\Downloads\BatchCsvExporter;
use App\Services\Downloads\BatchZipBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CarImageBulkDownloadController extends Controller
{
    public function zip(
        Request $request,
        BatchZipBuilder $builder,
    ): BinaryFileResponse {
        $data = $request->validate([
            'image_ids' => ['required', 'array', 'min:1'],
            'image_ids.*' => ['integer'],
        ]);

        $images = CarImage::with('carSearch')
            ->whereIn('id', $data['image_ids'])
            ->orderBy('car_search_id')
            ->orderBy('id')
            ->get();

        $tmpPath = tempnam(sys_get_temp_dir(), 'cars-batch-');
        $builder->buildToFile($images, $tmpPath);

        $filename = 'cars-batch-' . now()->format('Ymd-His') . '.zip';

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function csv(
        Request $request,
        BatchCsvExporter $exporter,
    ): StreamedResponse {
        $data = $request->validate([
            'image_ids' => ['required', 'array', 'min:1'],
            'image_ids.*' => ['integer'],
        ]);

        $images = CarImage::with('carSearch')
            ->whereIn('id', $data['image_ids'])
            ->orderBy('car_search_id')
            ->orderBy('id')
            ->get();

        $filename = 'cars-batch-' . now()->format('Ymd-His') . '.csv';

        return response()->stream(
            function () use ($exporter, $images) {
                $handle = fopen('php://output', 'w');
                $exporter->streamTo($handle, $images);
                fclose($handle);
            },
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ],
        );
    }
}
```

- [ ] **Step 4: Add routes**

Open `routes/web.php` and add (inside the existing file, alongside the existing `Route::get('/car-images/...')` route):

```php
use App\Http\Controllers\CarImageBulkDownloadController;

Route::middleware(['auth'])->group(function () {
    Route::post('/batch-downloads/zip', [CarImageBulkDownloadController::class, 'zip'])
        ->name('batch-downloads.zip');

    Route::post('/batch-downloads/csv', [CarImageBulkDownloadController::class, 'csv'])
        ->name('batch-downloads.csv');
});
```

- [ ] **Step 5: Run tests — verify they pass**

```bash
docker compose exec app php artisan test --filter=BulkDownloadTest
```

Expected: 4/4 pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/CarImageBulkDownloadController.php routes/web.php tests/Feature/BulkDownloadTest.php
git commit -m "$(cat <<'EOF'
feat(downloads): batch ZIP + CSV download endpoints

POST /batch-downloads/zip and /batch-downloads/csv accept a list of
image_ids and stream the artifacts using BatchZipBuilder and
BatchCsvExporter. Auth required, empty selections rejected with 422.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Filament Page 1 — CsvImportResource

**Files:**
- Create: `app/Filament/Resources/CsvImportResource.php`
- Create: `app/Filament/Resources/CsvImportResource/Pages/CreateCsvImport.php`
- Create: `app/Filament/Resources/CsvImportResource/Pages/ListCsvImports.php`
- Create: `app/Filament/Resources/CsvImportResource/Pages/ViewCsvImport.php`
- Test: `tests/Feature/Filament/CsvImportResourceTest.php`

- [ ] **Step 1: Inspect the existing `CarSearchResource` for conventions**

```bash
docker compose exec app cat app/Filament/Resources/CarSearchResource.php
```

Identify: the `protected static UnitEnum|string|null $navigationGroup` value (it's `'Cars'`) and the import statements used. Reuse the same patterns.

- [ ] **Step 2: Create `CsvImportResource`**

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CsvImportResource\Pages;
use App\Models\CsvImport;
use BackedEnum;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class CsvImportResource extends Resource
{
    protected static ?string $model = CsvImport::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static UnitEnum|string|null $navigationGroup = 'Cars';

    protected static ?string $navigationLabel = 'Upload CSV';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        // The upload form is implemented in CreateCsvImport (custom page).
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('File')
                    ->searchable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('unique_combos')
                    ->label('Queries')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('duplicates_skipped')
                    ->label('Dupes skipped')
                    ->numeric(),
                Tables\Columns\TextColumn::make('importer.name')
                    ->label('Imported by'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('This will also delete all imported queries and their images.'),
            ])
            ->toolbarActions([
                Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCsvImports::route('/'),
            'create' => Pages\CreateCsvImport::route('/create'),
            'view' => Pages\ViewCsvImport::route('/{record}'),
        ];
    }
}
```

- [ ] **Step 3: Create `ListCsvImports` page**

```php
<?php

namespace App\Filament\Resources\CsvImportResource\Pages;

use App\Filament\Resources\CsvImportResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCsvImports extends ListRecords
{
    protected static string $resource = CsvImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Upload CSV'),
        ];
    }
}
```

- [ ] **Step 4: Create `CreateCsvImport` page**

This is the upload form; it overrides the default Create to use a custom file-upload schema and to call `CsvQueryImporter` directly.

```php
<?php

namespace App\Filament\Resources\CsvImportResource\Pages;

use App\Filament\Resources\CsvImportResource;
use App\Services\Imports\CsvImportException;
use App\Services\Imports\CsvQueryImporter;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;

class CreateCsvImport extends CreateRecord
{
    protected static string $resource = CsvImportResource::class;

    protected static ?string $title = 'Upload CSV';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('csv_file')
                ->label('CSV file (Make,Model,Year,Transmission)')
                ->acceptedFileTypes(['text/csv', 'application/csv', 'application/vnd.ms-excel'])
                ->maxSize(5 * 1024) // 5 MB
                ->storeFiles(false)
                ->required(),
        ]);
    }

    protected function handleRecordCreation(array $data): \App\Models\CsvImport
    {
        /** @var UploadedFile $file */
        $file = $data['csv_file'];

        try {
            $result = app(CsvQueryImporter::class)->import($file, auth()->user());
        } catch (CsvImportException $e) {
            Notification::make()
                ->title('Import rejected')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }

        Notification::make()
            ->title('CSV imported')
            ->body("{$result->csvImport->unique_combos} queries imported, {$result->csvImport->duplicates_skipped} duplicates skipped, {$result->skippedInvalidRows} invalid rows skipped.")
            ->success()
            ->send();

        return $result->csvImport;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
```

- [ ] **Step 5: Create `ViewCsvImport` page**

```php
<?php

namespace App\Filament\Resources\CsvImportResource\Pages;

use App\Filament\Resources\CsvImportResource;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewCsvImport extends ViewRecord
{
    protected static string $resource = CsvImportResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('original_filename')->label('File'),
            TextEntry::make('total_rows')->label('Total rows'),
            TextEntry::make('unique_combos')->label('Unique queries imported'),
            TextEntry::make('duplicates_skipped'),
            TextEntry::make('importer.name')->label('Imported by'),
            TextEntry::make('created_at')->dateTime(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('goToQueries')
                ->label('Go to Search Queries')
                ->url(fn () => route('filament.admin.resources.search-queries.index', ['tableFilters' => ['csv_import_id' => ['value' => $this->record->id]]]))
                ->icon('heroicon-o-arrow-right'),
        ];
    }
}
```

- [ ] **Step 6: Write a Filament smoke test**

`tests/Feature/Filament/CsvImportResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CsvImportResource\Pages\ListCsvImports;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CsvImportResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_view_list_page(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ListCsvImports::class)
            ->assertSuccessful();
    }

    public function test_list_page_shows_imports(): void
    {
        $user = User::factory()->create();

        $csvImport = CsvImport::create([
            'original_filename' => 'sample.csv',
            'total_rows' => 100,
            'unique_combos' => 50,
            'duplicates_skipped' => 50,
            'imported_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(ListCsvImports::class)
            ->assertCanSeeTableRecords([$csvImport]);
    }
}
```

- [ ] **Step 7: Run tests and verify in browser**

```bash
docker compose exec app php artisan test --filter=CsvImportResourceTest
```

Expected: 2/2 pass.

Manually: visit `http://cars-images-api.test/admin/csv-imports` — page loads, shows empty state. Click "Upload CSV", drag in the sample CSV (`sample/COMPLETE LIST = ALL VEHICLES - sorted - 4 columns.csv`) — should be rejected with "exceeds 1000" message. Create a smaller test CSV (50 rows) and verify success.

- [ ] **Step 8: Commit**

```bash
git add app/Filament/Resources/CsvImportResource.php app/Filament/Resources/CsvImportResource/ tests/Feature/Filament/CsvImportResourceTest.php
git commit -m "$(cat <<'EOF'
feat(filament): CsvImportResource (Upload CSV page)

Page 1 of the three-page CSV workflow. Uploads CSV via FileUpload,
delegates parsing to CsvQueryImporter, redirects to the View page
with toast counts. List view shows import history.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Filament Page 2 — SearchQueryResource

**Files:**
- Create: `app/Filament/Resources/SearchQueryResource.php`
- Create: `app/Filament/Resources/SearchQueryResource/Pages/ListSearchQueries.php`
- Test: `tests/Feature/Filament/SearchQueryResourceTest.php`

- [ ] **Step 1: Create `SearchQueryResource`**

This is a view onto `CarSearch` scoped to rows with `csv_import_id` set. Key features:
- List page with auto-poll every 3s
- Filters: by CSV import, status, year range, make
- Per-row `Run` action (single)
- Bulk `Run Selected` action — chunked, time-capped, stops on block

```php
<?php

namespace App\Filament\Resources;

use App\Exceptions\WikimediaBlockedException;
use App\Filament\Resources\SearchQueryResource\Pages;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Services\Search\RunSearchQueryAction;
use BackedEnum;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use UnitEnum;

class SearchQueryResource extends Resource
{
    protected static ?string $model = CarSearch::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-queue-list';

    protected static UnitEnum|string|null $navigationGroup = 'Cars';

    protected static ?string $navigationLabel = 'Search Queries';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'search-queries';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('csv_import_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('3s')
            ->columns([
                Tables\Columns\TextColumn::make('from_year')->label('Year')->sortable(),
                Tables\Columns\TextColumn::make('make')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('model')->searchable(),
                Tables\Columns\TextColumn::make('csvImport.original_filename')->label('Source CSV')->limit(30),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray' => 'pending',
                        'warning' => 'running',
                        'success' => 'completed',
                        'danger' => 'failed',
                    ]),
                Tables\Columns\TextColumn::make('images_count')
                    ->label('Images')
                    ->counts('images'),
            ])
            ->defaultSort('id', 'asc')
            ->filters([
                SelectFilter::make('csv_import_id')
                    ->label('CSV Import')
                    ->options(fn () => CsvImport::pluck('original_filename', 'id')->all()),
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'running' => 'Running',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                ]),
            ])
            ->recordActions([
                Actions\Action::make('run')
                    ->label('Run')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->visible(fn (CarSearch $record) => in_array($record->status, ['pending', 'failed'], true))
                    ->action(function (CarSearch $record) {
                        try {
                            app(RunSearchQueryAction::class)->execute($record);

                            Notification::make()
                                ->title('Query complete')
                                ->body("{$record->from_year} {$record->make} {$record->model} — done.")
                                ->success()
                                ->send();
                        } catch (WikimediaBlockedException $e) {
                            Notification::make()
                                ->title('Wikimedia blocked')
                                ->body("HTTP {$e->statusCode} — see Block Events. Retry-After: " . ($e->retryAfterSeconds ?? 'n/a') . 's')
                                ->danger()
                                ->persistent()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Query failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Actions\Action::make('viewResults')
                    ->label('View results')
                    ->icon('heroicon-o-photo')
                    ->color('success')
                    ->visible(fn (CarSearch $record) => $record->status === 'completed')
                    ->url(fn (CarSearch $record) => route('filament.admin.pages.results', ['searchId' => $record->id])),
            ])
            ->toolbarActions([
                Actions\BulkAction::make('runSelected')
                    ->label('Run Selected')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Runs up to ' . config('cars-images.bulk_run_max_queries_per_chunk') . ' queries OR ' . config('cars-images.bulk_run_max_seconds_per_chunk') . ' seconds, whichever first. Click again to continue.')
                    ->action(function ($records) {
                        $maxQueries = (int) config('cars-images.bulk_run_max_queries_per_chunk');
                        $maxSeconds = (int) config('cars-images.bulk_run_max_seconds_per_chunk');
                        $sleepSeconds = (int) config('cars-images.bulk_run_sleep_seconds_between_queries');

                        $start = microtime(true);
                        $processed = 0;
                        $blocked = false;
                        $blockMessage = null;

                        foreach ($records as $record) {
                            if ($processed >= $maxQueries) {
                                break;
                            }
                            if (microtime(true) - $start >= $maxSeconds) {
                                break;
                            }
                            if (! in_array($record->status, ['pending', 'failed'], true)) {
                                continue;
                            }

                            try {
                                app(RunSearchQueryAction::class)->execute($record);
                            } catch (WikimediaBlockedException $e) {
                                $blocked = true;
                                $blockMessage = "HTTP {$e->statusCode} after {$processed} queries. Retry-After: " . ($e->retryAfterSeconds ?? 'n/a') . 's';
                                break;
                            } catch (Throwable $e) {
                                // continue past individual non-block failures
                            }

                            $processed++;
                            if ($sleepSeconds > 0) {
                                sleep($sleepSeconds);
                            }
                        }

                        if ($blocked) {
                            Notification::make()
                                ->title('Bulk run paused — Wikimedia blocked')
                                ->body($blockMessage)
                                ->danger()
                                ->persistent()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Bulk run finished')
                                ->body("Processed {$processed} queries this chunk. Click 'Run Selected' again to continue.")
                                ->success()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSearchQueries::route('/'),
        ];
    }
}
```

- [ ] **Step 2: Create `ListSearchQueries` page**

```php
<?php

namespace App\Filament\Resources\SearchQueryResource\Pages;

use App\Filament\Resources\SearchQueryResource;
use Filament\Resources\Pages\ListRecords;

class ListSearchQueries extends ListRecords
{
    protected static string $resource = SearchQueryResource::class;
}
```

- [ ] **Step 3: Smoke test**

`tests/Feature/Filament/SearchQueryResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\SearchQueryResource\Pages\ListSearchQueries;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SearchQueryResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_page_only_shows_csv_imported_searches(): void
    {
        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 'sample.csv',
            'total_rows' => 1, 'unique_combos' => 1, 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        $importedSearch = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'pending', 'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]);

        $adHocSearch = CarSearch::create([
            'make' => 'Honda', 'model' => 'Civic',
            'from_year' => 2018, 'to_year' => 2022,
            'transparent_background' => false, 'images_per_year' => 10,
            'status' => 'pending', 'requested_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(ListSearchQueries::class)
            ->assertCanSeeTableRecords([$importedSearch])
            ->assertCanNotSeeTableRecords([$adHocSearch]);
    }

    public function test_run_action_marks_search_completed_on_success(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['search' => []]], 200),
        ]);

        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 'sample.csv',
            'total_rows' => 1, 'unique_combos' => 1, 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);
        $search = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'pending', 'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]);

        Livewire::actingAs($user)
            ->test(ListSearchQueries::class)
            ->callTableAction('run', $search)
            ->assertNotified();

        $this->assertSame('completed', $search->fresh()->status);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
docker compose exec app php artisan test --filter=SearchQueryResourceTest
```

Expected: 2/2 pass.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/SearchQueryResource.php app/Filament/Resources/SearchQueryResource/ tests/Feature/Filament/SearchQueryResourceTest.php
git commit -m "$(cat <<'EOF'
feat(filament): SearchQueryResource (Run + Bulk Run with loader)

Page 2 of the CSV workflow. Lists csv-imported searches with status
badges, auto-polls every 3s, and offers per-row [Run] and bulk
[Run Selected] actions. Bulk caps at 50 queries or 50 seconds; stops
on WikimediaBlockedException with a persistent error toast.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: Filament Page 3 — Results page

**Files:**
- Create: `app/Filament/Pages/Results.php`
- Create: `resources/views/filament/pages/results.blade.php`
- Test: `tests/Feature/Filament/ResultsPageTest.php`

The Results page is a custom Filament Page (not a Resource) because it needs a custom layout (grid + sticky action bar) over `CarImage` rows scoped to CSV-imported searches.

- [ ] **Step 1: Create the page class**

```php
<?php

namespace App\Filament\Pages;

use App\Models\CarImage;
use BackedEnum;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class Results extends Page implements HasTable
{
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-photo';

    protected static UnitEnum|string|null $navigationGroup = 'Cars';

    protected static ?string $navigationLabel = 'Results';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.results';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                CarImage::query()
                    ->whereHas('carSearch', fn (Builder $q) => $q->whereNotNull('csv_import_id'))
                    ->with('carSearch.csvImport')
            )
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail_url')
                    ->label('Thumbnail')
                    ->size(120),
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Name')
                    ->state(fn (CarImage $record) => "{$record->year} {$record->make} {$record->model}")
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function ($q) use ($search) {
                            $q->where('make', 'like', "%{$search}%")
                              ->orWhere('model', 'like', "%{$search}%")
                              ->orWhere('year', 'like', "%{$search}%");
                        });
                    }),
                Tables\Columns\TextColumn::make('carSearch.csvImport.original_filename')
                    ->label('Source CSV')
                    ->limit(30),
                Tables\Columns\TextColumn::make('year')->sortable(),
                Tables\Columns\TextColumn::make('make')->sortable(),
                Tables\Columns\TextColumn::make('model'),
            ])
            ->filters([
                SelectFilter::make('csv_import_id')
                    ->label('CSV Import')
                    ->relationship('carSearch.csvImport', 'original_filename'),
            ])
            ->recordActions([
                Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->url(fn (CarImage $record) => $record->source_url, true),
                Actions\Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (CarImage $record) {
                        // Single-image download via the bulk endpoint with one ID.
                        // Returning a download response from a Filament action requires
                        // a redirect or JS dispatch. Simplest: redirect to the existing
                        // /car-images/{carImage}/download route.
                        return redirect()->route('car-images.download', ['carImage' => $record->id]);
                    }),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkAction::make('downloadZip')
                    ->label('Download Selected as ZIP')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->action(function ($records) {
                        $ids = $records->pluck('id')->all();

                        // Build form-post URL that the browser will follow as a download.
                        // Filament bulk actions can return a redirect; we render a JS
                        // POST submitter via Filament notification with link approach.
                        $url = route('batch-downloads.zip');

                        // Easiest UX: emit a Livewire event that triggers a hidden form POST.
                        $this->dispatch('post-download', url: $url, ids: $ids);

                        Notification::make()
                            ->title('Preparing ZIP…')
                            ->success()
                            ->send();
                    }),
                Actions\BulkAction::make('exportCsv')
                    ->label('Export Selected as CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(function ($records) {
                        $ids = $records->pluck('id')->all();
                        $url = route('batch-downloads.csv');

                        $this->dispatch('post-download', url: $url, ids: $ids);

                        Notification::make()
                            ->title('Preparing CSV…')
                            ->success()
                            ->send();
                    }),
                Actions\DeleteBulkAction::make(),
            ])
            ->paginated([24, 48, 96]);
    }

    public static function getRouteName(string $panel = 'admin'): string
    {
        return 'filament.admin.pages.results';
    }
}
```

- [ ] **Step 2: Create the Blade view**

`resources/views/filament/pages/results.blade.php`:

```blade
<x-filament-panels::page>
    {{ $this->table }}

    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('post-download', (event) => {
                const data = Array.isArray(event) ? event[0] : event;
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = data.url;
                form.style.display = 'none';

                const tokenInput = document.createElement('input');
                tokenInput.type = 'hidden';
                tokenInput.name = '_token';
                tokenInput.value = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                form.appendChild(tokenInput);

                data.ids.forEach((id) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'image_ids[]';
                    input.value = id;
                    form.appendChild(input);
                });

                document.body.appendChild(form);
                form.submit();
                setTimeout(() => form.remove(), 5000);
            });
        });
    </script>
</x-filament-panels::page>
```

- [ ] **Step 3: Verify the CSRF meta tag exists in the admin layout**

```bash
docker compose exec app grep -r "csrf-token" resources/views/ vendor/filament/filament/resources/views/ 2>/dev/null | head -3
```

Filament includes the CSRF meta tag in the admin panel by default. If the grep returns nothing, add it to a published layout — but the default Filament panel already includes it.

- [ ] **Step 4: Smoke test**

`tests/Feature/Filament/ResultsPageTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Results;
use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ResultsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_shows_images_from_csv_imported_searches(): void
    {
        $user = User::factory()->create();

        $csvImport = CsvImport::create([
            'original_filename' => 'sample.csv',
            'total_rows' => 1, 'unique_combos' => 1, 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        $importedSearch = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]);
        $adHocSearch = CarSearch::create([
            'make' => 'Honda', 'model' => 'Civic',
            'from_year' => 2018, 'to_year' => 2022,
            'transparent_background' => false, 'images_per_year' => 10,
            'status' => 'completed', 'requested_by' => $user->id,
        ]);

        $importedImage = CarImage::create([
            'car_search_id' => $importedSearch->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'A', 'make' => 'Toyota', 'model' => 'RAV4',
            'year' => 1997, 'title' => 'A', 'source_url' => 'https://example.com/a.jpg',
            'thumbnail_url' => 'https://example.com/a.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);
        $adHocImage = CarImage::create([
            'car_search_id' => $adHocSearch->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'B', 'make' => 'Honda', 'model' => 'Civic',
            'year' => 2020, 'title' => 'B', 'source_url' => 'https://example.com/b.jpg',
            'thumbnail_url' => 'https://example.com/b.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);

        Livewire::actingAs($user)
            ->test(Results::class)
            ->assertCanSeeTableRecords([$importedImage])
            ->assertCanNotSeeTableRecords([$adHocImage]);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
docker compose exec app php artisan test --filter=ResultsPageTest
```

Expected: 1/1 pass.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/Results.php resources/views/filament/pages/results.blade.php tests/Feature/Filament/ResultsPageTest.php
git commit -m "$(cat <<'EOF'
feat(filament): Results page with ZIP and CSV bulk download

Page 3 of the CSV workflow. Grid of csv-imported car images with per-
image download (redirects to existing single-image route), bulk ZIP
download and CSV manifest export. Bulk actions POST image_ids[] to
/batch-downloads/{zip,csv} via a Livewire-dispatched form submit.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: README update

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add a "CSV Bulk Search" section**

Open `README.md` and add a new top-level section immediately after `## Usage` (around line 210) and before `### Running a car image search`:

```markdown
### CSV Bulk Search and Download

For bulk harvesting, use the three-page CSV workflow under the **Cars** navigation group:

1. **Upload CSV** (`/admin/csv-imports/create`) — drop in a CSV with columns `Make, Model, Year, Transmission`. Rows are deduplicated by `(Year, Make, Model)`. Uploads with more than `CSV_IMPORT_MAX_COMBOS` unique combos (default 1,000) are rejected; split the CSV externally first.
2. **Search Queries** (`/admin/search-queries`) — review the imported queries. Click `[Run]` per-row, or select multiple and click `[Run Selected]`. The bulk run caps at 50 queries or 50 seconds per click — click again to continue.
3. **Results** (`/admin/results`) — browse images from completed queries. Select images and use `[Download Selected as ZIP]` (renamed files inside) or `[Export Selected as CSV]` (manifest).

**Filename format:** `"YEAR MAKE MODEL.ext"` — e.g. `1997 Toyota RAV4.jpg`. Duplicates within an export get a numeric suffix: `1997 Toyota RAV4.jpg`, `1997 Toyota RAV4 2.jpg`.

**Wikimedia etiquette is on by default:** honest User-Agent with contact info, `maxlag=5`, 24h cache, 1-second throttle between bulk queries, exponential backoff on transient errors. Any 429/403/503 response auto-pauses the bulk loop and writes a `wikimedia_block_events` row.

```

- [ ] **Step 2: Verify**

```bash
grep -n "CSV Bulk Search" README.md
```

Expected: one line referencing the new section.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "$(cat <<'EOF'
docs(readme): add CSV Bulk Search and Download usage section

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: End-to-end acceptance verification

**Files:** none (verification only).

This task runs the spec's 12 acceptance criteria as a single end-to-end check. No new files. If any step fails, treat it as a bug to fix before considering the work done.

- [ ] **Step 1: Full test suite**

```bash
docker compose exec app php artisan test
```

Expected: all green.

- [ ] **Step 2: Create a small test CSV**

```bash
cat > /tmp/test-cars.csv <<EOF
Make,Model,Year,Transmission
Toyota,RAV4,1997,Automatic 4-spd
Toyota,RAV4,1997,Manual 5-spd
Toyota,Camry,1998,Manual 5-spd
Mitsubishi,Mirage,2015,Automatic
EOF
```

Copy it into the container or use it from the host:

```bash
docker compose cp /tmp/test-cars.csv app:/tmp/test-cars.csv
```

- [ ] **Step 3: Acceptance criteria — manual UI verification**

Open `http://cars-images-api.test/admin` and log in. Then for each criterion below, perform the action and confirm the expected outcome.

**Criterion 1 (CSV upload deduplicates):**
- Go to **Upload CSV** → upload `/tmp/test-cars.csv` → confirm toast says "3 queries imported, 1 duplicates skipped".

**Criterion 2 (oversized CSV rejected):**
- In `.env` temporarily set `CSV_IMPORT_MAX_COMBOS=2` and `docker compose exec app php artisan config:clear` → upload the same CSV → confirm rejection with "exceeds 2" message.
- Restore `CSV_IMPORT_MAX_COMBOS=1000` and clear config again.

**Criterion 3 (queries appear filtered):**
- Go to **Search Queries**, filter by the new CSV → confirm 3 rows appear (Toyota RAV4 1997, Toyota Camry 1998, Mitsubishi Mirage 2015) all status `pending`.

**Criterion 4 (single Run):**
- Click `[Run]` on Toyota RAV4 1997 → confirm spinner shows, then status becomes `completed`, image count is > 0 (assuming Wikimedia returns results).

**Criterion 5 (bulk Run with cap):**
- Select the remaining two pending rows → `[Run Selected]` → confirm progress modal → both rows become `completed` (or `failed` if no results).

**Criterion 6 (block handling — automated test only):**
- Already covered by `WikimediaBlockHandlingTest` and `RunSearchQueryActionTest`.

**Criterion 7 (View Results link):**
- On a completed query, click `[View results]` → confirm Results page opens with images for that query.

**Criterion 8 (single image download renamed):**
- On the Results page, click `[Download]` on an image → confirm the saved file is named like `1997 Toyota RAV4.jpg`.

**Criterion 9 (ZIP download with duplicate suffix):**
- Select multiple images from the same `(Year, Make, Model)` → click `[Download Selected as ZIP]` → unzip and confirm the entries are `1997 Toyota RAV4.jpg`, `1997 Toyota RAV4 2.jpg`, etc.

**Criterion 10 (CSV manifest matches ZIP names):**
- Same selection → click `[Export Selected as CSV]` → open the CSV in a text editor → confirm the `Filename` column entries exactly match the ZIP entry names.

**Criterion 11 (existing single-search flow unaffected):**
- Go to **Car Image Searches** (the original resource) → create a new ad-hoc search → confirm it works as before (no `csv_import_id`, doesn't appear on the new Search Queries page).

**Criterion 12 (no queue/cron required):**
- Verify no new `schedule()` or cron line was added: `grep -rn "queue:work\|schedule:run" routes/ app/Console/ 2>/dev/null || echo "OK: no scheduler additions"`.

- [ ] **Step 4: Final commit if any fixes were needed**

If any verification step revealed an issue, fix and commit as a separate `fix(...)` commit. Do not amend.

If everything passed without changes, no commit is needed — the acceptance task is verification only.

---

## Self-Review

**Spec coverage check** — every spec section maps to at least one task:

| Spec section | Tasks |
|---|---|
| Database schema (csv_imports, wikimedia_block_events, csv_import_id FK) | Task 2 |
| Models (CsvImport, WikimediaBlockEvent, CarSearch updates) | Task 3 |
| Page 1 (Upload CSV / CsvImportResource) | Task 10 |
| Page 2 (Search Queries / SearchQueryResource) | Task 11 |
| Page 3 (Results) | Task 12 |
| Filename rules (FilenameBuilder) | Task 5 |
| Wikimedia etiquette (UA, maxlag, retries, cache, 24h TTL) | Tasks 1, 4 |
| Block-on-fail flow (`WikimediaBlockedException`, log event, mark failed) | Tasks 4, 7 |
| Bulk-run pacing (50 queries / 50 seconds / 1s throttle) | Tasks 1, 11 |
| Filament loaders (auto-poll 3s, action spinner, persistent toast on block) | Tasks 11, 12 |
| Single-image download with rename | Task 12 (via existing route) |
| ZIP download with rename + dedup | Tasks 8, 9, 12 |
| CSV manifest with matching filenames | Tasks 8, 9, 12 |
| Out-of-scope items (no queue/cron/AI) | All — none added |
| Acceptance criteria 1–12 | Task 14 |

No spec sections without a corresponding task.

**Placeholder scan** — no TBD/TODO/"add error handling"/"similar to" placeholders. Every code block is complete and copy-pasteable.

**Type and identifier consistency:**
- Status values used throughout: `pending`, `running`, `completed`, `failed` — consistent.
- Service names: `FilenameBuilder`, `CsvQueryImporter`, `RunSearchQueryAction`, `BatchZipBuilder`, `BatchCsvExporter` — consistent across tasks.
- Method names: `FilenameBuilder::build()` and `FilenameBuilder::buildUnique()` — consistent across Tasks 5, 8.
- Config keys: `cars-images.csv_import_max_combos`, `cars-images.csv_import_default_images_per_year`, `cars-images.bulk_run_max_queries_per_chunk`, `cars-images.bulk_run_max_seconds_per_chunk`, `cars-images.bulk_run_sleep_seconds_between_queries` — consistent across Tasks 1, 6, 11.
- Env keys: `CSV_IMPORT_MAX_COMBOS`, `WIKIMEDIA_MAXLAG`, `WIKIMEDIA_USER_AGENT` — consistent across Tasks 1, 4, 13.
- Route names: `batch-downloads.zip`, `batch-downloads.csv` — consistent Tasks 9, 12. Existing `car-images.download` reused for single-image (Task 12).
- Filament resource slug `search-queries` set in Task 11 and referenced in Task 10 (ViewCsvImport "Go to Queries" button URL).

No inconsistencies found.
