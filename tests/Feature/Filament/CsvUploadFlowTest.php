<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CsvImportResource\Pages\CreateCsvImport;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Drives the CSV upload through the Filament form itself.
 *
 * `CsvQueryImporterTest` covers the parser directly, but nothing exercised
 * the path the user actually takes: FileUpload component -> form state ->
 * `handleRecordCreation()` -> importer -> persisted queries. Livewire 4
 * moved file uploads onto async actions and widened the lifecycle-method
 * whitelist, which makes this seam worth pinning.
 *
 * Note the limit of this test: it hands the component an `UploadedFile`
 * directly, so it verifies the PHP half of the upload. The browser half
 * (`_startUpload` / `_finishUpload` / `_uploadErrored` / `_removeUpload`)
 * is JavaScript transport and cannot be reached from PHPUnit at all —
 * that part needs a real browser.
 */
class CsvUploadFlowTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('vehicles.csv', $body);
    }

    public function test_uploading_a_csv_creates_the_import_and_its_pending_queries(): void
    {
        $user = User::factory()->create();

        $csv = <<<'CSV'
        Make,Model,Year,Transmission
        Toyota,RAV4,1997,Automatic 4-spd
        Acura,2.2CL/3.0CL,1998,Manual 5-spd
        Toyota,RAV4,1997,Manual 5-spd
        CSV;

        Livewire::actingAs($user)
            ->test(CreateCsvImport::class)
            ->fillForm(['csv_file' => $this->csv($csv)])
            ->call('create')
            ->assertHasNoFormErrors();

        $import = CsvImport::query()->latest('id')->first();

        $this->assertNotNull($import, 'The upload should create a CsvImport row.');
        $this->assertSame('vehicles.csv', $import->original_filename);
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(2, $import->unique_combos, 'The duplicate (1997, Toyota, RAV4) row must be collapsed.');

        $queries = CarSearch::query()->where('csv_import_id', $import->id)->get();

        $this->assertCount(2, $queries);
        $this->assertEqualsCanonicalizing(
            ['RAV4', '2.2CL/3.0CL'],
            $queries->pluck('model')->all(),
            'The raw model string is stored verbatim; normalization happens at query time.'
        );
        $this->assertTrue(
            $queries->every(fn (CarSearch $q) => $q->status === 'pending'),
            'Imported queries must start pending so a human chooses when to run them.'
        );
    }

    public function test_a_csv_missing_required_columns_is_rejected_without_creating_anything(): void
    {
        $user = User::factory()->create();

        $csv = <<<'CSV'
        Brand,Vehicle,Built
        Toyota,RAV4,1997
        CSV;

        Livewire::actingAs($user)
            ->test(CreateCsvImport::class)
            ->fillForm(['csv_file' => $this->csv($csv)])
            ->call('create');

        $this->assertSame(0, CsvImport::query()->count(), 'A rejected import must not be persisted.');
        $this->assertSame(0, CarSearch::query()->count(), 'A rejected import must not leave orphan queries.');
    }
}
