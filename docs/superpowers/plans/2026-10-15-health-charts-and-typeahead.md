# Health Charts and Make/Model Typeahead Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put the panel's two dashboard charts on the mobile Health screen, and
suggest makes and models in the search form, so that the mobile programme is
finished.

**Architecture:** A new `App\Services\Health\PipelineSeries` takes over the
daily counting from both chart widgets. The widgets now call it and put their
Chart.js keys and colours back on top. `GET /api/v1/health/series` returns the
service's output and `GET /api/v1/makes` returns the `car_makes` table, both
inside the usual `data` envelope. The spec's example payload for the series
leaves the envelope out; the plan follows the API's convention that every
endpoint except login wraps its payload in `data`. On the client, the charts are
plain `View`s built on a shared `DailyBars` primitive, and the typeahead is a
pure `suggest()` function plus a `Suggestions` list placed under each field.

**Tech Stack:** Laravel 13, Filament 5, Sanctum, PHPUnit; Expo 57, React Query 5,
Zod 4, NativeWind 4. **No new dependencies.**

**Spec:** [`docs/superpowers/specs/2026-09-15-health-charts-and-typeahead-design.md`](../specs/2026-09-15-health-charts-and-typeahead-design.md)

## Global Constraints

- Laravel tests run in the container, because PHP on the host has no
  `pdo_sqlite`:
  `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test`
- **`vendor/bin/pint --test` is a CI gate.** It runs on the host.
- Mobile commands run on the host from `mobile/`. **Never add a dependency to
  `mobile/package.json` or to the repository-root `package.json`.**
- **No chart library.** Charts are plain `View`s.
- **No colour in any API response.** Colour is presentation, and the app's
  comes from `mobile/src/theme/tokens.ts`.
- **Each widget's `series()` keeps its Chart.js shape exactly**: `labels`, plus
  `datasets[]` of `label` and `data`, plus colours. **`tests/Feature/Filament/Widgets/`
  must pass without being edited.**
- **The typeahead suggests and never constrains.** A make that isn't in the
  catalogue must submit exactly as it does today.
- **The panel's hard-coded fallback list is not copied.** `GET /makes`
  returns only what is in the table.
- Every endpoint except login wraps its payload as `{ "data": ... }`.
- **No model factories** except `UserFactory`. Build rows with
  `ApiTestCase::search()` and `ErrorEvent::create()`.
- `@testing-library/react-native` 13.3.3: `render()` is synchronous. **Never
  write `await render(...)`.**
- **Don't modify `mobile/jest.config.js`.**
- Test `QueryClient`s: `gcTime: 0` for queries, and set `mutations: { gcTime: 0 }`
  **separately**.
- `noUncheckedIndexedAccess` is on, so every array index read needs a
  fallback (`days[i] ?? ''`, `values[i] ?? 0`).
- **Write NativeWind classes out in full as string literals** (`'bg-success'`).
  Tailwind finds classes by scanning the source, so a class assembled at
  runtime (`` `bg-${kind}` ``) is never generated and silently does nothing.
- **Text uses the text tokens, never a series colour.** Labels, values and
  legend text use `text-text`, `text-text-secondary` or `text-text-muted`; only
  a swatch or a bar carries the series colour.
- **Regenerating the contract fixtures may only add files.** The only fixtures
  allowed to change are `health-series.json` (Task 2) and `makes.json`
  (Task 3), both new.

## Review Focus

1. **A suggestion tapped while the keyboard is up.** A React Native
   `ScrollView` defaults to `keyboardShouldPersistTaps="never"`, so the first
   tap only dismisses the keyboard and the user has to tap the suggestion
   again. Expected: one tap fills the field. Pinned in Task 8.
2. **A fortnight with no failures.** PHP encodes an empty array as `[]`, and a
   Zod record rejects a list. The whole series request would then fail, and
   both charts would disappear behind an error banner on the good days.
   Expected: *"No failures in the last 14 days."* Pinned in Task 2 (the server
   sends `{}`) and Task 4 (the client rejects `[]` and accepts `{}`).
3. **A failure kind the app doesn't know**, either added on the server later
   or a leftover row from a retired context. Expected: one more small chart
   titled with the raw key, not a contract failure that blanks both charts.
   Pinned in Task 1 (the service keeps it), Task 4 (the schema accepts it) and
   Task 6 (the chart labels it).
4. **The make catalogue can't be read** (403, offline, or an empty table).
   Expected: the search form submits as plain text and shows no error banner.
   Pinned in Task 8.
5. **The series request fails while the summary succeeds.** Expected: the
   counters stay, and the chart area shows the failure. Pinned in Task 7.

## File Structure

**New: Laravel**

| File | Responsibility |
|---|---|
| `app/Services/Health/PipelineSeries.php` | The daily counts for both charts, with no colour |
| `app/Http/Controllers/Api/V1/HealthSeriesController.php` | `GET /health/series`; casts an empty `series` to `{}` |
| `app/Http/Controllers/Api/V1/MakeController.php` | `GET /makes` |
| `tests/Feature/Services/Health/PipelineSeriesTest.php` | Windows, buckets, context order |
| `tests/Feature/Api/MakesTest.php` | Ability, sorting, empty table |

**New: mobile**

| File | Responsibility |
|---|---|
| `mobile/src/api/hooks/useHealthSeries.ts` | Fetches the series |
| `mobile/src/api/hooks/useMakes.ts` | Fetches the catalogue, held for an hour |
| `mobile/src/ui/charts/scale.ts` | `niceCeiling`, `dayLong`, `dayShort` |
| `mobile/src/ui/charts/DailyBars.tsx` | Bars, axes, tap targets and labels, shared by both charts |
| `mobile/src/ui/charts/ThroughputChart.tsx` | Completed and failed stacked, a legend, a callout |
| `mobile/src/ui/charts/FailuresByKindChart.tsx` | One small chart per kind, all on one scale |
| `mobile/src/format/errorContext.ts` | `contextLabel()`, moved out of the Health screen |
| `mobile/src/ui/Suggestions.tsx` | `suggest()` and the tappable rows |

**Modified:** `app/Filament/Widgets/SearchThroughputChart.php`,
`app/Filament/Widgets/ErrorsByContextChart.php`, `routes/api.php`,
`tests/Feature/Api/HealthTest.php`, `tests/Feature/Api/ContractFixturesTest.php`,
`DEPLOYMENT.md`, `mobile/src/api/schemas.ts`, `mobile/src/api/queryKeys.ts`,
`mobile/src/api/__tests__/schemas.test.ts`, `mobile/src/theme/tokens.ts`,
`mobile/tailwind.config.js`, `mobile/app/(app)/health/index.tsx`,
`mobile/app/(app)/health/__tests__/health.test.tsx`,
`mobile/app/(app)/search/index.tsx`,
`mobile/app/(app)/search/__tests__/search.test.tsx`.

**Deleted:** `app/Filament/Widgets/Concerns/BucketsByDay.php`, its only file
in `Concerns/`. Its docblock's reasoning moves into `PipelineSeries`.

---

### Task 1: `PipelineSeries`, and the widgets delegate to it

**Files:**
- Create: `app/Services/Health/PipelineSeries.php`
- Create: `tests/Feature/Services/Health/PipelineSeriesTest.php`
- Modify: `app/Filament/Widgets/SearchThroughputChart.php`
- Modify: `app/Filament/Widgets/ErrorsByContextChart.php`
- Delete: `app/Filament/Widgets/Concerns/BucketsByDay.php`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `PipelineSeries::THROUGHPUT_DAYS = 30`, `PipelineSeries::ERRORS_DAYS = 14`
  - `throughput(): array{days: list<string>, completed: list<int>, failed: list<int>}`
  - `errorsByContext(): array{days: list<string>, series: array<string, list<int>>}`
    (only contexts that failed, in `ErrorEvent::contexts()` order, then any
    undeclared context alphabetically)
  - `build(): array{throughput: …, errors_by_context: …}`, the API's shape

- [ ] **Step 1: Write the failing test**

`tests/Feature/Services/Health/PipelineSeriesTest.php`:

```php
<?php

namespace Tests\Feature\Services\Health;

use App\Models\ErrorEvent;
use App\Models\User;
use App\Services\Health\PipelineSeries;
use Tests\Feature\Api\ApiTestCase;

/**
 * Extends ApiTestCase for its search() helper, not for anything HTTP - there
 * are no model factories for searches in this project.
 */
class PipelineSeriesTest extends ApiTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-15 12:00:00');
        $this->user = User::factory()->create();
    }

    private function searchAt(string $status, string $createdAt): void
    {
        $this->search($this->user, ['status' => $status])
            ->forceFill(['created_at' => $createdAt])
            ->save();
    }

    private function errorAt(string $context, string $occurredAt): void
    {
        ErrorEvent::create([
            'context' => $context,
            'severity' => 'error',
            'message' => 'something broke',
            'occurred_at' => $occurredAt,
        ]);
    }

    public function test_throughput_lists_every_day_of_the_window_including_quiet_ones(): void
    {
        $throughput = app(PipelineSeries::class)->throughput();

        $this->assertCount(30, $throughput['days']);
        $this->assertSame('2026-08-17', $throughput['days'][0]);
        $this->assertSame('2026-09-15', $throughput['days'][29]);
        // Both series always present and as long as the window, so an empty
        // day reads as a gap rather than closing up and shifting its neighbours.
        $this->assertSame(array_fill(0, 30, 0), $throughput['completed']);
        $this->assertSame(array_fill(0, 30, 0), $throughput['failed']);
    }

    public function test_throughput_counts_completed_and_failed_separately(): void
    {
        $this->searchAt('completed', '2026-09-15 08:00:00');
        $this->searchAt('completed', '2026-09-15 09:00:00');
        $this->searchAt('failed', '2026-09-15 10:00:00');
        $this->searchAt('completed', '2026-09-14 10:00:00');
        // Neither finished, so neither counts as run.
        $this->searchAt('pending', '2026-09-15 11:00:00');
        $this->searchAt('running', '2026-09-15 11:00:00');

        $throughput = app(PipelineSeries::class)->throughput();

        $this->assertSame(2, $throughput['completed'][29]);
        $this->assertSame(1, $throughput['failed'][29]);
        $this->assertSame(1, $throughput['completed'][28]);
        $this->assertSame(0, $throughput['failed'][28]);
    }

    public function test_throughput_starts_at_midnight_on_the_first_day(): void
    {
        $this->searchAt('completed', '2026-08-17 00:00:00');
        $this->searchAt('completed', '2026-08-16 23:59:59');

        $completed = app(PipelineSeries::class)->throughput()['completed'];

        $this->assertSame(1, $completed[0]);
        $this->assertSame(1, array_sum($completed));
    }

    public function test_failures_list_every_day_and_only_the_contexts_that_failed(): void
    {
        $this->errorAt(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-15 09:00:00');
        $this->errorAt(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-15 10:00:00');
        $this->errorAt(ErrorEvent::CONTEXT_WIKIMEDIA_BLOCK, '2026-09-14 10:00:00');
        // Outside the 14-day window: its context must not get a series.
        $this->errorAt(ErrorEvent::CONTEXT_CSV_UPLOAD, '2026-09-01 09:00:00');

        $errors = app(PipelineSeries::class)->errorsByContext();

        $this->assertCount(14, $errors['days']);
        $this->assertSame('2026-09-02', $errors['days'][0]);
        $this->assertSame('2026-09-15', $errors['days'][13]);
        $this->assertSame(['search_run', 'wikimedia_block'], array_keys($errors['series']));
        $this->assertSame(2, $errors['series']['search_run'][13]);
        $this->assertSame(0, $errors['series']['search_run'][12]);
        $this->assertSame(1, $errors['series']['wikimedia_block'][12]);
        $this->assertCount(14, $errors['series']['wikimedia_block']);
    }

    public function test_failures_are_ordered_as_the_model_declares_contexts(): void
    {
        // Inserted, and alphabetised, in a different order from the declaration
        // (csv_upload, csv_row, search_run, ...): the response must not depend
        // on which order the database hands groups back in.
        $this->errorAt(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-15 09:00:00');
        $this->errorAt(ErrorEvent::CONTEXT_CSV_ROW, '2026-09-15 09:00:00');
        $this->errorAt(ErrorEvent::CONTEXT_CSV_UPLOAD, '2026-09-15 09:00:00');

        $series = app(PipelineSeries::class)->errorsByContext()['series'];

        $this->assertSame(['csv_upload', 'csv_row', 'search_run'], array_keys($series));
    }

    public function test_a_context_the_model_no_longer_declares_is_still_charted_last(): void
    {
        // Rows outlive code: a retired context keeps its rows until the prune
        // catches up, and the panel has always charted them under their raw
        // key rather than hiding them.
        $this->errorAt('legacy_context', '2026-09-15 09:00:00');
        $this->errorAt(ErrorEvent::CONTEXT_WIKIMEDIA_BLOCK, '2026-09-15 09:00:00');

        $series = app(PipelineSeries::class)->errorsByContext()['series'];

        $this->assertSame(['wikimedia_block', 'legacy_context'], array_keys($series));
    }

    public function test_a_quiet_fortnight_has_no_series_at_all(): void
    {
        $this->assertSame([], app(PipelineSeries::class)->errorsByContext()['series']);
    }

    public function test_build_returns_both_windows_and_no_colours(): void
    {
        $this->searchAt('failed', '2026-09-15 08:00:00');
        $this->errorAt(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-15 09:00:00');

        $built = app(PipelineSeries::class)->build();

        $this->assertSame(['throughput', 'errors_by_context'], array_keys($built));
        $this->assertSame(['days', 'completed', 'failed'], array_keys($built['throughput']));
        $this->assertSame(['days', 'series'], array_keys($built['errors_by_context']));
        // Colour is presentation: each client brings its own.
        $this->assertStringNotContainsString('#', json_encode($built));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=PipelineSeriesTest`
Expected: FAIL, `Target class [App\Services\Health\PipelineSeries] does not exist.`

- [ ] **Step 3: Write the service**

`app/Services/Health/PipelineSeries.php`:

```php
<?php

namespace App\Services\Health;

use App\Models\CarSearch;
use App\Models\ErrorEvent;
use Illuminate\Support\Collection;

/**
 * The two daily series behind the health charts: searches run, and failures by
 * kind.
 *
 * Extracted from the dashboard's SearchThroughputChart and ErrorsByContextChart
 * so the panel and the API count with one implementation. The widgets keep
 * what is their own - Chart.js's labels, datasets and colours - and layer it on
 * top, as Results layers its deep links on ImportCoverage. Nothing here carries
 * a colour: that is presentation, and each client has its own.
 *
 * The grouping is done in SQL - `DATE(...)` and a `COUNT(*)` over an indexed
 * window - rather than by pulling rows into PHP, because a bad week can put
 * thousands of rows inside the window and none of them are wanted individually.
 */
class PipelineSeries
{
    public const THROUGHPUT_DAYS = 30;

    public const ERRORS_DAYS = 14;

    /**
     * @return array{
     *     throughput: array{days: list<string>, completed: list<int>, failed: list<int>},
     *     errors_by_context: array{days: list<string>, series: array<string, list<int>>}
     * }
     */
    public function build(): array
    {
        return [
            'throughput' => $this->throughput(),
            'errors_by_context' => $this->errorsByContext(),
        ];
    }

    /**
     * Completed and failed are both always present, even when empty, so a
     * legend does not change shape between a quiet week and a busy one.
     *
     * @return array{days: list<string>, completed: list<int>, failed: list<int>}
     */
    public function throughput(): array
    {
        $days = $this->days(self::THROUGHPUT_DAYS);

        $rows = CarSearch::query()
            ->whereIn('status', ['completed', 'failed'])
            ->where('created_at', '>=', now()->subDays(self::THROUGHPUT_DAYS - 1)->startOfDay())
            ->selectRaw('status, DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('status', 'day')
            ->get();

        return [
            'days' => $days,
            'completed' => $this->countsForDays($rows->where('status', 'completed'), $days),
            'failed' => $this->countsForDays($rows->where('status', 'failed'), $days),
        ];
    }

    /**
     * Only contexts that failed in the window get a series: five permanently
     * empty ones would bury the one that matters. An absent context means zero.
     *
     * Ordered as ErrorEvent::contexts() declares them, not as the database
     * happens to return groups, so the same failures always produce the same
     * response. A context the model no longer declares keeps its series, after
     * the declared ones.
     *
     * @return array{days: list<string>, series: array<string, list<int>>}
     */
    public function errorsByContext(): array
    {
        $days = $this->days(self::ERRORS_DAYS);

        $byContext = ErrorEvent::query()
            ->where('occurred_at', '>=', now()->subDays(self::ERRORS_DAYS - 1)->startOfDay())
            ->selectRaw('context, DATE(occurred_at) as day, COUNT(*) as total')
            ->groupBy('context', 'day')
            ->get()
            ->groupBy('context');

        $declared = array_keys(ErrorEvent::contexts());
        $order = array_merge(
            array_values(array_intersect($declared, $byContext->keys()->all())),
            $byContext->keys()->diff($declared)->sort()->values()->all(),
        );

        $series = [];
        foreach ($order as $context) {
            $series[$context] = $this->countsForDays($byContext[$context], $days);
        }

        return ['days' => $days, 'series' => $series];
    }

    /**
     * The window's days as `Y-m-d` strings, oldest first.
     *
     * Every day is listed, including the quiet ones, so a gap in the data reads
     * as a gap rather than closing up and shifting its neighbours.
     *
     * @return list<string>
     */
    private function days(int $count): array
    {
        return array_map(
            fn (int $ago): string => now()->subDays($ago)->toDateString(),
            range($count - 1, 0),
        );
    }

    /**
     * @param  Collection<int, object>  $rows  rows carrying `day` and `total`
     * @param  list<string>  $days
     * @return list<int>
     */
    private function countsForDays(Collection $rows, array $days): array
    {
        $byDay = $rows->pluck('total', 'day');

        return array_map(fn (string $day): int => (int) ($byDay[$day] ?? 0), $days);
    }
}
```

- [ ] **Step 4: Run it and watch it pass**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=PipelineSeriesTest`
Expected: PASS, 8 tests.

- [ ] **Step 5: Point both widgets at the service, and delete the trait**

Replace the body of `app/Filament/Widgets/SearchThroughputChart.php`. Keep
the class docblock, `getData()`, `getType()` and `getOptions()` exactly as
they are:

```php
<?php

namespace App\Filament\Widgets;

use App\Services\Health\PipelineSeries;
use Filament\Widgets\ChartWidget;

/**
 * Throughput answers the question the error log cannot: the pipeline stopped
 * producing and *nothing failed* — it simply was never run. A flat line here
 * with an empty error log is still a problem.
 */
class SearchThroughputChart extends ChartWidget
{
    protected static ?int $sort = -1;

    protected ?string $heading = 'Searches run ('.PipelineSeries::THROUGHPUT_DAYS.' days)';

    /**
     * The counting lives in PipelineSeries, shared with the mobile API. This
     * widget adds only what is Chart.js's: labels, datasets and colours. The
     * keys stay Chart.js's own rather than the service's, because the widget
     * tests assert on `labels` and `datasets[].label/data`.
     *
     * @return array{labels: array<int, string>, datasets: array<int, array<string, mixed>>}
     */
    public function series(): array
    {
        $throughput = app(PipelineSeries::class)->throughput();

        $datasets = [];

        foreach (['completed' => ['Completed', '#16a34a'], 'failed' => ['Failed', '#dc2626']] as $status => [$label, $colour]) {
            $datasets[] = [
                'label' => $label,
                'data' => $throughput[$status],
                'borderColor' => $colour,
                'backgroundColor' => $colour,
            ];
        }

        return ['labels' => $throughput['days'], 'datasets' => $datasets];
    }

    // getData(), getType() and getOptions() unchanged.
}
```

Do the same in `app/Filament/Widgets/ErrorsByContextChart.php`, keeping its
docblock, `getData()`, `getType()` and the stacked `getOptions()` with its
comment:

```php
<?php

namespace App\Filament\Widgets;

use App\Models\ErrorEvent;
use App\Services\Health\PipelineSeries;
use Filament\Widgets\ChartWidget;

/**
 * Three failures a day tells the operator nothing. ... (docblock unchanged)
 */
class ErrorsByContextChart extends ChartWidget
{
    protected static ?int $sort = -2;

    protected ?string $heading = 'Failures by kind ('.PipelineSeries::ERRORS_DAYS.' days)';

    /**
     * Only contexts that failed in the window arrive from PipelineSeries, so
     * only they earn a legend entry. Colour is added here: it is the panel's,
     * not the data's.
     *
     * @return array{labels: array<int, string>, datasets: array<int, array<string, mixed>>}
     */
    public function series(): array
    {
        $errors = app(PipelineSeries::class)->errorsByContext();

        $datasets = [];

        foreach ($errors['series'] as $context => $data) {
            $datasets[] = [
                'label' => ErrorEvent::contexts()[$context] ?? $context,
                'data' => $data,
                'backgroundColor' => ErrorEvent::contextChartColor($context),
            ];
        }

        return ['labels' => $errors['days'], 'datasets' => $datasets];
    }

    // getData(), getType() and getOptions() unchanged.
}
```

The `// ... unchanged` lines above only mark where the existing methods stay;
do not write them into the file. Then delete the trait and its now-empty
directory:

```bash
git rm app/Filament/Widgets/Concerns/BucketsByDay.php
rmdir app/Filament/Widgets/Concerns 2>/dev/null || true
grep -rn 'BucketsByDay' app tests && echo "STILL REFERENCED" || echo "ok: trait gone"
```

- [ ] **Step 6: The widget tests pass without being edited**

```bash
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter='SearchThroughputChartTest|ErrorsByContextChartTest|PipelineSeriesTest|PanelSmokeTest'
git diff --exit-code tests/Feature/Filament/Widgets && echo "ok: widget tests untouched"
```

Expected: everything passes, and `ok: widget tests untouched` is printed. If a
widget test fails, **fix the widget, not the test**: the test failing means
the Chart.js shape moved.

- [ ] **Step 7: Lint and commit**

```bash
vendor/bin/pint --test app/Services/Health app/Filament/Widgets tests/Feature/Services/Health
git add app/Services/Health/PipelineSeries.php tests/Feature/Services/Health/PipelineSeriesTest.php app/Filament/Widgets
git commit -m "refactor: count the dashboard charts in one service the API can share"
```

---

### Task 2: `GET /api/v1/health/series`

**Files:**
- Create: `app/Http/Controllers/Api/V1/HealthSeriesController.php`
- Modify: `routes/api.php` (the `ERRORS_READ` group)
- Modify: `tests/Feature/Api/HealthTest.php`
- Modify: `tests/Feature/Api/ContractFixturesTest.php`
- Generated: `mobile/src/api/__fixtures__/health-series.json`

**Interfaces:**
- Consumes: `PipelineSeries::build()` (Task 1).
- Produces: `GET /api/v1/health/series` returns
  `{ "data": { "throughput": { "days", "completed", "failed" }, "errors_by_context": { "days", "series": {…} } } }`,
  with `series` **always a JSON object**, `{}` when empty. The route is named
  `api.v1.health.series` and needs ability `errors:read`.

- [ ] **Step 1: Write the failing tests**

Add these three methods to `tests/Feature/Api/HealthTest.php`. The `error()`
helper and the `ApiTestCase` helpers they use already exist:

```php
    public function test_the_series_requires_the_errors_read_ability(): void
    {
        $this->getJson('/api/v1/health/series')->assertUnauthorized();

        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->getJson('/api/v1/health/series')->assertForbidden();
    }

    public function test_the_series_carries_both_windows_as_data_only(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::ERRORS_READ]);
        $this->search($user, ['status' => 'completed']);
        $this->search($user, ['status' => 'failed']);
        $this->error(ErrorEvent::CONTEXT_WIKIMEDIA_BLOCK, now());

        $response = $this->getJson('/api/v1/health/series')
            ->assertOk()
            ->assertJsonCount(30, 'data.throughput.days')
            ->assertJsonPath('data.throughput.completed.29', 1)
            ->assertJsonPath('data.throughput.failed.29', 1)
            ->assertJsonCount(14, 'data.errors_by_context.days')
            ->assertJsonPath('data.errors_by_context.series.wikimedia_block.13', 1)
            ->assertJsonMissingPath('data.errors_by_context.series.search_run');

        // Colour is presentation; the app's comes from its tokens.
        $this->assertStringNotContainsString('#', $response->getContent());
    }

    public function test_a_quiet_fortnight_still_sends_series_as_an_object(): void
    {
        // PHP encodes an empty array as `[]`. The client reads `series` as a
        // map from context to counts, and a list there fails its contract -
        // which would take both charts down on exactly the good days.
        $this->actingAsApiUser([TokenAbilities::ERRORS_READ]);

        $content = $this->getJson('/api/v1/health/series')->assertOk()->getContent();

        $this->assertStringContainsString('"series":{}', $content);
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=HealthTest`
Expected: the three new tests FAIL with 404. The four existing ones pass.

- [ ] **Step 3: The controller and the route**

`app/Http/Controllers/Api/V1/HealthSeriesController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Health\PipelineSeries;
use Illuminate\Http\JsonResponse;

class HealthSeriesController extends Controller
{
    public function __invoke(PipelineSeries $series): JsonResponse
    {
        $data = $series->build();

        // PHP encodes an empty array as `[]`, and `series` is a map: a quiet
        // fortnight must still arrive as `{}`, or the client's contract
        // rejects the whole response on exactly the good days.
        $data['errors_by_context']['series'] = (object) $data['errors_by_context']['series'];

        return response()->json(['data' => $data]);
    }
}
```

In `routes/api.php`, import the controller next to `HealthSummaryController`
(alphabetical order: `HealthSeriesController` comes first), and add the route
inside the existing `ERRORS_READ` group:

```php
        Route::middleware(['ability:'.TokenAbilities::ERRORS_READ, 'throttle:120,1'])->group(function () {
            Route::get('health/summary', HealthSummaryController::class)->name('health.summary');
            // Both charts' daily counts in one request: the screen always shows both.
            Route::get('health/series', HealthSeriesController::class)->name('health.series');
            Route::get('errors', [ErrorController::class, 'index'])->name('errors.index');
        });
```

- [ ] **Step 4: Run them and watch them pass**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=HealthTest`
Expected: PASS, 7 tests.

- [ ] **Step 5: Add the contract fixture**

In `tests/Feature/Api/ContractFixturesTest.php::buildFixtures()`, add one entry
**directly after `'health'`**. The position matters: PHP evaluates the array in
order, and the later `'search-create'` entry inserts another search. Placed
here, the fixture sees exactly one completed search and one `search_run`
failure, both on its last day.

```php
            'health' => $this->getJson('/api/v1/health/summary')->assertOk()->json(),
            'health-series' => $this->getJson('/api/v1/health/series')->assertOk()->json(),
```

In the same file, the throttle comment says "all eleven authenticated calls
below", a count that was already out of date. Change that phrase to "every
authenticated call below" so it stops needing upkeep.

Regenerate the fixtures as your own user, so the new file isn't owned by root,
then rerun the test normally and confirm only the new file appeared:

```bash
docker run --rm --user "$(id -u):$(id -g)" -e UPDATE_CONTRACT_FIXTURES=1 -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ContractFixturesTest
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ContractFixturesTest
git status --short mobile/src/api/__fixtures__
```

Expected: the second run passes, and `git status` lists **only**
`?? mobile/src/api/__fixtures__/health-series.json`. Open that file and check
it: `throughput.days` runs from `2025-12-17` to `2026-01-15`;
`throughput.completed[29]` is `1`; every `failed` value is `0`;
`errors_by_context.series` has the single key `search_run`, whose last value
is `1`. Tasks 4 and 7 depend on these values.

- [ ] **Step 6: Lint and commit**

```bash
vendor/bin/pint --test
git add app/Http/Controllers/Api/V1/HealthSeriesController.php routes/api.php tests/Feature/Api/HealthTest.php tests/Feature/Api/ContractFixturesTest.php mobile/src/api/__fixtures__/health-series.json
git commit -m "feat(api): serve the health charts' daily series"
```

---

### Task 3: `GET /api/v1/makes`, and the deploy note

**Files:**
- Create: `app/Http/Controllers/Api/V1/MakeController.php`
- Create: `tests/Feature/Api/MakesTest.php`
- Modify: `routes/api.php` (the `SEARCH_READ` group)
- Modify: `tests/Feature/Api/ContractFixturesTest.php`
- Modify: `DEPLOYMENT.md` (§4.8)
- Generated: `mobile/src/api/__fixtures__/makes.json`

**Interfaces:**
- Consumes: `CarMake` and `CarModel`, plus the `CarMake::models()` relation.
- Produces: `GET /api/v1/makes` returns
  `{ "data": [ { "name": string, "models": string[] } ] }`. Makes are sorted
  by name; models are sorted with `SORT_NATURAL | SORT_FLAG_CASE`. The route
  is named `api.v1.makes.index` and needs ability `search:read`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Api/MakesTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\CarMake;

class MakesTest extends ApiTestCase
{
    private function make(string $name, array $models = []): void
    {
        $make = CarMake::create(['name' => $name]);

        foreach ($models as $model) {
            $make->models()->create(['name' => $model]);
        }
    }

    public function test_it_requires_the_search_read_ability(): void
    {
        $this->getJson('/api/v1/makes')->assertUnauthorized();

        $this->actingAsApiUser([TokenAbilities::ERRORS_READ]);
        $this->getJson('/api/v1/makes')->assertForbidden();
    }

    public function test_makes_are_sorted_by_name_and_their_models_naturally(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->make('Toyota', ['RAV4']);
        $this->make('BMW', ['X5']);
        // Natural and case-insensitive, as the panel's model dropdown sorts. A
        // plain sort gives "Model 10", "Model X", "model 3": capitals first,
        // and 10 before 3.
        $this->make('Tesla', ['Model X', 'model 3', 'Model 10']);

        $this->assertSame([
            ['name' => 'BMW', 'models' => ['X5']],
            ['name' => 'Tesla', 'models' => ['model 3', 'Model 10', 'Model X']],
            ['name' => 'Toyota', 'models' => ['RAV4']],
        ], $this->getJson('/api/v1/makes')->assertOk()->json('data'));
    }

    public function test_an_empty_table_returns_no_makes_rather_than_the_panels_fallback(): void
    {
        // The panel falls back to a hard-coded list when the table is empty.
        // The API does not: an empty list here means CarMakeSeeder has not
        // run, and the fix is running it - not a third copy of the list.
        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);

        $this->getJson('/api/v1/makes')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_a_make_with_no_models_still_sends_a_list(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->make('Lada');

        $this->assertStringContainsString('"models":[]', $this->getJson('/api/v1/makes')->getContent());
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=MakesTest`
Expected: FAIL with 404.

- [ ] **Step 3: The controller and the route**

`app/Http/Controllers/Api/V1/MakeController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CarMake;
use Illuminate\Http\JsonResponse;

/**
 * The curated catalogue the search form suggests from.
 *
 * Only what is in the table. The panel's CarSearchResource also carries a
 * hard-coded copy for when the table is empty; that list is deliberately not
 * copied a third time here. An empty table is fixed by running CarMakeSeeder,
 * and the app's fields work as plain text meanwhile.
 */
class MakeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $makes = CarMake::query()
            ->with('models')
            ->orderBy('name')
            ->get()
            ->map(fn (CarMake $make): array => [
                'name' => $make->name,
                // As CarSearchResource::getModelOptionsForMake() sorts them,
                // so "Model 10" follows "Model 9" in both places.
                'models' => $make->models
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->pluck('name')
                    ->values()
                    ->all(),
            ]);

        return response()->json(['data' => $makes]);
    }
}
```

In `routes/api.php`, import `MakeController` (alphabetically, after
`ImportController`) and add the route as the last line of the `SEARCH_READ`
group:

```php
            Route::get('searches/{search}/images', [SearchController::class, 'images'])->name('searches.images');

            // The search form's suggestions. Small and rarely changing; the
            // app holds it for an hour.
            Route::get('makes', MakeController::class)->name('makes.index');
        });
```

- [ ] **Step 4: Run them and watch them pass**

Run: `docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=MakesTest`
Expected: PASS, 4 tests.

- [ ] **Step 5: Add the contract fixture**

In `ContractFixturesTest::buildFixtures()`, add `use App\Models\CarMake;` to
the imports. Seed two makes **just before** `Sanctum::actingAs(...)`, so the
fixture pins the sort as well as the shape:

```php
        // Two makes, out of order, so makes.json pins the sort as well as the
        // shape. Nothing else reads car_makes, so no other fixture moves.
        CarMake::create(['name' => 'Honda'])->models()->createMany([
            ['name' => 'CR-V'],
            ['name' => 'Civic'],
        ]);
        CarMake::create(['name' => 'BMW'])->models()->create(['name' => 'X5']);
```

Then add the entry after `'health-series'`:

```php
            'makes' => $this->getJson('/api/v1/makes')->assertOk()->json(),
```

Regenerate and verify as in Task 2:

```bash
docker run --rm --user "$(id -u):$(id -g)" -e UPDATE_CONTRACT_FIXTURES=1 -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ContractFixturesTest
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test --filter=ContractFixturesTest
git status --short mobile/src/api/__fixtures__
cat mobile/src/api/__fixtures__/makes.json
```

Expected: `git status` lists **only** `?? mobile/src/api/__fixtures__/makes.json`,
and its `data` is `[{"name":"BMW","models":["X5"]},{"name":"Honda","models":["Civic","CR-V"]}]`.

- [ ] **Step 6: Record the deploy note**

In `DEPLOYMENT.md` §4.8, after the code block that ends with
`php artisan db:seed --class=FilamentAdminUserSeeder --force`, add:

````markdown
**Check that `car_makes` has rows.** The mobile search form suggests makes and
models from this table and from nowhere else. If production never ran
`CarMakeSeeder`, the panel has been showing its hard-coded fallback list all
along and the app will suggest nothing. The seeder is safe to rerun
(`firstOrCreate`):

```bash
php artisan tinker --execute="echo App\Models\CarMake::count();"
php artisan db:seed --class=CarMakeSeeder --force   # only if that printed 0
```
````

- [ ] **Step 7: Lint and commit**

```bash
vendor/bin/pint --test
git add app/Http/Controllers/Api/V1/MakeController.php tests/Feature/Api/MakesTest.php routes/api.php tests/Feature/Api/ContractFixturesTest.php mobile/src/api/__fixtures__/makes.json DEPLOYMENT.md
git commit -m "feat(api): list the make and model catalogue for the search form"
```

---

### Task 4: The client contract and hooks

**Files:**
- Modify: `mobile/src/api/schemas.ts`
- Modify: `mobile/src/api/queryKeys.ts`
- Modify: `mobile/src/api/__tests__/schemas.test.ts`
- Create: `mobile/src/api/hooks/useHealthSeries.ts`
- Create: `mobile/src/api/hooks/useMakes.ts`

**Interfaces:**
- Consumes: the two fixtures from Tasks 2 and 3.
- Produces:
  - `HealthSeriesSchema`, `type HealthSeries`
  - `CarMakeSchema`, `MakesSchema`, `type CarMake = { name: string; models: string[] }`
  - `queryKeys.healthSeries()` is `['health', 'series']`;
    `queryKeys.makes()` is `['makes']`
  - `useHealthSeries()` returns a `UseQueryResult<HealthSeries>`;
    `useMakes()` returns a `UseQueryResult<CarMake[]>`

- [ ] **Step 1: Write the failing tests**

In `mobile/src/api/__tests__/schemas.test.ts`, add two fixture imports,
keeping the list alphabetical:

```ts
import healthSeriesFixture from '../__fixtures__/health-series.json';
import makesFixture from '../__fixtures__/makes.json';
```

Add `HealthSeriesSchema` and `MakesSchema` to the `../schemas` import list,
and these tests inside `describe('the API contract', ...)`:

```ts
  it('parses the health series', () => {
    const parsed = single(HealthSeriesSchema).parse(healthSeriesFixture);

    // Every day listed, quiet ones included: each array lines up with `days`.
    expect(parsed.data.throughput.completed).toHaveLength(parsed.data.throughput.days.length);
    expect(Object.keys(parsed.data.errors_by_context.series)).toEqual(['search_run']);
  });

  it('accepts a fortnight with no failures', () => {
    expect(() =>
      HealthSeriesSchema.shape.errors_by_context.parse({ days: ['2026-09-15'], series: {} }),
    ).not.toThrow();
  });

  it('rejects a series sent as a list', () => {
    // What PHP sends for an empty array unless the controller casts it. The
    // server test pins the cast; this pins why it matters.
    expect(() =>
      HealthSeriesSchema.shape.errors_by_context.parse({ days: [], series: [] }),
    ).toThrow();
  });

  it('accepts a failure kind the app does not know yet', () => {
    // A context added on the server should arrive as one more chart, not as a
    // contract failure that blanks both.
    expect(() =>
      HealthSeriesSchema.shape.errors_by_context.parse({
        days: ['2026-09-15'],
        series: { meteor_strike: [1] },
      }),
    ).not.toThrow();
  });

  it('parses the make catalogue', () => {
    const parsed = single(MakesSchema).parse(makesFixture);

    expect(parsed.data.map((make) => make.name)).toEqual(['BMW', 'Honda']);
    expect(parsed.data[1]?.models).toEqual(['Civic', 'CR-V']);
  });
```

- [ ] **Step 2: Run them and watch them fail**

Run: `cd mobile && npx jest src/api/__tests__/schemas.test.ts`
Expected: FAIL. The TypeScript transform reports that `HealthSeriesSchema` and
`MakesSchema` are not exported (or that they are `undefined`).

- [ ] **Step 3: The schemas**

In `mobile/src/api/schemas.ts`, add directly after `HealthSummary`:

```ts
/**
 * GET /health/series: the two charts' daily counts. Data only - colour comes
 * from the tokens, never the API.
 *
 * Every day of each window is listed, quiet ones included, and every array
 * lines up with its `days`.
 */
export const HealthSeriesSchema = z.object({
  throughput: z.object({
    days: z.array(z.string()),
    completed: z.array(z.number().int()),
    failed: z.array(z.number().int()),
  }),
  errors_by_context: z.object({
    days: z.array(z.string()),
    /*
     * Only the contexts that failed in the window; absent means zero. Keyed by
     * plain string rather than ErrorContextSchema: a context added on the
     * server should arrive as one more small multiple, not as a contract
     * failure that blanks both charts. (Zod 4's record over an enum is also
     * exhaustive - it would demand all five keys.)
     */
    series: z.record(z.string(), z.array(z.number().int())),
  }),
});
export type HealthSeries = z.infer<typeof HealthSeriesSchema>;
```

And directly after `ExportLink`:

```ts
/** GET /makes: the curated catalogue the search form suggests from. */
export const CarMakeSchema = z.object({
  name: z.string(),
  models: z.array(z.string()),
});
export type CarMake = z.infer<typeof CarMakeSchema>;

export const MakesSchema = z.array(CarMakeSchema);
```

- [ ] **Step 4: Run them and watch them pass**

Run: `cd mobile && npx jest src/api/__tests__/schemas.test.ts`
Expected: PASS.

- [ ] **Step 5: Query keys and hooks**

In `mobile/src/api/queryKeys.ts`, after `health`:

```ts
  health: () => ['health'] as const,
  // Under 'health' on purpose: useCreateSearch invalidates ['health'] after a
  // run, and that prefix refreshes the charts along with the counters.
  healthSeries: () => ['health', 'series'] as const,
  makes: () => ['makes'] as const,
```

`mobile/src/api/hooks/useHealthSeries.ts`:

```ts
import { useQuery } from '@tanstack/react-query';

import { apiRequest } from '../client';
import { queryKeys } from '../queryKeys';
import { HealthSeriesSchema, single } from '../schemas';

const series = single(HealthSeriesSchema);

export function useHealthSeries() {
  return useQuery({
    queryKey: queryKeys.healthSeries(),
    queryFn: async () => (await apiRequest('/health/series', { schema: series })).data,
    // Daily buckets: a minute is already finer than the smallest unit drawn.
    staleTime: 60_000,
  });
}
```

`mobile/src/api/hooks/useMakes.ts`:

```ts
import { useQuery } from '@tanstack/react-query';

import { apiRequest } from '../client';
import { queryKeys } from '../queryKeys';
import { MakesSchema, single } from '../schemas';

const makes = single(MakesSchema);

/**
 * The make catalogue behind the search form's suggestions.
 *
 * Ten curated makes that change only when someone edits them in the panel, so
 * it is held for an hour. A failure is left for the caller to ignore:
 * suggestions are a convenience, and the fields work as plain text without
 * them.
 */
export function useMakes() {
  return useQuery({
    queryKey: queryKeys.makes(),
    queryFn: async () => (await apiRequest('/makes', { schema: makes })).data,
    staleTime: 60 * 60_000,
  });
}
```

- [ ] **Step 6: Typecheck and commit**

```bash
cd mobile && npm run typecheck && npx jest src/api
git add src/api/schemas.ts src/api/queryKeys.ts src/api/__tests__/schemas.test.ts src/api/hooks/useHealthSeries.ts src/api/hooks/useMakes.ts
git commit -m "feat(mobile): parse the health series and the make catalogue"
```

---

### Task 5: `DailyBars` and the throughput chart

**Files:**
- Create: `mobile/src/ui/charts/scale.ts`
- Create: `mobile/src/ui/charts/DailyBars.tsx`
- Create: `mobile/src/ui/charts/ThroughputChart.tsx`
- Create: `mobile/src/ui/charts/__tests__/scale.test.ts`
- Create: `mobile/src/ui/charts/__tests__/ThroughputChart.test.tsx`

**Interfaces:**
- Consumes: the tokens' `success` and `danger` (the classes `bg-success` and
  `bg-danger`).
- Produces:
  - `niceCeiling(value: number): number`, `dayLong(day: string): string` and
    `dayShort(day: string): string`
  - `interface Segment { key: string; value: number; className: string }`
  - `<DailyBars days stacks max height describe selected onSelect />`. Each day
    is a `Pressable` with `accessibilityRole="button"` and
    `accessibilityLabel={describe(i)}`. Each drawn segment has
    `testID="segment-<key>"`, and a zero value draws no segment at all.
  - `<ThroughputChart days completed failed />`

- [ ] **Step 1: Write the failing tests**

`mobile/src/ui/charts/__tests__/scale.test.ts`:

```ts
import { dayLong, dayShort, niceCeiling } from '../scale';

describe('niceCeiling', () => {
  it('rounds the tallest value up to 1, 2 or 5 times a power of ten', () => {
    expect([1, 2, 3, 7, 12, 48, 51, 100].map(niceCeiling)).toEqual([
      1, 2, 5, 10, 20, 50, 100, 100,
    ]);
  });

  it('never returns less than 1', () => {
    // An all-zero window still needs a scale, or every bar height divides by
    // zero.
    expect(niceCeiling(0)).toBe(1);
  });
});

describe('day labels', () => {
  it('names the day as written, not as midnight UTC', () => {
    expect(dayLong('2026-09-03')).toBe('3 September');
    expect(dayShort('2026-09-03')).toBe('3 Sep');
  });
});
```

`mobile/src/ui/charts/__tests__/ThroughputChart.test.tsx`:

```tsx
import { fireEvent, render, screen, within } from '@testing-library/react-native';

import { ThroughputChart } from '../ThroughputChart';

const days = ['2026-09-13', '2026-09-14', '2026-09-15'];
const chart = <ThroughputChart days={days} completed={[4, 0, 2]} failed={[1, 0, 0]} />;

describe('<ThroughputChart />', () => {
  it('draws one bar per day, each labelled with its figures', () => {
    // A screen reader reads the data a sighted user reads off the bars.
    render(chart);

    expect(screen.getAllByRole('button')).toHaveLength(3);
    expect(screen.getByLabelText('13 September: 4 completed, 1 failed')).toBeTruthy();
    expect(screen.getByLabelText('15 September: 2 completed, 0 failed')).toBeTruthy();
  });

  it('draws only the segments a day has, and nothing for a quiet day', () => {
    // An empty day must be visibly empty: a stub of colour would read as
    // "something ran".
    render(chart);

    const quiet = screen.getByLabelText('14 September: 0 completed, 0 failed');
    expect(within(quiet).queryByTestId('segment-completed')).toBeNull();
    expect(within(quiet).queryByTestId('segment-failed')).toBeNull();

    const both = screen.getByLabelText('13 September: 4 completed, 1 failed');
    expect(within(both).getByTestId('segment-completed')).toBeTruthy();
    expect(within(both).getByTestId('segment-failed')).toBeTruthy();

    const completedOnly = screen.getByLabelText('15 September: 2 completed, 0 failed');
    expect(within(completedOnly).queryByTestId('segment-failed')).toBeNull();
  });

  it("shows a tapped day's figures, and the window's totals after a second tap", () => {
    // A phone has no hover: tapping a bar is the tooltip.
    render(chart);
    expect(screen.getByText('6 completed, 1 failed in 3 days')).toBeTruthy();

    fireEvent.press(screen.getByLabelText('13 September: 4 completed, 1 failed'));
    expect(screen.getByText('13 September: 4 completed, 1 failed')).toBeTruthy();

    fireEvent.press(screen.getByLabelText('13 September: 4 completed, 1 failed'));
    expect(screen.getByText('6 completed, 1 failed in 3 days')).toBeTruthy();
  });

  it('names both series in a legend, so colour never carries the meaning alone', () => {
    // Red against green is marginal under deuteranopia (ΔE 8.1).
    render(chart);

    expect(screen.getByText('Completed')).toBeTruthy();
    expect(screen.getByText('Failed')).toBeTruthy();
  });

  it('scales an all-zero window without dividing by zero', () => {
    render(<ThroughputChart days={days} completed={[0, 0, 0]} failed={[0, 0, 0]} />);

    expect(screen.queryAllByTestId(/^segment-/)).toHaveLength(0);
    // The axis tops out at 1 rather than at 0 or NaN.
    expect(screen.getByText('1')).toBeTruthy();
  });
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `cd mobile && npx jest src/ui/charts`
Expected: FAIL, `Cannot find module '../scale'` and `'../ThroughputChart'`.

- [ ] **Step 3: `scale.ts`**

```ts
const MONTHS = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
] as const;

/**
 * Rounds a chart's tallest value up to 1, 2 or 5 times a power of ten, so the
 * axis tops out at a number read at a glance ("20") rather than the raw
 * maximum ("17"). Never below 1: an all-zero window still needs a scale.
 */
export function niceCeiling(value: number): number {
  if (!(value > 0)) return 1;

  const magnitude = 10 ** Math.floor(Math.log10(value));

  // 10 is in the list so a log that lands a hair under a power of ten still
  // finishes on the right answer.
  for (const step of [1, 2, 5, 10]) {
    if (step * magnitude >= value) return step * magnitude;
  }

  return 10 * magnitude;
}

/**
 * Split by hand, never `new Date('2026-09-03')`: that parses as midnight UTC,
 * which is the previous evening anywhere west of Greenwich, and the label
 * would name a day the server did not count.
 */
function parts(day: string): { date: number; month: string } {
  const [, month = 1, date = 1] = day.split('-').map(Number);

  return { date, month: MONTHS[month - 1] ?? '' };
}

/** '2026-09-03' -> '3 September', for screen readers and the callout. */
export function dayLong(day: string): string {
  const { date, month } = parts(day);

  return `${date} ${month}`;
}

/** '2026-09-03' -> '3 Sep', for the axis. */
export function dayShort(day: string): string {
  const { date, month } = parts(day);

  return `${date} ${month.slice(0, 3)}`;
}
```

- [ ] **Step 4: `DailyBars.tsx`**

```tsx
import { Pressable, Text, View } from 'react-native';

import { dayShort } from './scale';

export interface Segment {
  /** Becomes the segment's testID, `segment-<key>`. */
  key: string;
  value: number;
  /**
   * A whole background class from the tokens, such as `bg-success`, written
   * out in full where it is chosen: Tailwind finds classes by scanning the
   * source, so a class assembled at runtime is never generated.
   */
  className: string;
}

interface Props {
  days: string[];
  /** One stack per day, baseline segment first. */
  stacks: Segment[][];
  /** The axis top. Passed in, not derived, so small multiples can share one. */
  max: number;
  /** Plot height in px. */
  height: number;
  /** What a screen reader hears for a day. */
  describe: (index: number) => string;
  selected: number | null;
  onSelect: (index: number | null) => void;
}

/** The surface gap between stacked segments, and between neighbouring bars. */
const GAP = 2;

/** A non-zero value never draws thinner than this, or one failure beside fifty successes vanishes. */
const MIN_SEGMENT = 2;

/** Rounded at the data end only; the baseline end stays square. */
const DATA_END_RADIUS = 4;

/**
 * Daily bars drawn with plain Views: no chart library, so nothing new for
 * Jest to load and the same rendering on web.
 *
 * Each day's whole column is its tap target, wider and taller than the bar
 * drawn inside it - thirty bars on a phone are too thin to hit otherwise.
 * The axes are recessive: muted labels at the top and foot of one y-axis, the
 * first and last day under the x-axis, hairlines in the border colour.
 */
export function DailyBars({ days, stacks, max, height, describe, selected, onSelect }: Props) {
  // Scaled against the plot less one gap, so the tallest stack still fits
  // with a gap inside it.
  const scale = (height - GAP) / max;

  return (
    <View>
      <View className="flex-row">
        <View className="w-8 justify-between pr-1" style={{ height }}>
          <Text className="text-right text-micro text-text-muted">{max}</Text>
          <Text className="text-right text-micro text-text-muted">0</Text>
        </View>

        <View className="flex-1 border-b border-t border-border">
          <View className="flex-row items-end overflow-hidden" style={{ height }}>
            {stacks.map((stack, index) => {
              const drawn = stack.filter((segment) => segment.value > 0);

              return (
                <Pressable
                  key={days[index] ?? index}
                  accessibilityRole="button"
                  accessibilityLabel={describe(index)}
                  accessibilityState={{ selected: selected === index }}
                  onPress={() => onSelect(selected === index ? null : index)}
                  className="h-full flex-1 justify-end"
                  style={{
                    paddingHorizontal: GAP / 2,
                    opacity: selected === null || selected === index ? 1 : 0.4,
                  }}
                >
                  {/* Top segment first: the column stacks downward, and the
                      baseline segment belongs at the bottom. */}
                  {[...drawn].reverse().map((segment, position) => (
                    <View
                      key={segment.key}
                      testID={`segment-${segment.key}`}
                      className={segment.className}
                      style={{
                        height: Math.max(MIN_SEGMENT, Math.round(segment.value * scale)),
                        marginBottom: position < drawn.length - 1 ? GAP : 0,
                        borderTopLeftRadius: position === 0 ? DATA_END_RADIUS : 0,
                        borderTopRightRadius: position === 0 ? DATA_END_RADIUS : 0,
                      }}
                    />
                  ))}
                </Pressable>
              );
            })}
          </View>
        </View>
      </View>

      {days.length > 0 ? (
        <View className="ml-8 mt-1 flex-row justify-between">
          <Text className="text-micro text-text-muted">{dayShort(days[0] ?? '')}</Text>
          <Text className="text-micro text-text-muted">
            {dayShort(days[days.length - 1] ?? '')}
          </Text>
        </View>
      ) : null}
    </View>
  );
}
```

- [ ] **Step 5: `ThroughputChart.tsx`**

```tsx
import { useState } from 'react';
import { Text, View } from 'react-native';

import { DailyBars } from './DailyBars';
import { dayLong, niceCeiling } from './scale';

interface Props {
  days: string[];
  completed: number[];
  failed: number[];
}

const HEIGHT = 120;

const sum = (values: number[]) => values.reduce((total, value) => total + value, 0);

/**
 * Searches run per day: completed at the base, failed stacked on top.
 *
 * Thirty bars rather than the panel's line. On a phone, bars answer "did the
 * pipeline run that day?" at a glance, and an empty day is visibly empty
 * where a line would join straight across it.
 *
 * Status colours, because completed against failed means good against bad.
 * Their red-green separation is marginal under deuteranopia (ΔE 8.1), so
 * colour never carries the meaning alone: the legend names both series, and
 * a surface gap divides the two segments.
 */
export function ThroughputChart({ days, completed, failed }: Props) {
  const [selected, setSelected] = useState<number | null>(null);

  const completedOn = (i: number) => completed[i] ?? 0;
  const failedOn = (i: number) => failed[i] ?? 0;
  const describe = (i: number) =>
    `${dayLong(days[i] ?? '')}: ${completedOn(i)} completed, ${failedOn(i)} failed`;

  const max = niceCeiling(Math.max(0, ...days.map((_, i) => completedOn(i) + failedOn(i))));

  return (
    <View className="mb-6 rounded-surface bg-surface-raised p-3">
      {/* The tap callout. It always holds a line - the window's totals until
          a bar is tapped - so the chart does not jump when one is. */}
      <Text className="mb-2 text-meta text-text-secondary">
        {selected === null
          ? `${sum(completed)} completed, ${sum(failed)} failed in ${days.length} days`
          : describe(selected)}
      </Text>

      <DailyBars
        days={days}
        stacks={days.map((_, i) => [
          { key: 'completed', value: completedOn(i), className: 'bg-success' },
          { key: 'failed', value: failedOn(i), className: 'bg-danger' },
        ])}
        max={max}
        height={HEIGHT}
        describe={describe}
        selected={selected}
        onSelect={setSelected}
      />

      <View className="mt-3 flex-row gap-4">
        <LegendItem swatch="bg-success" label="Completed" />
        <LegendItem swatch="bg-danger" label="Failed" />
      </View>
    </View>
  );
}

/** The label wears the text colour; only the swatch beside it carries the series colour. */
function LegendItem({ swatch, label }: { swatch: string; label: string }) {
  return (
    <View className="flex-row items-center gap-1">
      <View className={`h-2 w-2 rounded-sm ${swatch}`} />
      <Text className="text-meta text-text-secondary">{label}</Text>
    </View>
  );
}
```

- [ ] **Step 6: Run them and watch them pass**

Run: `cd mobile && npx jest src/ui/charts && npm run typecheck && npm run lint`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
cd mobile
git add src/ui/charts
git commit -m "feat(mobile): draw daily throughput as stacked bars without a chart library"
```

---

### Task 6: The failures-by-kind small multiples

**Files:**
- Modify: `mobile/src/theme/tokens.ts`
- Modify: `mobile/tailwind.config.js`
- Create: `mobile/src/format/errorContext.ts`
- Create: `mobile/src/format/__tests__/errorContext.test.ts`
- Create: `mobile/src/ui/charts/FailuresByKindChart.tsx`
- Create: `mobile/src/ui/charts/__tests__/FailuresByKindChart.test.tsx`
- Modify: `mobile/app/(app)/health/index.tsx` (swap its local
  `CONTEXT_LABELS` for `contextLabel`)

**Interfaces:**
- Consumes: `DailyBars`, `niceCeiling` and `dayLong` (Task 5).
- Produces:
  - `color.chartSeries = '#3987e5'` and the class `bg-chart-series`
  - `contextLabel(context: string): string`
  - `<FailuresByKindChart days series />`. Each bar's label is
    `"<Kind label>, <day>: <count>"`, for example `"Search run, 15 September: 2"`.

- [ ] **Step 1: Write the failing tests**

`mobile/src/format/__tests__/errorContext.test.ts`:

```ts
import { contextLabel } from '../errorContext';

describe('contextLabel', () => {
  it('labels a known context as the panel does', () => {
    expect(contextLabel('wikimedia_block')).toBe('Wikimedia block');
  });

  it('falls back to the raw key for a context it does not know', () => {
    // As the panel does: a new context shows up under its key rather than
    // disappearing.
    expect(contextLabel('meteor_strike')).toBe('meteor_strike');
  });
});
```

`mobile/src/ui/charts/__tests__/FailuresByKindChart.test.tsx`:

```tsx
import { fireEvent, render, screen } from '@testing-library/react-native';

import { FailuresByKindChart } from '../FailuresByKindChart';

const days = ['2026-09-14', '2026-09-15'];

describe('<FailuresByKindChart />', () => {
  it('draws one small multiple per kind that failed, titled by its label', () => {
    render(
      <FailuresByKindChart days={days} series={{ search_run: [0, 2], wikimedia_block: [1, 0] }} />,
    );

    expect(screen.getByText('Search run')).toBeTruthy();
    expect(screen.getByText('Wikimedia block')).toBeTruthy();
    // Absent means zero, and zero gets no chart.
    expect(screen.queryByText('CSV row')).toBeNull();
    expect(screen.getByLabelText('Search run, 15 September: 2')).toBeTruthy();
  });

  it('says so when nothing failed, rather than drawing an empty chart', () => {
    render(<FailuresByKindChart days={days} series={{}} />);

    expect(screen.getByText('No failures in the last 2 days.')).toBeTruthy();
    expect(screen.queryAllByRole('button')).toHaveLength(0);
  });

  it('puts every kind on one scale so they compare honestly', () => {
    // On separate scales, one failure in a quiet kind would draw as tall as
    // twenty in a busy one.
    render(<FailuresByKindChart days={days} series={{ search_run: [0, 20], csv_row: [1, 0] }} />);

    expect(screen.getAllByText('20')).toHaveLength(2);
  });

  it("shows a tapped day's count in that kind's own title row", () => {
    render(<FailuresByKindChart days={days} series={{ search_run: [0, 2] }} />);
    expect(screen.getByText('2 in 2 days')).toBeTruthy();

    fireEvent.press(screen.getByLabelText('Search run, 15 September: 2'));

    expect(screen.getByText('15 September: 2')).toBeTruthy();
  });

  it('charts a kind the app does not know under its raw key', () => {
    render(<FailuresByKindChart days={days} series={{ meteor_strike: [1, 0] }} />);

    expect(screen.getByText('meteor_strike')).toBeTruthy();
  });
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `cd mobile && npx jest src/format src/ui/charts/__tests__/FailuresByKindChart.test.tsx`
Expected: FAIL, cannot find `../errorContext` and `../FailuresByKindChart`.

- [ ] **Step 3: The token**

In `mobile/src/theme/tokens.ts`, add after `infoText`:

```ts
  /*
   * The one categorical chart colour: blue, slot one of the validated palette,
   * at 3:1 or better against surfaceRaised. Failures by kind draws each kind
   * as its own small multiple, so identity never rides on colour and one hue
   * is all it needs. Not red - red already means "failed" in the throughput
   * chart beside it.
   */
  chartSeries: '#3987e5',
```

In `mobile/tailwind.config.js`, add to `colors` after `info`:

```js
        chart: { series: color.chartSeries },
```

`src/theme/__tests__/tokens.test.ts` already checks that every colour is a
six-digit hex value, so it covers the new token as it stands.

- [ ] **Step 4: `errorContext.ts`, and the Health screen uses it**

`mobile/src/format/errorContext.ts`:

```ts
/** Mirrors ErrorEvent::contexts() - keys to the labels Filament shows. */
const CONTEXT_LABELS: Record<string, string> = {
  csv_upload: 'CSV upload',
  csv_row: 'CSV row',
  search_run: 'Search run',
  image_download: 'Image download',
  wikimedia_block: 'Wikimedia block',
};

/**
 * A context's label. One the app does not know yet shows as its raw key, as
 * the panel does, rather than disappearing.
 */
export function contextLabel(context: string): string {
  return CONTEXT_LABELS[context] ?? context;
}
```

In `mobile/app/(app)/health/index.tsx`:
- delete the local `CONTEXT_LABELS` constant and its comment;
- add `import { contextLabel } from '@/format/errorContext';`;
- replace both uses of `CONTEXT_LABELS[context] ?? context` and
  `CONTEXT_LABELS[event.context] ?? event.context` with
  `contextLabel(context)` and `contextLabel(event.context)`.

- [ ] **Step 5: `FailuresByKindChart.tsx`**

```tsx
import { useState } from 'react';
import { Text, View } from 'react-native';

import { contextLabel } from '@/format/errorContext';

import { DailyBars } from './DailyBars';
import { dayLong, niceCeiling } from './scale';

interface Props {
  days: string[];
  /** Only the kinds that failed in the window; absent means zero. */
  series: Record<string, number[]>;
}

const HEIGHT = 48;

/**
 * Failures per day, one small multiple per kind.
 *
 * Not one stacked bar: colour has to follow the kind, never its rank, and only
 * kinds that failed get a series - so any two of five kinds can end up side by
 * side, and no five-hue palette keeps every pair apart (magenta and aqua fall
 * to ΔE 1.6 under deuteranopia). Each multiple is a single series titled by
 * its kind, so nothing depends on colour for identity at all.
 *
 * Every multiple shares one y-scale, so the kinds remain comparable.
 */
export function FailuresByKindChart({ days, series }: Props) {
  const [selected, setSelected] = useState<{ context: string; index: number } | null>(null);

  const contexts = Object.keys(series);

  if (contexts.length === 0) {
    return (
      <Text className="mb-6 text-meta text-text-secondary">
        No failures in the last {days.length} days.
      </Text>
    );
  }

  const max = niceCeiling(Math.max(0, ...Object.values(series).flat()));

  return (
    <View className="mb-6 gap-2">
      {contexts.map((context) => {
        const values = series[context] ?? [];
        const label = contextLabel(context);
        const countOn = (i: number) => values[i] ?? 0;
        const active = selected?.context === context ? selected.index : null;
        const total = values.reduce((sum, value) => sum + value, 0);

        return (
          <View key={context} className="rounded-surface bg-surface-raised p-3">
            <View className="mb-2 flex-row justify-between">
              <Text className="text-meta font-medium text-text">{label}</Text>
              <Text className="text-meta text-text-secondary">
                {active === null
                  ? `${total} in ${days.length} days`
                  : `${dayLong(days[active] ?? '')}: ${countOn(active)}`}
              </Text>
            </View>

            <DailyBars
              days={days}
              stacks={values.map((value) => [
                { key: context, value, className: 'bg-chart-series' },
              ])}
              max={max}
              height={HEIGHT}
              describe={(i) => `${label}, ${dayLong(days[i] ?? '')}: ${countOn(i)}`}
              selected={active}
              onSelect={(index) => setSelected(index === null ? null : { context, index })}
            />
          </View>
        );
      })}
    </View>
  );
}
```

- [ ] **Step 6: Run them and watch them pass**

Run: `cd mobile && npx jest src/format src/ui/charts src/theme "app/(app)/health" && npm run typecheck && npm run lint`
Expected: PASS. That includes the existing Health tests: the labels are
unchanged, and only where they come from has moved.

- [ ] **Step 7: Commit**

```bash
cd mobile
git add src/theme/tokens.ts tailwind.config.js src/format/errorContext.ts src/format/__tests__/errorContext.test.ts src/ui/charts/FailuresByKindChart.tsx src/ui/charts/__tests__/FailuresByKindChart.test.tsx "app/(app)/health/index.tsx"
git commit -m "feat(mobile): chart failures as one small multiple per kind"
```

---

### Task 7: The charts on the Health screen

**Files:**
- Modify: `mobile/app/(app)/health/index.tsx`
- Modify: `mobile/app/(app)/health/__tests__/health.test.tsx`

**Interfaces:**
- Consumes: `useHealthSeries()` (Task 4), `ThroughputChart` (Task 5) and
  `FailuresByKindChart` (Task 6).
- Produces: nothing new.

- [ ] **Step 1: Rewrite the test file to answer every request the screen makes**

The current tests answer anything that isn't `/health/summary` with the
error log fixture. Once the screen also asks for `/health/series`, that
request would receive the error log, and `.data` would be an array of events
handed to the charts. Replace the whole of
`mobile/app/(app)/health/__tests__/health.test.tsx` with the file below. The
four existing tests keep their assertions and comments unchanged; only the
way requests are answered changes. Two new tests follow them.

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react-native';
import type { ReactNode } from 'react';

import errorsFixture from '@/api/__fixtures__/errors.json';
import healthSeriesFixture from '@/api/__fixtures__/health-series.json';
import healthFixture from '@/api/__fixtures__/health.json';
import * as client from '@/api/client';
import Health from '../index';

jest.mock('@/auth/AuthContext', () => ({
  useAuth: () => ({ status: 'authenticated', user: null, signIn: jest.fn(), signOut: jest.fn() }),
}));

const wrapper = ({ children }: { children: ReactNode }) => {
  // gcTime: 0 disposes the cache (and its GC timer) as soon as nothing
  // observes it, instead of leaving it scheduled for the default 5 minutes.
  // Without this the timer outlives the test as a real handle and Jest
  // hangs past its normal exit - `--detectOpenHandles` catches it directly.
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
};

/**
 * The screen's three requests, answered from the committed contract fixtures.
 * A test overrides only the one it is about. A request this table does not
 * know is rejected rather than handed another endpoint's payload, so a new
 * request on this screen fails here, loudly.
 */
const FIXTURES: Record<string, unknown> = {
  '/health/summary': healthFixture,
  '/health/series': healthSeriesFixture,
  '/errors': errorsFixture,
};

function answer(overrides: Record<string, () => Promise<unknown>> = {}) {
  return jest.spyOn(client, 'apiRequest').mockImplementation((path) => {
    const override = overrides[path];
    if (override) return override();
    if (!(path in FIXTURES)) return Promise.reject(new Error(`Unexpected request to ${path}`));

    return Promise.resolve(FIXTURES[path]);
  });
}

describe('<Health />', () => {
  beforeEach(() => jest.restoreAllMocks());

  it('renders the counters and the error log', async () => {
    answer();

    render(<Health />, { wrapper });

    await waitFor(() => expect(screen.getByText('completed')).toBeTruthy());

    // Assert the count alongside its label, scoped to that one tile - not
    // just that "completed" and "1" each appear somewhere on the screen.
    // getByText('completed') alone would still pass if a status/count
    // mapping bug swapped values between statuses.
    // Two levels up: `.parent` of the matched host text is RN's own `Text`
    // composite, and its `.parent` is the `StatTile`'s enclosing `View` -
    // the tile that also holds the sibling `<Text>{value}</Text>`.
    const completedTile = screen.getByText('completed').parent?.parent;
    expect(completedTile).not.toBeNull();
    expect(within(completedTile!).getByText('1')).toBeTruthy();

    // getByText on the fixture's own message text, not a query for "search
    // run": the humanized "Search run" label also renders in the "Errors by
    // context, 7d" counters (driven by useHealth alone), so a query that
    // only checks for that text passes even if the error log never renders.
    // This message string is unique to the logged event, so it only exists
    // if useErrors actually resolved and the log rendered it.
    expect(screen.getByText('The search run failed.')).toBeTruthy();
  });

  it('does not call the log empty while it is still loading', async () => {
    // The empty-log line is guarded by a compound condition
    // (`!isLoading && !isError && length === 0`), and the tempting tidy-up is
    // to reduce it to `events.length === 0`. That reads "nothing logged" over
    // a log that has not arrived yet, and over one that failed to arrive at
    // all - the same swallowed-failure shape already fixed in InfiniteGrid.
    answer({ '/errors': () => new Promise(() => {}) });

    render(<Health />, { wrapper });

    await waitFor(() => expect(screen.getByText('completed')).toBeTruthy());
    expect(screen.queryByText('Nothing logged.')).toBeNull();
  });

  it('shows the failure instead of calling the log empty when it cannot be read', async () => {
    answer({
      '/errors': () => Promise.reject(new client.ApiError(500, 'The error log is unavailable (500).')),
    });

    render(<Health />, { wrapper });

    await waitFor(() =>
      expect(screen.getByText('The error log is unavailable (500).')).toBeTruthy(),
    );
    expect(screen.queryByText('Nothing logged.')).toBeNull();
  });

  it('says so when the log really is empty', async () => {
    answer({ '/errors': () => Promise.resolve({ ...errorsFixture, data: [] }) });

    render(<Health />, { wrapper });

    await waitFor(() => expect(screen.getByText('Nothing logged.')).toBeTruthy());
  });

  it('draws both charts from the series', async () => {
    answer();

    render(<Health />, { wrapper });

    // The fixture's one completed search and one search_run failure, both on
    // its last day.
    await waitFor(() =>
      expect(screen.getByLabelText('15 January: 1 completed, 0 failed')).toBeTruthy(),
    );
    expect(screen.getByLabelText('Search run, 15 January: 1')).toBeTruthy();
    expect(screen.getByText('Searches run, last 30 days')).toBeTruthy();
    expect(screen.getByText('Failures by kind, last 14 days')).toBeTruthy();
  });

  it('keeps the counters when the series cannot be read', async () => {
    // Two requests, two fates: the charts failing must not take the counters
    // down with them, and must say so rather than leave headings over nothing.
    answer({
      '/health/series': () =>
        Promise.reject(new client.ApiError(500, 'The charts are unavailable (500).')),
    });

    render(<Health />, { wrapper });

    await waitFor(() =>
      expect(screen.getByText('The charts are unavailable (500).')).toBeTruthy(),
    );
    expect(screen.getByText('completed')).toBeTruthy();
    expect(screen.queryByText('Searches run, last 30 days')).toBeNull();
  });
});
```

- [ ] **Step 2: Run them and watch the two new ones fail**

Run: `cd mobile && npx jest "app/(app)/health"`
Expected: the four existing tests PASS, and the two new tests FAIL because
the labels and the banner aren't found.

- [ ] **Step 3: Wire the charts in**

In `mobile/app/(app)/health/index.tsx`, add the imports:

```tsx
import { useHealthSeries } from '@/api/hooks/useHealthSeries';
import { FailuresByKindChart } from '@/ui/charts/FailuresByKindChart';
import { ThroughputChart } from '@/ui/charts/ThroughputChart';
```

Inside the component, next to `const health = useHealth();`:

```tsx
  const series = useHealthSeries();
```

Between the closing `) : null}` of the `health.data` block and
`<SectionHeading title="Error log" />`, insert:

```tsx
        {/* Its own request, so the counters above render whether or not the
            charts' counts arrive - and a failure here says so rather than
            leaving two headings over nothing. */}
        {series.isError ? (
          <ErrorBanner
            message={series.error instanceof Error ? series.error.message : 'Charts unavailable.'}
          />
        ) : null}

        {series.isLoading ? <Skeleton height={200} className="mb-6" /> : null}

        {series.data ? (
          <>
            <SectionHeading
              title={`Searches run, last ${series.data.throughput.days.length} days`}
            />
            <ThroughputChart
              days={series.data.throughput.days}
              completed={series.data.throughput.completed}
              failed={series.data.throughput.failed}
            />

            <SectionHeading
              title={`Failures by kind, last ${series.data.errors_by_context.days.length} days`}
            />
            <FailuresByKindChart
              days={series.data.errors_by_context.days}
              series={series.data.errors_by_context.series}
            />
          </>
        ) : null}
```

The headings take their day counts from the response, so they can't
disagree with the server's windows.

- [ ] **Step 4: Run them and watch them pass**

Run: `cd mobile && npx jest "app/(app)/health" && npm run typecheck && npm run lint`
Expected: PASS, 6 tests.

If `getByLabelText('15 January: 1 completed, 0 failed')` doesn't match, open
`src/api/__fixtures__/health-series.json`. The fixture is the truth, so
correct the label in the test to match the fixture's last day and counts.
Don't change the fixture.

- [ ] **Step 5: Commit**

```bash
cd mobile
git add "app/(app)/health/index.tsx" "app/(app)/health/__tests__/health.test.tsx"
git commit -m "feat(mobile): show searches run and failures by kind on Health"
```

---

### Task 8: Make and model suggestions

**Files:**
- Create: `mobile/src/ui/Suggestions.tsx`
- Create: `mobile/src/ui/__tests__/Suggestions.test.tsx`
- Modify: `mobile/app/(app)/search/index.tsx`
- Modify: `mobile/app/(app)/search/__tests__/search.test.tsx`

**Interfaces:**
- Consumes: `useMakes()` and `CarMake` (Task 4).
- Produces: `suggest(options: readonly string[], typed: string): string[]` and
  `<Suggestions items onPick />`. Each row is a `Pressable` with
  `accessibilityRole="button"` and `accessibilityLabel={item}`.

- [ ] **Step 1: Write the failing component tests**

`mobile/src/ui/__tests__/Suggestions.test.tsx`:

```tsx
import { fireEvent, render, screen } from '@testing-library/react-native';

import { suggest, Suggestions } from '../Suggestions';

const MAKES = [
  'BMW',
  'Ford',
  'Honda',
  'Hyundai',
  'Kia',
  'Mercedes-Benz',
  'Nissan',
  'Tesla',
  'Toyota',
  'Volkswagen',
];

describe('suggest', () => {
  it('matches the start of a name, ignoring case', () => {
    expect(suggest(MAKES, 'h')).toEqual(['Honda', 'Hyundai']);
    expect(suggest(MAKES, 'TO')).toEqual(['Toyota']);
  });

  it('matches only the start, not anywhere in the name', () => {
    expect(suggest(MAKES, 'onda')).toEqual([]);
  });

  it('offers at most five', () => {
    expect(suggest(['Ma', 'Mb', 'Mc', 'Md', 'Me', 'Mf', 'Mg'], 'm')).toEqual([
      'Ma',
      'Mb',
      'Mc',
      'Md',
      'Me',
    ]);
  });

  it('offers nothing for an empty field', () => {
    // Five arbitrary makes under a box nobody has typed in yet are noise.
    expect(suggest(MAKES, '')).toEqual([]);
    expect(suggest(MAKES, '   ')).toEqual([]);
  });

  it('stops offering a name once the field holds it exactly', () => {
    // Which is what a tap leaves behind; the row would otherwise echo the
    // field back at the user.
    expect(suggest(MAKES, 'Kia')).toEqual([]);
    // A case-only difference still offers the catalogue's spelling.
    expect(suggest(MAKES, 'kia')).toEqual(['Kia']);
  });

  it('ignores spaces around what was typed', () => {
    expect(suggest(MAKES, ' tes')).toEqual(['Tesla']);
  });
});

describe('<Suggestions />', () => {
  it('renders nothing when there is nothing to suggest', () => {
    render(<Suggestions items={[]} onPick={jest.fn()} />);

    expect(screen.queryAllByRole('button')).toHaveLength(0);
  });

  it('reports the tapped suggestion', () => {
    const onPick = jest.fn();
    render(<Suggestions items={['Honda', 'Hyundai']} onPick={onPick} />);

    fireEvent.press(screen.getByLabelText('Hyundai'));

    expect(onPick).toHaveBeenCalledWith('Hyundai');
  });
});
```

- [ ] **Step 2: Run them and watch them fail**

Run: `cd mobile && npx jest src/ui/__tests__/Suggestions.test.tsx`
Expected: FAIL, `Cannot find module '../Suggestions'`.

- [ ] **Step 3: `Suggestions.tsx`**

```tsx
import { Pressable, Text, View } from 'react-native';

const LIMIT = 5;

/**
 * Up to five options that start with what has been typed, ignoring case.
 *
 * Nothing for an empty field, and never the exact text already in it - which
 * is what a tap leaves behind. A case-only difference is still offered, so
 * "kia" gets the catalogue's "Kia".
 */
export function suggest(options: readonly string[], typed: string): string[] {
  const text = typed.trim();
  const needle = text.toLowerCase();

  if (needle === '') return [];

  return options
    .filter((option) => option !== text && option.toLowerCase().startsWith(needle))
    .slice(0, LIMIT);
}

/**
 * Tappable rows under a field. They suggest and never constrain: every search
 * reaches Wikimedia, so a make outside the catalogue is as valid as one in it.
 */
export function Suggestions({
  items,
  onPick,
}: {
  items: string[];
  onPick: (item: string) => void;
}) {
  if (items.length === 0) return null;

  return (
    <View className="overflow-hidden rounded-control bg-surface-raised">
      {items.map((item, index) => (
        <Pressable
          key={item}
          accessibilityRole="button"
          accessibilityLabel={item}
          accessibilityHint="Fills the field"
          onPress={() => onPick(item)}
          // 44pt tall: the smallest comfortable touch target.
          className={`min-h-[44px] justify-center px-4 active:opacity-80 ${
            index > 0 ? 'border-t border-border' : ''
          }`}
        >
          <Text className="text-body text-text">{item}</Text>
        </Pressable>
      ))}
    </View>
  );
}
```

Run: `cd mobile && npx jest src/ui/__tests__/Suggestions.test.tsx`
Expected: PASS.

- [ ] **Step 4: Write the failing form tests**

In `mobile/app/(app)/search/__tests__/search.test.tsx`:

1. Change the first import line to bring in `ScrollView`, and add the
   fixture import:

```tsx
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import type { ReactNode } from 'react';
import { ScrollView } from 'react-native';

import makesFixture from '@/api/__fixtures__/makes.json';
import searchFixture from '@/api/__fixtures__/search-create.json';
```

2. Delete `beforeEach(() => jest.restoreAllMocks());` from inside
   `describe('<SearchForm /> cap enforcement', ...)`, and add this at the top
   level, above that `describe`:

```tsx
beforeEach(() => {
  jest.restoreAllMocks();
  // The form fetches the make catalogue on mount. Answered here so no test
  // reaches for a real network, and so suggestions have something to show.
  // apiRequest, not apiRequestRaw: the cap tests' "did not call the API"
  // assertions watch apiRequestRaw, which only the submit uses.
  jest.spyOn(client, 'apiRequest').mockResolvedValue(makesFixture);
});
```

3. Append a new `describe` at the end of the file:

```tsx
describe('<SearchForm /> suggestions', () => {
  it('suggests makes from what has been typed, and fills the field when one is tapped', async () => {
    render(<SearchForm />, { wrapper });

    fireEvent.changeText(screen.getByLabelText('Make'), 'ho');
    await waitFor(() => expect(screen.getByLabelText('Honda')).toBeTruthy());

    fireEvent.press(screen.getByLabelText('Honda'));

    expect(screen.getByLabelText('Make').props.value).toBe('Honda');
    expect(screen.queryByLabelText('Honda')).toBeNull();
  });

  it("suggests the chosen make's models, whatever case the make was typed in", async () => {
    render(<SearchForm />, { wrapper });

    fireEvent.changeText(screen.getByLabelText('Make'), 'honda');
    fireEvent.changeText(screen.getByLabelText('Model'), 'c');

    await waitFor(() => expect(screen.getByLabelText('Civic')).toBeTruthy());
    expect(screen.getByLabelText('CR-V')).toBeTruthy();
  });

  it('offers no models for a make outside the catalogue', async () => {
    render(<SearchForm />, { wrapper });

    // Wait for the catalogue to land first, or "no suggestions" proves nothing.
    fireEvent.changeText(screen.getByLabelText('Make'), 'ho');
    await waitFor(() => expect(screen.getByLabelText('Honda')).toBeTruthy());

    fireEvent.changeText(screen.getByLabelText('Make'), 'Lada');
    fireEvent.changeText(screen.getByLabelText('Model'), 'c');

    expect(screen.queryByLabelText('Civic')).toBeNull();
  });

  it('still submits a make the catalogue does not list', async () => {
    // Suggest, never constrain: every search reaches Wikimedia, and the
    // catalogue is ten makes.
    const request = jest
      .spyOn(client, 'apiRequestRaw')
      .mockResolvedValue({ status: 201, body: searchFixture });

    render(<SearchForm />, { wrapper });
    fillIn('Lada', '1997', '1999');

    await waitFor(() => expect(request).toHaveBeenCalledTimes(1));
    expect(request.mock.calls[0]?.[1]).toMatchObject({ body: { make: 'Lada' } });
  });

  it('works as plain text when the catalogue cannot be read', async () => {
    jest
      .spyOn(client, 'apiRequest')
      .mockRejectedValue(new client.ApiError(403, 'This action is unauthorized.'));
    const request = jest
      .spyOn(client, 'apiRequestRaw')
      .mockResolvedValue({ status: 201, body: searchFixture });

    render(<SearchForm />, { wrapper });
    fillIn('Toyota', '1997', '1999');

    await waitFor(() => expect(request).toHaveBeenCalledTimes(1));
    // Suggestions are a convenience; their failure is not the user's problem.
    expect(screen.queryByText('This action is unauthorized.')).toBeNull();
  });

  it('lets a tap on a suggestion through while the keyboard is up', async () => {
    // With the default ("never"), the first tap only dismisses the keyboard
    // and the user has to tap the suggestion a second time.
    render(<SearchForm />, { wrapper });

    fireEvent.changeText(screen.getByLabelText('Make'), 'ho');
    await waitFor(() => expect(screen.getByLabelText('Honda')).toBeTruthy());

    expect(screen.UNSAFE_getByType(ScrollView).props.keyboardShouldPersistTaps).toBe('handled');
  });
});
```

- [ ] **Step 5: Run them and watch the new ones fail**

Run: `cd mobile && npx jest "app/(app)/search"`
Expected: the two cap tests PASS. Of the six new tests, the two submission
tests may already pass, because the form submits today. The four that check
suggestions and the `ScrollView` prop FAIL.

- [ ] **Step 6: Wire the suggestions into the form**

In `mobile/app/(app)/search/index.tsx`, add these imports:

```tsx
import { useMakes } from '@/api/hooks/useMakes';
import { suggest, Suggestions } from '@/ui/Suggestions';
```

After `const createSearch = useCreateSearch();`:

```tsx
  // Suggestions only. A failed or empty catalogue leaves both fields as plain
  // text that still submits, so its error is deliberately not shown.
  const makes = useMakes();
```

After the `useState` declarations and before `submit`:

```tsx
  const catalogue = makes.data ?? [];
  // Matched ignoring case, so "honda" still offers Honda's models.
  const chosenMake = catalogue.find(
    (entry) => entry.name.toLowerCase() === make.trim().toLowerCase(),
  );
```

Replace the `ScrollView` opening tag:

```tsx
      {/* keyboardShouldPersistTaps: with the default, the first tap on a
          suggestion only dismisses the keyboard and the user taps twice. */}
      <ScrollView contentContainerClassName="gap-3" keyboardShouldPersistTaps="handled">
```

Replace the Make and Model fields:

```tsx
        <View className="gap-1">
          <Field label="Make" placeholder="e.g. Toyota" value={make} onChangeText={setMake} />
          <Suggestions
            items={suggest(
              catalogue.map((entry) => entry.name),
              make,
            )}
            onPick={setMake}
          />
        </View>
        <View className="gap-1">
          <Field
            label="Model"
            hint="Optional. Narrows the search to one model."
            value={model}
            onChangeText={setModel}
          />
          <Suggestions items={suggest(chosenMake?.models ?? [], model)} onPick={setModel} />
        </View>
```

- [ ] **Step 7: Run them and watch them pass**

Run: `cd mobile && npx jest "app/(app)/search" src/ui && npm run typecheck && npm run lint`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
cd mobile
git add src/ui/Suggestions.tsx src/ui/__tests__/Suggestions.test.tsx "app/(app)/search/index.tsx" "app/(app)/search/__tests__/search.test.tsx"
git commit -m "feat(mobile): suggest makes and models without constraining the search"
```

---

### Task 9: Look at it, then verify everything

The colour validator checked colour, not layout. Following step 7 of the
dataviz skill, render the screen and look at it before calling the charts
done.

**Files:** none committed, unless the screenshot finds a defect. Any fix goes
back to the owning task's component, with a test if it is behavioural.

- [ ] **Step 1: Build the web app against a stubbed API, and serve it**

```bash
cd mobile
EXPO_PUBLIC_API_URL=http://127.0.0.1:8999 npx expo export -p web
(npx --yes serve@14 -l 8124 dist >/dev/null 2>&1 &)
```

Nothing listens on port 8999. Playwright answers every request to it.

- [ ] **Step 2: Stub the API in Playwright and open Health at phone width**

Use the Playwright MCP tools. First run `browser_resize` with 390 × 844, then
`browser_run_code_unsafe` with:

```js
async (page) => {
  const cors = {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Headers': '*',
    'Access-Control-Allow-Methods': 'GET, POST, PATCH, OPTIONS',
  };
  const days = (n) =>
    Array.from({ length: n }, (_, i) =>
      new Date(Date.UTC(2026, 8, 15 - (n - 1 - i))).toISOString().slice(0, 10),
    );
  const t = days(30);
  const e = days(14);
  const responses = {
    '/api/v1/auth/me': { data: { id: 1, name: 'Fixture User', email: 'fixtures@example.test' } },
    '/api/v1/health/summary': {
      data: {
        searches_by_status: { pending: 3, running: 0, completed: 212, failed: 9 },
        errors_last_24h: 4,
        errors_by_context_last_7d: { csv_upload: 0, csv_row: 6, search_run: 5, image_download: 0, wikimedia_block: 7 },
        images_last_7d: 640,
        latest_error_at: '2026-09-15T09:00:00+00:00',
      },
    },
    '/api/v1/health/series': {
      data: {
        throughput: {
          days: t,
          // A quiet day every week, and one heavy day, to stress the scale.
          completed: t.map((_, i) => (i % 7 === 3 ? 0 : i === 20 ? 48 : 3 + ((i * 5) % 11))),
          failed: t.map((_, i) => (i % 5 === 0 ? 2 : i % 9 === 0 ? 1 : 0)),
        },
        errors_by_context: {
          days: e,
          series: {
            csv_row: e.map((_, i) => (i % 4 === 0 ? 3 : 0)),
            search_run: e.map((_, i) => i % 3),
            wikimedia_block: e.map((_, i) => (i === 11 ? 7 : 0)),
          },
        },
      },
    },
    '/api/v1/errors': {
      data: [],
      links: { first: null, last: null, prev: null, next: null },
      meta: { path: 'http://127.0.0.1:8999/api/v1/errors', per_page: 24, next_cursor: null, prev_cursor: null },
    },
    '/api/v1/makes': {
      data: [
        { name: 'Honda', models: ['Accord', 'Civic', 'CR-V', 'Jazz'] },
        { name: 'Hyundai', models: ['Elantra', 'Santa Fe', 'Tucson'] },
      ],
    },
  };
  await page.route('http://127.0.0.1:8999/**', (route) => {
    if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors });
    const body = responses[new URL(route.request().url()).pathname];
    return route.fulfill({
      status: body ? 200 : 404,
      headers: { ...cors, 'Content-Type': 'application/json' },
      body: JSON.stringify(body ?? { message: 'Not stubbed.' }),
    });
  });
  await page.addInitScript(() => localStorage.setItem('cars-images.token', 'visual-check'));
  await page.goto('http://localhost:8124/health');
}
```

- [ ] **Step 3: Screenshot the charts, and check each point below**

Take a full-page `browser_take_screenshot`, then check it against this list:

- all 30 throughput bars fit at 390px wide, with visible gaps between them,
  and nothing scrolls sideways;
- the y-axis labels (`50`, `0`) don't collide with the bars or get clipped;
  the x-axis labels (`17 Aug` and `15 Sep`) sit under the first and last bars;
- the heavy day reaches the top hairline without poking above it, and the
  2px gap between completed and failed segments is visible;
- each small multiple's title row ("Wikimedia block" against "7 in 14 days")
  fits on one line;
- the legend and the totals line don't wrap awkwardly.

Then `browser_click` a throughput bar and a small-multiple bar, and take a
screenshot of each callout. Confirm the tapped bar stays bright while the
others dim.

- [ ] **Step 4: Screenshot the typeahead**

Navigate to `http://localhost:8124/search`, type `h` into Make, and take a
screenshot. Two rows should appear, Honda and Hyundai, directly under the
field. Tap Honda, type `c` into Model, and take another screenshot: Civic and
CR-V should appear under the Model hint.

- [ ] **Step 5: Fix what the screenshots show**

If any check fails, fix the component, rerun its tests, and take the
screenshot again. Record each fix in the report, along with what it fixed.
Stop the server afterwards with `pkill -f 'serve@14 -l 8124'`.

- [ ] **Step 6: Both stacks, in full**

```bash
vendor/bin/pint --test
docker run --rm -v "$PWD":/app -w /app cars-ci-php:8.3 php artisan test
cd mobile && npm run typecheck && npm run lint && npm test && npm run build:web && npm run check:redirects
```

Running `npm run build:web` also overwrites the stubbed build from Step 1.

- [ ] **Step 7: The guarantees held**

```bash
git diff --stat main -- tests/Feature/Filament/Widgets          # expect empty
git diff --stat main -- mobile/src/api/__fixtures__             # expect only health-series.json and makes.json, both added
git diff main -- mobile/package.json package.json               # expect empty
git ls-files app/Filament/Widgets/Concerns                      # expect empty
```

- [ ] **Step 8: Report** which commands passed, with their output, and attach
  the screenshots.

---

## Self-Review

**Spec coverage.**
- Extracting `PipelineSeries` with no colour, the widgets keeping their
  Chart.js shape, and the SQL-counting rationale moving with the code →
  Task 1.
- `GET /health/series`: `errors:read`, every day listed, only failing
  contexts → Task 2.
- `GET /makes`: `search:read`, the sorting, the empty table returning no
  fallback → Task 3.
- The deploy note about the seeder → Task 3, Step 6.
- The long cache on makes → Task 4.
- 30 stacked status bars; `success` and `danger`; the legend; the 2px gap;
  4px data-ends; recessive axes; the tap callout; `accessibilityLabel` on
  every bar; tap targets wider than the bar → Task 5.
- Small multiples on one shared scale; slot-one blue `#3987e5`; no legend box;
  titles from `ErrorEvent::contexts()`; a line of text when nothing failed →
  Task 6.
- The charts placed below the existing counters → Task 7.
- Case-insensitive prefix matching capped at five, a tap filling the field,
  models from the chosen make, nothing for an unknown make, and an unknown
  make still submitting → Task 8.
- Rendering the screen and looking at it (step 7 of the dataviz skill) →
  Task 9.

**Three problems found in the code that the spec doesn't mention.**
1. **PHP encodes an empty `series` as `[]`.** The client contract would reject
   it, and both charts would fail on the days with no failures. Task 2 casts
   `series` to an object and pins the cast with a test; Task 4 pins why the
   cast matters.
2. **Zod 4's `z.record(enum, …)` is exhaustive.** Keying `series` by
   `ErrorContextSchema`, as `HealthSummarySchema` keys its own map, would
   demand all five contexts, and `series` holds only the ones that failed.
   Task 4 keys it by `z.string()` instead, which also keeps a new server-side
   context from blanking the screen.
3. **The existing Health tests answer every request that isn't the summary
   with the error log.** Once the screen also asks for `/health/series`, the
   charts would receive an array of events. Task 7 rewrites the test file to
   answer by path and to reject unknown paths loudly, keeping every existing
   assertion.

**One deliberate departure from the spec.** The spec's example for
`GET /health/series` shows no `data` envelope, while its `GET /makes` example
does have one. Every endpoint except login wraps its payload, and the client's
`single()` helper expects that, so the series is wrapped too.

**Type consistency.**
- `PipelineSeries::throughput()`, `errorsByContext()` and `build()` are
  declared in Task 1; the widgets use them in Task 1 and the controller in
  Task 2.
- `HealthSeries`, `CarMake` and `MakesSchema` are declared in Task 4 and used
  in Tasks 7 and 8.
- `Segment`, `DailyBars`, `niceCeiling` and `dayLong` are declared in Task 5
  and used in Task 6.
- `contextLabel` is declared in Task 6 and used by the Health screen and the
  chart.
- The bar labels `"<day>: N completed, M failed"` and
  `"<Kind>, <day>: N"` are produced in Tasks 5 and 6 and queried in Task 7.
- `suggest` and `Suggestions` are declared and used in Task 8.

**Review Focus.** Every item has a test in the task that owns it: item 1 in
Task 8; item 2 in Tasks 2 and 4; item 3 in Tasks 1, 4 and 6; item 4 in
Task 8; item 5 in Task 7.

**Placeholder scan.** Clean. The `// … unchanged` comments in Task 1, Step 5
mark existing methods that stay where they are, and the step says not to
write those comments into the file.
