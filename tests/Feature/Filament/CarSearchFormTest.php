<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CarSearchResource\Pages\CreateCarSearch;
use App\Filament\Resources\CarSearchResource\Pages\EditCarSearch;
use App\Models\CarSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Characterises the ad-hoc Car Search form.
 *
 * The form leans on three Filament behaviours that a major upgrade can
 * change without any deprecation warning:
 *
 *   1. `dehydrateStateUsing()`   — turns the `__all__` sentinel into NULL on save.
 *   2. `afterStateHydrated()`    — turns NULL back into `__all__` when editing.
 *   3. `handleRecordCreation()`  — reuse of an identical completed search.
 *
 * If (1) regresses, the literal string "__all__" is written to the database
 * and every subsequent Wikimedia query is poisoned with it. If (2) regresses,
 * editing a search silently drops its "All ..." filters. Neither failure is
 * visible without a test, which is why these live here rather than being
 * covered only by the service-level tests.
 */
class CarSearchFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No test in this file should reach the network. Anything that does
        // gets an empty Wikimedia result set rather than a real request.
        Http::fake([
            '*' => Http::response(['query' => ['pages' => []]], 200),
        ]);
    }

    public function test_all_sentinel_options_are_persisted_as_null(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateCarSearch::class)
            ->fillForm([
                'make' => 'Toyota',
                'model' => '__all__',
                'from_year' => 2018,
                'to_year' => 2022,
                'color' => '__all__',
                'transmission' => '__all__',
                'transparent_background' => false,
                'images_per_year' => 10,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $search = CarSearch::query()->latest('id')->first();

        $this->assertNotNull($search);
        $this->assertSame('Toyota', $search->make);
        $this->assertNull($search->model, 'The "All models" sentinel must be stored as NULL.');
        $this->assertNull($search->color, 'The "All colors" sentinel must be stored as NULL.');
        $this->assertNull($search->transmission, 'The "All transmissions" sentinel must be stored as NULL.');
    }

    public function test_reversed_year_range_is_normalized(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateCarSearch::class)
            ->fillForm([
                'make' => 'Toyota',
                'model' => '__all__',
                'from_year' => 2022,
                'to_year' => 2018,
                'color' => '__all__',
                'transmission' => '__all__',
                'transparent_background' => false,
                'images_per_year' => 10,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $search = CarSearch::query()->latest('id')->first();

        $this->assertNotNull($search);
        $this->assertSame(2018, $search->from_year, 'A reversed range must be swapped, not stored as given.');
        $this->assertSame(2022, $search->to_year);
    }

    public function test_identical_completed_search_is_reused_without_calling_wikimedia(): void
    {
        $user = User::factory()->create();

        $existing = CarSearch::create([
            'make' => 'Toyota',
            'model' => null,
            'from_year' => 2018,
            'to_year' => 2022,
            'color' => null,
            'transmission' => null,
            'transparent_background' => false,
            'images_per_year' => 10,
            'status' => 'completed',
            'requested_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(CreateCarSearch::class)
            ->fillForm([
                'make' => 'Toyota',
                'model' => '__all__',
                'from_year' => 2018,
                'to_year' => 2022,
                'color' => '__all__',
                'transmission' => '__all__',
                'transparent_background' => false,
                'images_per_year' => 10,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            1,
            CarSearch::query()->count(),
            'An identical completed search must be reused instead of creating a second row.'
        );
        $this->assertSame($existing->id, CarSearch::query()->first()->id);

        Http::assertNothingSent();
    }

    public function test_editing_a_search_hydrates_the_all_sentinel_for_null_filters(): void
    {
        $user = User::factory()->create();

        $search = CarSearch::create([
            'make' => 'Toyota',
            'model' => null,
            'from_year' => 2018,
            'to_year' => 2022,
            'color' => null,
            'transmission' => null,
            'transparent_background' => false,
            'images_per_year' => 10,
            'status' => 'completed',
            'requested_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(EditCarSearch::class, ['record' => $search->getKey()])
            ->assertFormSet([
                'model' => '__all__',
                'color' => '__all__',
                'transmission' => '__all__',
            ]);
    }

    public function test_saving_an_edited_search_persists_the_all_sentinels_as_null(): void
    {
        // The edit path is where `dehydrateStateUsing()` is load-bearing.
        // On create, CreateCarSearch::handleRecordCreation() has its own
        // $normalize closure that masks the loss of these callbacks, so a
        // create-only test passes even with all three of them deleted.
        // Without this test, removing them persists the literal string
        // "__all__" as a real colour or transmission, which then travels into
        // the CSV manifest and the download filenames as if a user had chosen
        // it.
        $user = User::factory()->create();

        $search = CarSearch::create([
            'make' => 'Toyota',
            'model' => 'RAV4',
            'from_year' => 2018,
            'to_year' => 2022,
            'color' => 'red',
            'transmission' => 'Automatic',
            'transparent_background' => false,
            'images_per_year' => 10,
            'status' => 'completed',
            'requested_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(EditCarSearch::class, ['record' => $search->getKey()])
            // `set()` rather than `fillForm()`: on an EditRecord page fillForm
            // re-fills the schema from the record, so the new values are
            // discarded before save and the test silently passes.
            ->set('data.model', '__all__')
            ->set('data.color', '__all__')
            ->set('data.transmission', '__all__')
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $search->fresh();

        $this->assertNull($fresh->model, 'Editing to "All models" must persist NULL, not the sentinel string.');
        $this->assertNull($fresh->color, 'Editing to "All colors" must persist NULL, not the sentinel string.');
        $this->assertNull($fresh->transmission, 'Editing to "All transmissions" must persist NULL, not the sentinel string.');
    }
}
