<?php

namespace Tests\Feature\Services\Search;

use App\Models\User;
use App\Services\Search\RunChunkAction;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\ApiTestCase;

class RunChunkActionTest extends ApiTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        // Pacing is real time; a suite that honoured it would take minutes.
        config()->set('cars-images.bulk_run_sleep_seconds_between_queries', 0);
    }

    /** Commons returning nothing: a search completes having found no images. */
    private function fakeEmptyCommons(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => []]], 200)]);
    }

    public function test_it_runs_the_imports_pending_searches(): void
    {
        $this->fakeEmptyCommons();
        $import = $this->csvImport($this->user);
        $a = $this->search($this->user, ['csv_import_id' => $import->id, 'status' => 'pending']);
        $b = $this->search($this->user, ['csv_import_id' => $import->id, 'status' => 'pending']);

        $result = app(RunChunkAction::class)->execute($import->id);

        $this->assertCount(2, $result->outcomes);
        $this->assertSame(0, $result->remaining);
        $this->assertSame('completed', $a->refresh()->status);
        $this->assertSame('completed', $b->refresh()->status);
    }

    public function test_it_stops_at_the_chunk_size_and_reports_what_is_left(): void
    {
        $this->fakeEmptyCommons();
        config()->set('cars-images.bulk_run_max_queries_per_chunk', 1);
        $import = $this->csvImport($this->user);
        $this->search($this->user, ['csv_import_id' => $import->id, 'status' => 'pending']);
        $this->search($this->user, ['csv_import_id' => $import->id, 'status' => 'pending']);

        $result = app(RunChunkAction::class)->execute($import->id);

        $this->assertCount(1, $result->outcomes);
        // Counted after the slice, so the client's figure is never inferred.
        $this->assertSame(1, $result->remaining);
    }

    public function test_it_names_the_car_in_each_outcome(): void
    {
        // A failure that reads "1998 Honda Civic - failed" is actionable; one
        // that reads "id 42" sends the reader back to a list to look it up.
        $this->fakeEmptyCommons();
        $import = $this->csvImport($this->user);
        $this->search($this->user, [
            'csv_import_id' => $import->id,
            'status' => 'pending',
            'make' => 'Honda',
            'model' => 'Civic',
            'from_year' => 1998,
        ]);

        $outcome = app(RunChunkAction::class)->execute($import->id)->outcomes[0];

        $this->assertSame('Honda', $outcome['make']);
        $this->assertSame('Civic', $outcome['model']);
        $this->assertSame(1998, $outcome['from_year']);
    }

    public function test_it_includes_failed_searches_as_runnable(): void
    {
        // Matches runnableSearchIds(): a failed row is work still to do, and
        // the panel's "Run all pending" picks it up too.
        $this->fakeEmptyCommons();
        $import = $this->csvImport($this->user);
        $failed = $this->search($this->user, ['csv_import_id' => $import->id, 'status' => 'failed']);

        app(RunChunkAction::class)->execute($import->id);

        $this->assertSame('completed', $failed->refresh()->status);
    }

    public function test_it_leaves_completed_searches_alone(): void
    {
        $this->fakeEmptyCommons();
        $import = $this->csvImport($this->user);
        $done = $this->search($this->user, ['csv_import_id' => $import->id, 'status' => 'completed']);

        $result = app(RunChunkAction::class)->execute($import->id);

        $this->assertSame([], $result->outcomes);
        $this->assertSame(0, $result->remaining);
        $this->assertSame('completed', $done->refresh()->status);
    }

    public function test_a_single_failure_does_not_abandon_the_rest(): void
    {
        // One bad query is already marked `failed` and can be re-run; stopping
        // the chunk for it would strand everything behind it.
        $import = $this->csvImport($this->user);
        $bad = $this->search($this->user, ['csv_import_id' => $import->id, 'status' => 'pending', 'make' => 'Bad']);
        $good = $this->search($this->user, ['csv_import_id' => $import->id, 'status' => 'pending', 'make' => 'Good']);

        Http::fake(function ($request) {
            return str_contains(urldecode((string) $request->url()), 'Bad')
                ? Http::response('kaboom', 500)
                : Http::response(['query' => ['pages' => []]], 200);
        });

        $result = app(RunChunkAction::class)->execute($import->id);

        $this->assertCount(2, $result->outcomes);
        $this->assertSame('failed', $bad->refresh()->status);
        $this->assertSame('completed', $good->refresh()->status);
        $this->assertNull($result->blocked);
    }

    public function test_a_wikimedia_block_halts_the_chunk_and_reports_the_window(): void
    {
        // Not a per-query failure: a block means stop asking. The block event
        // and error event are already written by RunSearchQueryAction.
        Http::fake(['*' => Http::response('Too Many Requests', 429, ['Retry-After' => '600'])]);
        $import = $this->csvImport($this->user);
        $this->search($this->user, ['csv_import_id' => $import->id, 'status' => 'pending']);
        $this->search($this->user, ['csv_import_id' => $import->id, 'status' => 'pending']);

        $result = app(RunChunkAction::class)->execute($import->id);

        $this->assertNotNull($result->blocked);
        $this->assertSame(429, $result->blocked['status']);
        $this->assertSame(600, $result->blocked['retry_after_seconds']);
        // The second search was never attempted.
        $this->assertLessThan(2, count($result->outcomes));
        $this->assertDatabaseCount('wikimedia_block_events', 1);
    }

    public function test_an_empty_queue_is_not_an_error(): void
    {
        // Asking a finished run to continue is how the client's loop
        // terminates; a 404 or a throw here would turn that into a crash.
        $import = $this->csvImport($this->user);

        $result = app(RunChunkAction::class)->execute($import->id);

        $this->assertSame([], $result->outcomes);
        $this->assertSame(0, $result->remaining);
        $this->assertNull($result->blocked);
    }

    public function test_it_ignores_searches_from_other_imports(): void
    {
        $this->fakeEmptyCommons();
        $mine = $this->csvImport($this->user);
        $theirs = $this->csvImport($this->user);
        $notMine = $this->search($this->user, ['csv_import_id' => $theirs->id, 'status' => 'pending']);

        app(RunChunkAction::class)->execute($mine->id);

        $this->assertSame('pending', $notMine->refresh()->status);
    }
}
