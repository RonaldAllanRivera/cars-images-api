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

    public function test_filters_to_single_search_when_searchId_param_present(): void
    {
        $user = \App\Models\User::factory()->create();
        $csvImport = \App\Models\CsvImport::create([
            'original_filename' => 's.csv', 'total_rows' => 2,
            'unique_combos' => 2, 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);
        $searchA = \App\Models\CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4', 'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id, 'csv_import_id' => $csvImport->id,
        ]);
        $searchB = \App\Models\CarSearch::create([
            'make' => 'Honda', 'model' => 'Civic', 'from_year' => 2010, 'to_year' => 2010,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id, 'csv_import_id' => $csvImport->id,
        ]);
        $imgA = \App\Models\CarImage::create([
            'car_search_id' => $searchA->id, 'provider' => 'wikimedia', 'provider_image_id' => 'A',
            'make' => 'Toyota', 'model' => 'RAV4', 'year' => 1997, 'title' => 'A',
            'source_url' => 'https://example.com/a.jpg', 'thumbnail_url' => 'https://example.com/a.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);
        $imgB = \App\Models\CarImage::create([
            'car_search_id' => $searchB->id, 'provider' => 'wikimedia', 'provider_image_id' => 'B',
            'make' => 'Honda', 'model' => 'Civic', 'year' => 2010, 'title' => 'B',
            'source_url' => 'https://example.com/b.jpg', 'thumbnail_url' => 'https://example.com/b.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);

        \Livewire\Livewire::actingAs($user)
            ->withQueryParams(['searchId' => $searchA->id])
            ->test(\App\Filament\Pages\Results::class)
            ->assertCanSeeTableRecords([$imgA])
            ->assertCanNotSeeTableRecords([$imgB]);
    }
}
