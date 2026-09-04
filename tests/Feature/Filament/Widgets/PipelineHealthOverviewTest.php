<?php

namespace Tests\Feature\Filament\Widgets;

use App\Filament\Widgets\PipelineHealthOverview;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\ErrorEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The four numbers an operator wants before deciding whether to open anything.
 *
 * Each is deliberately scoped to a window: "is the pipeline broken now" is a
 * different question from "has it ever broken", and only the first belongs on
 * a landing page.
 */
class PipelineHealthOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-04 12:00:00');
    }

    private function error(string $context, string $occurredAt): ErrorEvent
    {
        return ErrorEvent::create([
            'context' => $context,
            'severity' => 'error',
            'message' => 'something broke',
            'occurred_at' => $occurredAt,
        ]);
    }

    private function search(User $user, string $status, string $createdAt): CarSearch
    {
        $search = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 2019, 'to_year' => 2019,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => $status, 'requested_by' => $user->id,
        ]);

        $search->forceFill(['created_at' => $createdAt])->save();

        return $search;
    }

    public function test_it_counts_only_the_errors_from_the_last_24_hours(): void
    {
        $user = User::factory()->create();

        $this->error(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-04 11:00:00');
        $this->error(ErrorEvent::CONTEXT_CSV_ROW, '2026-09-03 13:00:00');
        // 25 hours old: yesterday's noise, not today's problem.
        $this->error(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-03 11:00:00');

        $this->assertSame(2, Livewire::actingAs($user)
            ->test(PipelineHealthOverview::class)
            ->instance()
            ->recentErrorCount());
    }

    public function test_it_counts_searches_that_failed_in_the_last_seven_days(): void
    {
        $user = User::factory()->create();

        $this->search($user, 'failed', '2026-09-02 09:00:00');
        $this->search($user, 'failed', '2026-08-20 09:00:00');
        $this->search($user, 'completed', '2026-09-02 09:00:00');

        $this->assertSame(1, Livewire::actingAs($user)
            ->test(PipelineHealthOverview::class)
            ->instance()
            ->failedSearchCount());
    }

    public function test_it_describes_the_latest_import_by_the_errors_linked_to_it(): void
    {
        $user = User::factory()->create();

        $clean = CsvImport::create([
            'original_filename' => 'clean.csv',
            'total_rows' => 3, 'unique_combos' => 3, 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);
        $broken = CsvImport::create([
            'original_filename' => 'broken.csv',
            'total_rows' => 3, 'unique_combos' => 3, 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        $this->error(ErrorEvent::CONTEXT_CSV_ROW, '2026-09-04 11:00:00')
            ->update(['csv_import_id' => $broken->id]);

        $widget = Livewire::actingAs($user)->test(PipelineHealthOverview::class)->instance();

        $this->assertSame('broken.csv', $widget->latestImportLabel());
        $this->assertSame('1 error', $widget->latestImportDescription());
        $this->assertNotNull($clean->id);
    }

    public function test_it_renders_on_an_empty_database(): void
    {
        // A fresh install has no imports, no searches and no errors. The
        // landing page must still render rather than fail on a null import.
        $user = User::factory()->create();

        $widget = Livewire::actingAs($user)->test(PipelineHealthOverview::class);

        $widget->assertOk();
        $this->assertSame('None yet', $widget->instance()->latestImportLabel());
    }
}
