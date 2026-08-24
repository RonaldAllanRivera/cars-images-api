<?php

namespace Tests\Feature\Services\Imports;

use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use App\Services\Imports\CsvImportException;
use App\Services\Imports\CsvQueryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CsvQueryImporterTest extends TestCase
{
    use RefreshDatabase;

    private function makeCsv(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'sample.csv', 'text/csv', null, true);
    }

    public function test_imports_unique_year_make_model_rows(): void
    {
        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<'CSV'
        Make,Model,Year,Transmission
        Toyota,RAV4,1997,Automatic 4-spd
        Toyota,Camry,1998,Manual 5-spd
        Mitsubishi,Mirage,2015,Automatic
        CSV);

        $importer = app(CsvQueryImporter::class);
        $result = $importer->import($csv, $user);

        $this->assertInstanceOf(CsvImport::class, $result->csvImport);
        $this->assertSame(3, $result->csvImport->total_rows);
        $this->assertSame(3, $result->csvImport->unique_combos);
        $this->assertSame(0, $result->csvImport->duplicates_skipped);
        $this->assertSame(3, CarSearch::count());
        $this->assertSame(3, CarSearch::where('csv_import_id', $result->csvImport->id)->count());
    }

    public function test_deduplicates_by_year_make_model_ignoring_transmission(): void
    {
        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<'CSV'
        Make,Model,Year,Transmission
        Toyota,RAV4,1997,Automatic 4-spd
        Toyota,RAV4,1997,Manual 5-spd
        Toyota,RAV4,1997,Automatic 4-spd
        CSV);

        $result = app(CsvQueryImporter::class)->import($csv, $user);

        $this->assertSame(3, $result->csvImport->total_rows);
        $this->assertSame(1, $result->csvImport->unique_combos);
        $this->assertSame(2, $result->csvImport->duplicates_skipped);
        $this->assertSame(1, CarSearch::count());
    }

    public function test_sets_from_year_and_to_year_to_same_value(): void
    {
        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<'CSV'
        Make,Model,Year,Transmission
        Honda,Civic,2010,Manual
        CSV);

        app(CsvQueryImporter::class)->import($csv, $user);

        $search = CarSearch::first();
        $this->assertSame(2010, $search->from_year);
        $this->assertSame(2010, $search->to_year);
        $this->assertSame('Honda', $search->make);
        $this->assertSame('Civic', $search->model);
        $this->assertSame('pending', $search->status);
        $this->assertSame(5, $search->images_per_year); // from cars-images.csv_import_default_images_per_year
    }

    public function test_rejects_when_unique_combos_exceeds_max(): void
    {
        config(['cars-images.csv_import_max_combos' => 2]);

        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<'CSV'
        Make,Model,Year,Transmission
        Toyota,RAV4,1997,A
        Toyota,Camry,1998,B
        Honda,Civic,2010,C
        CSV);

        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessageMatches('/exceeds.*2/i');

        app(CsvQueryImporter::class)->import($csv, $user);
    }

    public function test_skips_rows_with_invalid_year(): void
    {
        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<'CSV'
        Make,Model,Year,Transmission
        Toyota,RAV4,1997,A
        Toyota,Camry,abc,B
        Honda,Civic,1800,C
        CSV);

        $result = app(CsvQueryImporter::class)->import($csv, $user);

        $this->assertSame(1, CarSearch::count());
    }

    public function test_rejects_missing_required_columns(): void
    {
        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<'CSV'
        Make,Year,Transmission
        Toyota,1997,A
        CSV);

        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessageMatches('/Model/');

        app(CsvQueryImporter::class)->import($csv, $user);
    }

    public function test_captures_transmission_from_first_occurrence(): void
    {
        $user = User::factory()->create();
        $csv = $this->makeCsv(<<<'CSV'
        Make,Model,Year,Transmission
        Toyota,RAV4,1997,Automatic 4-spd
        Toyota,RAV4,1997,Manual 5-spd
        CSV);

        app(CsvQueryImporter::class)->import($csv, $user);

        $search = CarSearch::first();
        $this->assertSame('Automatic 4-spd', $search->transmission);
    }
}
