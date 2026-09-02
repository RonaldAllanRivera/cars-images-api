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

/**
 * The bulk run drives itself.
 *
 * "Run Selected" used to process one chunk per click, because there is no
 * queue worker and a single request cannot outlast max_execution_time. That
 * held, but it meant four clicks for 58 rows with no idea how long each would
 * take. The click now only seeds a queue; the browser polls `runNextChunk`,
 * and each poll is its own bounded request — so the execution-time protection
 * is unchanged while the admin stops being the scheduler.
 */
class BulkRunProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The 1s courtesy pause between queries is real etiquette in
        // production and pure latency in a test.
        config()->set('cars-images.bulk_run_sleep_seconds_between_queries', 0);
    }

    /**
     * @return array<int, CarSearch>
     */
    private function pendingSearches(User $user, int $count): array
    {
        $csvImport = CsvImport::create([
            'original_filename' => 'sample.csv',
            'total_rows' => $count, 'unique_combos' => $count, 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        return array_map(fn (int $i) => CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 1997 + $i, 'to_year' => 1997 + $i,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'pending', 'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]), range(0, $count - 1));
    }

    private function fakeEmptyCommons(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => []]], 200)]);
    }

    public function test_the_click_only_seeds_the_queue_and_runs_nothing_yet(): void
    {
        // The seeding request must return immediately. If it processed even one
        // search it would be doing the browser's job, and a large selection
        // would push the click itself back towards max_execution_time.
        $this->fakeEmptyCommons();

        $user = User::factory()->create();
        $searches = $this->pendingSearches($user, 3);

        Livewire::actingAs($user)
            ->test(ListSearchQueries::class)
            ->callTableBulkAction('runSelected', $searches)
            ->assertSet('runActive', true)
            ->assertSet('runTotal', 3)
            ->assertSet('runProcessed', 0);

        foreach ($searches as $search) {
            $this->assertSame('pending', $search->fresh()->status);
        }

        Http::assertNothingSent();
    }

    public function test_each_poll_drains_a_chunk_and_the_last_one_announces_the_finish(): void
    {
        $this->fakeEmptyCommons();

        $user = User::factory()->create();
        $searches = $this->pendingSearches($user, 3);

        $component = Livewire::actingAs($user)
            ->test(ListSearchQueries::class)
            ->callTableBulkAction('runSelected', $searches);

        // One chunk is bounded by seconds and by query count; with the pause
        // disabled these three fit in one, so a single poll finishes the run.
        $component->call('runNextChunk')
            ->assertSet('runActive', false)
            ->assertSet('runProcessed', 3)
            ->assertDispatched('bulk-run-finished', status: 'finished', processed: 3);

        foreach ($searches as $search) {
            $this->assertSame('completed', $search->fresh()->status);
        }
    }

    public function test_a_chunk_stops_at_the_query_cap_and_the_run_continues_on_the_next_poll(): void
    {
        // Proves the loop is genuinely resumable rather than finishing in one
        // pass because the test data is small.
        config()->set('cars-images.bulk_run_max_queries_per_chunk', 2);
        $this->fakeEmptyCommons();

        $user = User::factory()->create();
        $searches = $this->pendingSearches($user, 5);

        $component = Livewire::actingAs($user)
            ->test(ListSearchQueries::class)
            ->callTableBulkAction('runSelected', $searches);

        $component->call('runNextChunk')
            ->assertSet('runProcessed', 2)
            ->assertSet('runActive', true)
            ->assertNotDispatched('bulk-run-finished');

        $component->call('runNextChunk')->assertSet('runProcessed', 4);
        $component->call('runNextChunk')
            ->assertSet('runProcessed', 5)
            ->assertSet('runActive', false)
            ->assertDispatched('bulk-run-finished');
    }

    public function test_a_wikimedia_block_halts_the_run_and_says_so(): void
    {
        // A block must stop the poll, not let it hammer a rate-limited API once
        // a second until the queue drains.
        Http::fake(['*' => Http::response('Too Many Requests', 429)]);

        $user = User::factory()->create();
        $searches = $this->pendingSearches($user, 3);

        Livewire::actingAs($user)
            ->test(ListSearchQueries::class)
            ->callTableBulkAction('runSelected', $searches)
            ->call('runNextChunk')
            ->assertSet('runActive', false)
            ->assertDispatched('bulk-run-finished', status: 'blocked')
            ->assertNotSet('runBlockMessage', null);
    }

    public function test_pausing_stops_the_run_but_keeps_the_queue(): void
    {
        config()->set('cars-images.bulk_run_max_queries_per_chunk', 1);
        $this->fakeEmptyCommons();

        $user = User::factory()->create();
        $searches = $this->pendingSearches($user, 4);

        $component = Livewire::actingAs($user)
            ->test(ListSearchQueries::class)
            ->callTableBulkAction('runSelected', $searches)
            ->call('runNextChunk')
            ->call('pauseBulkRun')
            ->assertSet('runActive', false)
            ->assertSet('runProcessed', 1);

        // A paused run must not advance even if a poll slips through.
        $component->call('runNextChunk')->assertSet('runProcessed', 1);

        $component->call('resumeBulkRun')->assertSet('runActive', true);
    }

    public function test_already_completed_rows_are_skipped_without_being_re_run(): void
    {
        $this->fakeEmptyCommons();

        $user = User::factory()->create();
        $searches = $this->pendingSearches($user, 3);
        $searches[1]->forceFill(['status' => 'completed'])->save();

        Livewire::actingAs($user)
            ->test(ListSearchQueries::class)
            ->callTableBulkAction('runSelected', $searches)
            ->call('runNextChunk')
            ->assertSet('runProcessed', 2)
            ->assertSet('runActive', false);
    }

    public function test_the_progress_panel_is_rendered_while_a_run_is_active(): void
    {
        // Wiring guard. Every assertion above can pass while the panel is never
        // rendered, leaving the run with no progress bar and - worse - no
        // poll to advance it, so it would seed and then sit there forever.
        $this->fakeEmptyCommons();

        $user = User::factory()->create();
        $searches = $this->pendingSearches($user, 3);
        config()->set('cars-images.bulk_run_max_queries_per_chunk', 1);

        Livewire::actingAs($user)
            ->test(ListSearchQueries::class)
            ->callTableBulkAction('runSelected', $searches)
            ->assertSee('runNextChunk', escape: false)
            ->assertSee('wire:poll', escape: false);
    }

    public function test_no_panel_and_no_poll_before_a_run_starts(): void
    {
        // The inverse matters just as much: an always-on poll would fire a
        // request every second on a page nobody is running anything from.
        Livewire::actingAs(User::factory()->create())
            ->test(ListSearchQueries::class)
            ->assertDontSee('runNextChunk', escape: false);
    }
}
