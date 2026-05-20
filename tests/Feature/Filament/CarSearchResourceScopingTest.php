<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CarSearchResource\Pages\ListCarSearches;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CarSearchResourceScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_car_search_resource_hides_csv_imported_rows(): void
    {
        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 's.csv', 'total_rows' => 1,
            'unique_combos' => 1, 'duplicates_skipped' => 0, 'imported_by' => $user->id,
        ]);

        $adHoc = CarSearch::create([
            'make' => 'Honda', 'model' => 'Civic', 'from_year' => 2018, 'to_year' => 2022,
            'transparent_background' => false, 'images_per_year' => 10,
            'status' => 'pending', 'requested_by' => $user->id,
        ]);
        $imported = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4', 'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'pending', 'requested_by' => $user->id, 'csv_import_id' => $csvImport->id,
        ]);

        Livewire::actingAs($user)
            ->test(ListCarSearches::class)
            ->assertCanSeeTableRecords([$adHoc])
            ->assertCanNotSeeTableRecords([$imported]);
    }
}
