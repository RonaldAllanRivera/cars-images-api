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
