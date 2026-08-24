<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Results;
use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ResultsPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeImportedImage(?string $thumbnailUrl = null): CarImage
    {
        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 's.csv', 'total_rows' => 1,
            'unique_combos' => 1, 'duplicates_skipped' => 0, 'imported_by' => $user->id,
        ]);
        $search = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4', 'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id, 'csv_import_id' => $csvImport->id,
        ]);

        return CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia', 'provider_image_id' => 'A',
            'make' => 'Toyota', 'model' => 'RAV4', 'year' => 1997, 'title' => 'A',
            'source_url' => 'https://upload.wikimedia.org/wikipedia/commons/4/47/Foo.jpg',
            'thumbnail_url' => $thumbnailUrl ?? 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/47/Foo.jpg/1280px-Foo.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);
    }

    public function test_bulk_zip_action_returns_a_file_download(): void
    {
        Http::fake(['*' => Http::response('IMGDATA', 200)]);

        $image = $this->makeImportedImage();
        $user = User::first();

        Livewire::actingAs($user)
            ->test(Results::class)
            ->callTableBulkAction('downloadZip', [$image])
            ->assertFileDownloaded();

        $this->assertSame('downloaded', $image->fresh()->download_status);
    }

    public function test_bulk_csv_action_returns_a_file_download(): void
    {
        $image = $this->makeImportedImage();
        $user = User::first();

        Livewire::actingAs($user)
            ->test(Results::class)
            ->callTableBulkAction('exportCsv', [$image])
            ->assertFileDownloaded();
    }

    public function test_make_match_filter_shows_only_confirmed_images(): void
    {
        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 's.csv', 'total_rows' => 1,
            'unique_combos' => 1, 'duplicates_skipped' => 0, 'imported_by' => $user->id,
        ]);
        $search = CarSearch::create([
            'make' => 'Acura', 'model' => 'CL', 'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id, 'csv_import_id' => $csvImport->id,
        ]);
        $confirmed = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia', 'provider_image_id' => 'OK',
            'make' => 'Acura', 'model' => 'CL', 'year' => 1997, 'title' => 'Acura CL',
            'source_url' => 'https://example.com/ok.jpg', 'thumbnail_url' => 'https://example.com/ok.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
            'make_confirmed' => true,
        ]);
        $offMake = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia', 'provider_image_id' => 'OFF',
            'make' => 'Acura', 'model' => 'CL', 'year' => 1997, 'title' => 'Honda Accord CL3 europe',
            'source_url' => 'https://example.com/off.jpg', 'thumbnail_url' => 'https://example.com/off.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
            'make_confirmed' => false,
        ]);

        Livewire::actingAs($user)
            ->test(Results::class)
            ->assertCanSeeTableRecords([$confirmed, $offMake])
            ->filterTable('make_confirmed', '1')
            ->assertCanSeeTableRecords([$confirmed])
            ->assertCanNotSeeTableRecords([$offMake]);
    }

    /**
     * Create a confirmed + an off-make image under a CSV-imported search,
     * so both appear in the Results page query (scoped to csv_import_id).
     *
     * @return array{0: User, 1: CarImage, 2: CarImage}
     */
    private function makeConfirmedAndOffMake(): array
    {
        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 's.csv', 'total_rows' => 1,
            'unique_combos' => 1, 'duplicates_skipped' => 0, 'imported_by' => $user->id,
        ]);
        $search = CarSearch::create([
            'make' => 'Acura', 'model' => 'CL', 'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id, 'csv_import_id' => $csvImport->id,
        ]);
        $confirmed = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia', 'provider_image_id' => 'OK',
            'make' => 'Acura', 'model' => 'CL', 'year' => 1997, 'title' => 'Acura CL',
            'source_url' => 'https://upload.wikimedia.org/ok.jpg', 'thumbnail_url' => 'https://upload.wikimedia.org/ok.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded', 'make_confirmed' => true,
        ]);
        $offMake = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia', 'provider_image_id' => 'OFF',
            'make' => 'Acura', 'model' => 'CL', 'year' => 1997, 'title' => 'Honda Accord',
            'source_url' => 'https://upload.wikimedia.org/off.jpg', 'thumbnail_url' => 'https://upload.wikimedia.org/off.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded', 'make_confirmed' => false,
        ]);

        return [$user, $confirmed, $offMake];
    }

    public function test_download_confirmed_zip_includes_only_confirmed_images(): void
    {
        Http::fake(['*' => Http::response('IMGDATA', 200)]);

        [$user, $confirmed, $offMake] = $this->makeConfirmedAndOffMake();

        Livewire::actingAs($user)
            ->test(Results::class)
            ->callTableBulkAction('downloadConfirmedZip', [$confirmed, $offMake])
            ->assertFileDownloaded();

        // Only the confirmed image is marked downloaded; the off-make one is left alone.
        $this->assertSame('downloaded', $confirmed->fresh()->download_status);
        $this->assertSame('not_downloaded', $offMake->fresh()->download_status);
    }

    public function test_download_confirmed_zip_notifies_when_selection_has_no_confirmed(): void
    {
        [$user, , $offMake] = $this->makeConfirmedAndOffMake();

        Livewire::actingAs($user)
            ->test(Results::class)
            ->callTableBulkAction('downloadConfirmedZip', [$offMake])
            ->assertNotified();
    }

    public function test_bulk_zip_respects_the_max_images_cap(): void
    {
        config(['cars-images.bulk_download_max_images' => 1]);

        [$user, $confirmed, $offMake] = $this->makeConfirmedAndOffMake();

        Livewire::actingAs($user)
            ->test(Results::class)
            ->callTableBulkAction('downloadZip', [$confirmed, $offMake])
            ->assertNotified();

        // Over the cap → nothing downloaded.
        $this->assertSame('not_downloaded', $confirmed->fresh()->download_status);
        $this->assertSame('not_downloaded', $offMake->fresh()->download_status);
    }

    public function test_bulk_zip_action_notifies_when_no_images_downloadable(): void
    {
        Http::fake(['*' => Http::response('Forbidden', 403)]);

        $image = $this->makeImportedImage();
        $user = User::first();

        Livewire::actingAs($user)
            ->test(Results::class)
            ->callTableBulkAction('downloadZip', [$image])
            ->assertNotified();

        $this->assertSame('not_downloaded', $image->fresh()->download_status);
    }

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

    public function test_filters_to_single_search_when_search_id_param_present(): void
    {
        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 's.csv', 'total_rows' => 2,
            'unique_combos' => 2, 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);
        $searchA = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4', 'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id, 'csv_import_id' => $csvImport->id,
        ]);
        $searchB = CarSearch::create([
            'make' => 'Honda', 'model' => 'Civic', 'from_year' => 2010, 'to_year' => 2010,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id, 'csv_import_id' => $csvImport->id,
        ]);
        $imgA = CarImage::create([
            'car_search_id' => $searchA->id, 'provider' => 'wikimedia', 'provider_image_id' => 'A',
            'make' => 'Toyota', 'model' => 'RAV4', 'year' => 1997, 'title' => 'A',
            'source_url' => 'https://example.com/a.jpg', 'thumbnail_url' => 'https://example.com/a.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);
        $imgB = CarImage::create([
            'car_search_id' => $searchB->id, 'provider' => 'wikimedia', 'provider_image_id' => 'B',
            'make' => 'Honda', 'model' => 'Civic', 'year' => 2010, 'title' => 'B',
            'source_url' => 'https://example.com/b.jpg', 'thumbnail_url' => 'https://example.com/b.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);

        Livewire::actingAs($user)
            ->withQueryParams(['searchId' => $searchA->id])
            ->test(Results::class)
            ->assertCanSeeTableRecords([$imgA])
            ->assertCanNotSeeTableRecords([$imgB]);
    }
}
