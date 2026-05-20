<?php

namespace Tests\Feature\Services\Search;

use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use App\Services\Images\CarImageSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CsvSearchQueryConstructionTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEmptyWikimedia(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['pages' => []]], 200),
        ]);
    }

    public function test_csv_imported_search_excludes_transmission_from_query(): void
    {
        $this->fakeEmptyWikimedia();

        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 'test.csv',
            'total_rows' => 1, 'unique_combos' => 1, 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        $search = CarSearch::create([
            'make' => 'Acura', 'model' => 'CL',
            'from_year' => 1997, 'to_year' => 1997,
            'color' => null, 'transmission' => 'Manual 5-spd',
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'pending', 'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]);

        app(CarImageSearchService::class)->runSearch($search);

        Http::assertSent(function ($request) {
            $query = $request->data()['gsrsearch'] ?? '';

            return ! str_contains($query, 'Manual 5-spd')
                && ! str_contains($query, 'Manual')
                && ! str_contains($query, '5-spd');
        });
    }

    public function test_messy_model_string_is_normalized_in_query(): void
    {
        $this->fakeEmptyWikimedia();

        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 'test.csv',
            'total_rows' => 1, 'unique_combos' => 1, 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        $search = CarSearch::create([
            'make' => 'Acura', 'model' => '2.2CL/3.0CL',
            'from_year' => 1997, 'to_year' => 1997,
            'color' => null, 'transmission' => null,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'pending', 'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]);

        app(CarImageSearchService::class)->runSearch($search);

        Http::assertSent(function ($request) {
            $query = $request->data()['gsrsearch'] ?? '';

            return str_contains($query, 'Acura CL 1997')
                && ! str_contains($query, '2.2CL');
        });
    }

    public function test_ad_hoc_search_still_includes_transmission_in_query(): void
    {
        $this->fakeEmptyWikimedia();

        $user = User::factory()->create();

        $search = CarSearch::create([
            'make' => 'Toyota', 'model' => 'Camry',
            'from_year' => 2020, 'to_year' => 2020,
            'color' => null, 'transmission' => 'automatic',
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'pending', 'requested_by' => $user->id,
        ]);

        app(CarImageSearchService::class)->runSearch($search);

        Http::assertSent(function ($request) {
            $query = $request->data()['gsrsearch'] ?? '';

            return str_contains($query, 'automatic');
        });
    }
}
