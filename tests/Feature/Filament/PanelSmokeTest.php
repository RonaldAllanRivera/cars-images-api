<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Results;
use App\Filament\Resources\CarImageResource\Pages\ListCarImages;
use App\Filament\Resources\CarMakeResource\Pages\CreateCarMake;
use App\Filament\Resources\CarMakeResource\Pages\EditCarMake;
use App\Filament\Resources\CarMakeResource\Pages\ListCarMakes;
use App\Filament\Resources\CarSearchResource\Pages\CreateCarSearch;
use App\Filament\Resources\CarSearchResource\Pages\EditCarSearch;
use App\Filament\Resources\CarSearchResource\Pages\ListCarSearches;
use App\Filament\Resources\CarSearchResource\Pages\ViewCarSearch;
use App\Filament\Resources\CsvImportResource\Pages\CreateCsvImport;
use App\Filament\Resources\CsvImportResource\Pages\ListCsvImports;
use App\Filament\Resources\CsvImportResource\Pages\ViewCsvImport;
use App\Filament\Resources\SearchQueryResource\Pages\ListSearchQueries;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\CarMake;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Renders every page in the admin panel.
 *
 * This is the upgrade safety net. A Filament or Livewire major release
 * typically breaks an application by renaming, moving, or removing a
 * builder method that a resource calls at render time — the resource
 * still compiles, but the page throws when Livewire mounts it. Nothing
 * else in this suite mounts most of these pages, so without this file a
 * broken upgrade only shows up when a human clicks through the panel.
 *
 * Each test asserts nothing more than "this page mounts and renders for
 * an authenticated admin". Keep it that way: behaviour belongs in the
 * focused test files, and a smoke test that asserts on markup becomes
 * noise the moment the UI is restyled.
 */
class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create();
    }

    private function adHocSearch(User $user): CarSearch
    {
        return CarSearch::create([
            'make' => 'Toyota',
            'model' => 'RAV4',
            'from_year' => 2018,
            'to_year' => 2022,
            'transparent_background' => false,
            'images_per_year' => 10,
            'status' => 'completed',
            'requested_by' => $user->id,
        ]);
    }

    public function test_car_search_list_page_renders(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListCarSearches::class)
            ->assertSuccessful();
    }

    public function test_car_search_create_page_renders(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateCarSearch::class)
            ->assertSuccessful();
    }

    public function test_car_search_view_page_renders(): void
    {
        $user = $this->admin();
        $search = $this->adHocSearch($user);

        Livewire::actingAs($user)
            ->test(ViewCarSearch::class, ['record' => $search->getKey()])
            ->assertSuccessful();
    }

    public function test_car_search_edit_page_renders(): void
    {
        $user = $this->admin();
        $search = $this->adHocSearch($user);

        Livewire::actingAs($user)
            ->test(EditCarSearch::class, ['record' => $search->getKey()])
            ->assertSuccessful();
    }

    public function test_car_image_list_page_renders(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListCarImages::class)
            ->assertSuccessful();
    }

    public function test_car_make_list_page_renders(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListCarMakes::class)
            ->assertSuccessful();
    }

    public function test_car_make_create_page_renders(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateCarMake::class)
            ->assertSuccessful();
    }

    public function test_car_make_edit_page_renders(): void
    {
        $make = CarMake::create(['name' => 'Toyota']);
        $make->models()->create(['name' => 'RAV4']);

        Livewire::actingAs($this->admin())
            ->test(EditCarMake::class, ['record' => $make->getKey()])
            ->assertSuccessful();
    }

    public function test_user_list_page_renders(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListUsers::class)
            ->assertSuccessful();
    }

    public function test_user_create_page_renders(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateUser::class)
            ->assertSuccessful();
    }

    public function test_user_edit_page_renders(): void
    {
        $user = $this->admin();

        Livewire::actingAs($user)
            ->test(EditUser::class, ['record' => $user->getKey()])
            ->assertSuccessful();
    }

    public function test_csv_import_list_page_renders(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListCsvImports::class)
            ->assertSuccessful();
    }

    public function test_csv_import_create_page_renders(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateCsvImport::class)
            ->assertSuccessful();
    }

    public function test_csv_import_view_page_renders(): void
    {
        $user = $this->admin();

        $csvImport = CsvImport::create([
            'original_filename' => 'sample.csv',
            'total_rows' => 10,
            'unique_combos' => 8,
            'duplicates_skipped' => 2,
            'imported_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(ViewCsvImport::class, ['record' => $csvImport->getKey()])
            ->assertSuccessful();
    }

    public function test_search_queries_list_page_renders(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ListSearchQueries::class)
            ->assertSuccessful();
    }

    public function test_results_page_renders(): void
    {
        Livewire::actingAs($this->admin())
            ->test(Results::class)
            ->assertSuccessful();
    }
}
