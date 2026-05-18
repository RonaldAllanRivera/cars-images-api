<?php

namespace Tests\Feature\Services\Downloads;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use App\Services\Downloads\BatchCsvExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchCsvExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_selected_images_with_renamed_filenames_and_metadata(): void
    {
        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 'test.csv',
            'total_rows' => 1,
            'unique_combos' => 1,
            'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        $search = CarSearch::create([
            'make' => 'Toyota',
            'model' => 'RAV4',
            'from_year' => 1997,
            'to_year' => 1997,
            'color' => null,
            'transmission' => 'Automatic 4-spd',
            'transparent_background' => false,
            'images_per_year' => 5,
            'status' => 'completed',
            'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]);

        $img1 = CarImage::create([
            'car_search_id' => $search->id,
            'provider' => 'wikimedia',
            'provider_image_id' => 'A',
            'make' => 'Toyota',
            'model' => 'RAV4',
            'year' => 1997,
            'title' => 'A',
            'description' => null,
            'source_url' => 'https://example.com/a.jpg',
            'thumbnail_url' => 'https://example.com/a-thumb.jpg',
            'width' => 800,
            'height' => 600,
            'license' => null,
            'attribution' => null,
            'download_status' => 'not_downloaded',
            'metadata' => null,
        ]);

        $img2 = CarImage::create([
            'car_search_id' => $search->id,
            'provider' => 'wikimedia',
            'provider_image_id' => 'B',
            'make' => 'Toyota',
            'model' => 'RAV4',
            'year' => 1997,
            'title' => 'B',
            'description' => null,
            'source_url' => 'https://example.com/b.png',
            'thumbnail_url' => 'https://example.com/b-thumb.png',
            'width' => 800,
            'height' => 600,
            'license' => null,
            'attribution' => null,
            'download_status' => 'not_downloaded',
            'metadata' => null,
        ]);

        $exporter = app(BatchCsvExporter::class);
        $rows = $exporter->buildRows(collect([$img1, $img2]));

        $this->assertCount(3, $rows); // header + 2 data rows
        $this->assertSame(
            ['Year', 'Make', 'Model', 'Transmission', 'Filename', 'SourceUrl', 'SearchId', 'ImageId'],
            $rows[0]
        );

        $this->assertSame('1997', $rows[1][0]);
        $this->assertSame('Toyota', $rows[1][1]);
        $this->assertSame('RAV4', $rows[1][2]);
        $this->assertSame('Automatic 4-spd', $rows[1][3]);
        $this->assertSame('1997 Toyota RAV4.jpg', $rows[1][4]);
        $this->assertSame('https://example.com/a.jpg', $rows[1][5]);

        $this->assertSame('1997 Toyota RAV4 2.png', $rows[2][4]);
    }
}
