<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\SearchQueryResource\Pages\ListSearchQueries;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Filament\Notifications\Notification;
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

    /**
     * @param  array<int, string>  $statuses
     * @return array<int, CarSearch>
     */
    private function importedSearches(User $user, array $statuses): array
    {
        $csvImport = CsvImport::create([
            'original_filename' => 'sample.csv',
            'total_rows' => count($statuses), 'unique_combos' => count($statuses), 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        return array_map(fn (string $status, int $i) => CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 1997 + $i, 'to_year' => 1997 + $i,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => $status, 'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]), $statuses, array_keys($statuses));
    }

    public function test_a_finished_bulk_run_announces_itself_to_a_backgrounded_tab(): void
    {
        // A chunk takes up to 50 seconds, so the admin switches tabs and misses
        // the toast, which fades on its own. The action dispatches a browser
        // event that the panel's script turns into a tab-title change and an OS
        // notification - both of which are visible without the tab in focus.
        Http::fake(['*' => Http::response(['query' => ['pages' => []]], 200)]);

        $user = User::factory()->create();
        $searches = $this->importedSearches($user, ['pending', 'pending']);

        Livewire::actingAs($user)
            ->test(ListSearchQueries::class)
            ->callTableBulkAction('runSelected', $searches)
            ->assertNotified()
            ->assertDispatched('bulk-run-finished', status: 'finished', processed: 2);
    }

    public function test_a_blocked_bulk_run_announces_itself_too(): void
    {
        // A pause matters more than a completion: the run stopped early and
        // needs a decision. It must reach a backgrounded tab as well.
        Http::fake(['*' => Http::response('Too Many Requests', 429)]);

        $user = User::factory()->create();
        $searches = $this->importedSearches($user, ['pending']);

        Livewire::actingAs($user)
            ->test(ListSearchQueries::class)
            ->callTableBulkAction('runSelected', $searches)
            ->assertNotified()
            ->assertDispatched('bulk-run-finished', status: 'blocked');
    }

    public function test_the_panel_actually_serves_the_signal_script(): void
    {
        // Guards the wiring, not the behaviour. The action can dispatch
        // `bulk-run-finished` perfectly while the render hook that listens for
        // it is unregistered, and every other test here would still pass — the
        // feature would simply be dead in the browser.
        $this->actingAs(User::factory()->create())
            ->get('/admin/search-queries')
            ->assertOk()
            ->assertSee('bulk-run-finished', escape: false);
    }

    public function test_the_completion_notification_waits_to_be_read(): void
    {
        // The blocked notification was already persistent; the success one was
        // not, so the ordinary outcome was the only one that could vanish
        // before the admin came back to the tab.
        Http::fake(['*' => Http::response(['query' => ['pages' => []]], 200)]);

        $user = User::factory()->create();
        $searches = $this->importedSearches($user, ['pending']);

        Livewire::actingAs($user)
            ->test(ListSearchQueries::class)
            ->callTableBulkAction('runSelected', $searches)
            ->assertNotified(
                Notification::make()
                    ->title('Bulk run finished')
                    ->body("Processed 1 queries this chunk. Click 'Run Selected' again to continue.")
                    ->success()
                    ->persistent()
            );
    }
}
