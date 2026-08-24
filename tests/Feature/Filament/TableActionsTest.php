<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CarImageResource\Pages\ListCarImages;
use App\Filament\Resources\CarMakeResource\Pages\ListCarMakes;
use App\Filament\Resources\CarSearchResource\Pages\ListCarSearches;
use App\Filament\Resources\CarSearchResource\Pages\ViewCarSearch;
use App\Filament\Resources\CarSearchResource\RelationManagers\CarImagesRelationManager;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\CarImage;
use App\Models\CarMake;
use App\Models\CarSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pins the record and bulk actions registered on every table.
 *
 * Purpose: these five tables are the ones still declaring their actions
 * through the `->actions()` / `->bulkActions()` builder methods, which
 * Filament 4 marks `@deprecated` in favour of `->recordActions()` /
 * `->toolbarActions()`. Renaming them is a prerequisite for Filament 5.
 *
 * `PanelSmokeTest` proves those pages still *render*, but a table whose
 * actions silently vanished still renders perfectly — it just quietly
 * loses its Delete and Download buttons. These assertions are what make
 * the rename safe: they fail if an action stops being registered.
 */
class TableActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create();
    }

    private function searchWithImage(User $user): CarSearch
    {
        $search = CarSearch::create([
            'make' => 'Toyota',
            'model' => 'RAV4',
            'from_year' => 1997,
            'to_year' => 1997,
            'transparent_background' => false,
            'images_per_year' => 5,
            'status' => 'completed',
            'requested_by' => $user->id,
        ]);

        CarImage::create([
            'car_search_id' => $search->id,
            'provider' => 'wikimedia',
            'provider_image_id' => 'p1',
            'make' => 'Toyota',
            'model' => 'RAV4',
            'year' => 1997,
            'title' => 'A RAV4',
            'source_url' => 'https://upload.wikimedia.org/a.jpg',
            'thumbnail_url' => 'https://upload.wikimedia.org/a.jpg',
            'width' => 800,
            'height' => 600,
            'download_status' => 'not_downloaded',
        ]);

        return $search;
    }

    public function test_car_images_table_keeps_its_record_and_bulk_actions(): void
    {
        $user = $this->admin();
        $this->searchWithImage($user);

        Livewire::actingAs($user)
            ->test(ListCarImages::class)
            ->assertTableActionExists('preview')
            ->assertTableActionExists('delete')
            ->assertTableBulkActionExists('downloadSelected')
            ->assertTableBulkActionExists('delete');
    }

    public function test_car_searches_table_keeps_its_view_action(): void
    {
        $user = $this->admin();
        $this->searchWithImage($user);

        Livewire::actingAs($user)
            ->test(ListCarSearches::class)
            ->assertTableActionExists('view');
    }

    public function test_car_makes_table_keeps_its_edit_and_delete_actions(): void
    {
        $user = $this->admin();
        CarMake::create(['name' => 'Toyota']);

        Livewire::actingAs($user)
            ->test(ListCarMakes::class)
            ->assertTableActionExists('edit')
            ->assertTableBulkActionExists('delete');
    }

    public function test_users_table_keeps_its_edit_and_delete_actions(): void
    {
        $user = $this->admin();

        Livewire::actingAs($user)
            ->test(ListUsers::class)
            ->assertTableActionExists('edit')
            ->assertTableBulkActionExists('delete');
    }

    public function test_search_images_relation_manager_keeps_its_actions(): void
    {
        $user = $this->admin();
        $search = $this->searchWithImage($user);

        Livewire::actingAs($user)
            ->test(CarImagesRelationManager::class, [
                'ownerRecord' => $search,
                'pageClass' => ViewCarSearch::class,
            ])
            ->assertTableActionExists('preview')
            ->assertTableActionExists('delete')
            ->assertTableBulkActionExists('downloadSelected')
            ->assertTableBulkActionExists('delete');
    }
}
