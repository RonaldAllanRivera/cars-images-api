<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CsvImportResource\Pages\ListCsvImports;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CsvImportResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_view_list_page(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ListCsvImports::class)
            ->assertSuccessful();
    }

    public function test_list_page_shows_imports(): void
    {
        $user = User::factory()->create();

        $csvImport = CsvImport::create([
            'original_filename' => 'sample.csv',
            'total_rows' => 100,
            'unique_combos' => 50,
            'duplicates_skipped' => 50,
            'imported_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(ListCsvImports::class)
            ->assertCanSeeTableRecords([$csvImport]);
    }
}
