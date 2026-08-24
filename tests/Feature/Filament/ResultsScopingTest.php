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
 * The Results page must stay scoped to one search across interactions.
 *
 * `searchId` used to be read with `request()->query('searchId')` inside the
 * table query closure. That works for the first render only: every later
 * Livewire request POSTs to the update endpoint with no query string, so
 * the scope silently disappeared and the table widened to every
 * csv-imported image in the database. Paginating, sorting, searching, or
 * running any bulk action was enough to trigger it — including
 * `DeleteBulkAction`, whose select-all plucks keys from the *filtered*
 * query and therefore deleted unrelated imports.
 *
 * `ResultsPageTest` could not catch this: `Livewire::withQueryParams()`
 * pins the query string for the component's entire lifetime, which no
 * browser does. These tests deliberately pass `searchId` the way Livewire
 * itself carries component state, so a query-string-only implementation
 * fails them.
 */
class ResultsScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CsvImport $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->import = CsvImport::create([
            'original_filename' => 'sample.csv',
            'total_rows' => 2,
            'unique_combos' => 2,
            'duplicates_skipped' => 0,
            'imported_by' => $this->user->id,
        ]);
    }

    private function searchWithImage(string $make, string $model, int $year): array
    {
        $search = CarSearch::create([
            'make' => $make,
            'model' => $model,
            'from_year' => $year,
            'to_year' => $year,
            'transparent_background' => false,
            'images_per_year' => 5,
            'status' => 'completed',
            'requested_by' => $this->user->id,
            'csv_import_id' => $this->import->id,
        ]);

        $image = CarImage::create([
            'car_search_id' => $search->id,
            'provider' => 'wikimedia',
            'provider_image_id' => "{$make}-{$year}",
            'make' => $make,
            'model' => $model,
            'year' => $year,
            'title' => "File:{$make} {$model}.jpg",
            'source_url' => "https://upload.wikimedia.org/{$make}.jpg",
            'thumbnail_url' => "https://upload.wikimedia.org/{$make}.jpg",
            'width' => 800,
            'height' => 600,
            'download_status' => 'not_downloaded',
        ]);

        return [$search, $image];
    }

    public function test_the_scope_is_component_state_not_a_query_string(): void
    {
        [$searchA, $imageA] = $this->searchWithImage('Toyota', 'RAV4', 1997);
        [, $imageB] = $this->searchWithImage('Honda', 'Civic', 2010);

        // Passed as component state — the way Livewire carries it on every
        // request after the first, rather than as a one-shot query string.
        Livewire::actingAs($this->user)
            ->test(Results::class, ['searchId' => $searchA->id])
            ->assertCanSeeTableRecords([$imageA])
            ->assertCanNotSeeTableRecords([$imageB]);
    }

    public function test_the_scope_survives_a_table_interaction(): void
    {
        [$searchA, $imageA] = $this->searchWithImage('Toyota', 'RAV4', 1997);
        [, $imageB] = $this->searchWithImage('Honda', 'Civic', 2010);

        Livewire::actingAs($this->user)
            ->test(Results::class, ['searchId' => $searchA->id])
            ->sortTable('year')
            ->assertCanSeeTableRecords([$imageA])
            ->assertCanNotSeeTableRecords([$imageB])
            ->searchTable('')
            ->assertCanNotSeeTableRecords([$imageB]);
    }

    public function test_without_a_search_id_every_imported_image_is_listed(): void
    {
        [, $imageA] = $this->searchWithImage('Toyota', 'RAV4', 1997);
        [, $imageB] = $this->searchWithImage('Honda', 'Civic', 2010);

        Livewire::actingAs($this->user)
            ->test(Results::class)
            ->assertCanSeeTableRecords([$imageA, $imageB]);
    }
}
