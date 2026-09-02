<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Results;
use App\Filament\Resources\SearchQueryResource\Pages\ListSearchQueries;
use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the gap that made a half-finished import look finished.
 *
 * A CSV import turns into one search per year/make/model. Searches that never
 * ran leave no images, and searches that ran and found nothing leave no images
 * either — so the Results table renders both as simple absence, under a total
 * that counts only what exists. These assertions pin the three things that now
 * tell them apart: the coverage counts, the filter that lists the empties, and
 * the action that runs whatever is left.
 */
class RunCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CsvImport $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->import = CsvImport::create([
            'original_filename' => 'cars.csv',
            'total_rows' => 4,
            'unique_combos' => 4,
            'duplicates_skipped' => 0,
            'imported_by' => $this->user->id,
        ]);
    }

    private function search(string $model, string $status, int $images = 0): CarSearch
    {
        $search = CarSearch::create([
            'make' => 'Acura',
            'model' => $model,
            'from_year' => 2013,
            'to_year' => 2013,
            'transparent_background' => false,
            'images_per_year' => 5,
            'status' => $status,
            'requested_by' => $this->user->id,
            'csv_import_id' => $this->import->id,
        ]);

        // Not range(1, $images): range(1, 0) counts *down* to [1, 0] in PHP,
        // which would give every "found nothing" search two images.
        for ($n = 1; $n <= $images; $n++) {
            CarImage::create([
                'car_search_id' => $search->id,
                'provider' => 'wikimedia',
                'provider_image_id' => "{$model}-{$n}",
                'make' => 'Acura',
                'model' => $model,
                'year' => 2013,
                'title' => "{$model} {$n}",
                'source_url' => 'https://upload.wikimedia.org/a.jpg',
                'thumbnail_url' => 'https://upload.wikimedia.org/a.jpg',
                'download_status' => 'not_downloaded',
            ]);
        }

        return $search;
    }

    /**
     * The shape this whole feature exists for: two of the four searches
     * produced no images, for two entirely different reasons.
     */
    private function mixedImport(): void
    {
        $this->search('ILX', 'completed', images: 2);
        $this->search('RL', 'completed', images: 0);   // ran, found nothing
        $this->search('Integra', 'pending');           // never ran
        $this->search('TL', 'failed');                 // errored
    }

    public function test_coverage_separates_never_ran_from_found_nothing(): void
    {
        $this->mixedImport();

        $coverage = Livewire::actingAs($this->user)
            ->test(Results::class)
            ->instance()
            ->coverage();

        $this->assertSame(4, $coverage['total']);
        $this->assertSame(1, $coverage['notRun']);
        $this->assertSame(3, $coverage['searched']);
        $this->assertSame(1, $coverage['failed']);
        $this->assertSame(1, $coverage['withImages']);
        $this->assertSame(1, $coverage['noImages']);
    }

    public function test_coverage_is_null_when_there_is_no_import_to_describe(): void
    {
        $this->assertNull(
            Livewire::actingAs($this->user)->test(Results::class)->instance()->coverage()
        );
    }

    /**
     * A search belonging to another import must not be counted, or the panel
     * would report progress the viewer is not looking at.
     */
    public function test_coverage_scopes_to_the_import_of_the_viewed_search(): void
    {
        $this->mixedImport();

        $other = CsvImport::create([
            'original_filename' => 'other.csv',
            'total_rows' => 1, 'unique_combos' => 1, 'duplicates_skipped' => 0,
            'imported_by' => $this->user->id,
        ]);
        CarSearch::create([
            'make' => 'Honda', 'model' => 'Civic', 'from_year' => 2020, 'to_year' => 2020,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'pending', 'requested_by' => $this->user->id,
            'csv_import_id' => $other->id,
        ]);

        $viewed = CarSearch::where('model', 'ILX')->firstOrFail();

        $coverage = Livewire::actingAs($this->user)
            ->test(Results::class, ['searchId' => (string) $viewed->id])
            ->instance()
            ->coverage();

        $this->assertSame('cars.csv', $coverage['importName']);
        $this->assertSame(4, $coverage['total']);
    }

    /**
     * The panel's two buttons are deep links into the Search Queries filters.
     * Filament 5 reads them from `filters` in the query string; under v3's
     * `tableFilters` key the links still resolve, still return 200, and
     * quietly show the unfiltered list — so assert the key itself.
     */
    public function test_coverage_links_carry_a_filter_filament_actually_reads(): void
    {
        $this->mixedImport();

        $coverage = Livewire::actingAs($this->user)
            ->test(Results::class)
            ->instance()
            ->coverage();

        $this->assertStringContainsString(
            urlencode('filters[coverage][value]').'=not_run',
            $coverage['notRunUrl'],
        );
        $this->assertStringContainsString(
            urlencode('filters[coverage][value]').'=no_images',
            $coverage['noImagesUrl'],
        );
        $this->assertStringNotContainsString('tableFilters', $coverage['notRunUrl']);
    }

    public function test_no_images_filter_lists_only_searches_that_ran_and_found_nothing(): void
    {
        $this->mixedImport();

        $ranEmpty = CarSearch::where('model', 'RL')->firstOrFail();
        $found = CarSearch::where('model', 'ILX')->firstOrFail();
        $neverRan = CarSearch::where('model', 'Integra')->firstOrFail();

        Livewire::actingAs($this->user)
            ->test(ListSearchQueries::class)
            ->filterTable('coverage', 'no_images')
            ->assertCanSeeTableRecords([$ranEmpty])
            ->assertCanNotSeeTableRecords([$found, $neverRan]);
    }

    public function test_not_run_filter_lists_only_searches_that_have_not_run(): void
    {
        $this->mixedImport();

        Livewire::actingAs($this->user)
            ->test(ListSearchQueries::class)
            ->filterTable('coverage', 'not_run')
            ->assertCanSeeTableRecords([CarSearch::where('model', 'Integra')->firstOrFail()])
            ->assertCanNotSeeTableRecords([CarSearch::where('model', 'RL')->firstOrFail()]);
    }

    /**
     * `failed` counts as runnable — it is work still owed — while a search
     * that already completed must never be run a second time.
     */
    public function test_run_all_pending_queues_pending_and_failed_but_not_completed(): void
    {
        $this->mixedImport();

        $component = Livewire::actingAs($this->user)->test(ListSearchQueries::class);

        $expected = CarSearch::whereIn('status', ['pending', 'failed'])->orderBy('id')->pluck('id')->all();

        $this->assertSame($expected, $component->instance()->runnableSearchIds());

        $component->callAction('runAllPending')
            ->assertSet('runActive', true)
            ->assertSet('runTotal', 2);
    }

    public function test_run_all_pending_respects_the_active_filters(): void
    {
        $this->mixedImport();

        Livewire::actingAs($this->user)
            ->test(ListSearchQueries::class)
            ->filterTable('coverage', 'not_run')
            ->callAction('runAllPending')
            // `failed` is runnable, but the Not-run filter excludes it.
            ->assertSet('runTotal', 1);
    }

    public function test_run_all_pending_is_hidden_when_nothing_is_left_to_run(): void
    {
        $this->search('ILX', 'completed', images: 2);

        Livewire::actingAs($this->user)
            ->test(ListSearchQueries::class)
            ->assertActionHidden('runAllPending');
    }
}
