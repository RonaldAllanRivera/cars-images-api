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

/**
 * The Results page lists only csv-imported images, so every fixture here
 * hangs off an import - the same shape ResultsPageTest uses.
 */
class ResultsReviewStatusTest extends TestCase
{
    use RefreshDatabase;

    private function importedImage(User $user, CsvImport $import, string $reviewStatus): CarImage
    {
        $id = uniqid('img-', true);

        $search = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4', 'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id, 'csv_import_id' => $import->id,
        ]);

        return CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia', 'provider_image_id' => $id,
            'make' => 'Toyota', 'model' => 'RAV4', 'year' => 1997, 'title' => "File:{$id}.jpg",
            'source_url' => "https://upload.wikimedia.org/{$id}.jpg",
            'thumbnail_url' => "https://upload.wikimedia.org/thumb/{$id}.jpg",
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
            'review_status' => $reviewStatus,
        ]);
    }

    public function test_the_review_column_renders_and_its_filter_narrows_to_one_verdict(): void
    {
        $user = User::factory()->create();
        $import = CsvImport::create([
            'original_filename' => 's.csv', 'total_rows' => 2,
            'unique_combos' => 2, 'duplicates_skipped' => 0, 'imported_by' => $user->id,
        ]);
        $approved = $this->importedImage($user, $import, CarImage::REVIEW_APPROVED);
        $pending = $this->importedImage($user, $import, CarImage::REVIEW_PENDING);

        Livewire::actingAs($user)
            ->test(Results::class)
            ->assertCanRenderTableColumn('review_status')
            ->assertCanSeeTableRecords([$approved, $pending])
            ->filterTable('review_status', CarImage::REVIEW_APPROVED)
            ->assertCanSeeTableRecords([$approved])
            ->assertCanNotSeeTableRecords([$pending]);
    }
}
