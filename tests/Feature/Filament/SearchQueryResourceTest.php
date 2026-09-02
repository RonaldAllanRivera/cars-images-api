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
}
