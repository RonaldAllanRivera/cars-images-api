<?php

namespace Tests\Feature\Services\Downloads;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use App\Services\Downloads\BatchZipBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class BatchZipBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_zip_with_renamed_entries_and_duplicate_suffix(): void
    {
        Http::fake([
            'https://example.com/a.jpg' => Http::response('AAAA', 200),
            'https://example.com/b.png' => Http::response('BBBB', 200),
        ]);

        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 'test.csv',
            'total_rows' => 1,
            'unique_combos' => 1,
            'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        $search = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 1997, 'to_year' => 1997,
            'color' => null, 'transmission' => null,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id,
            'csv_import_id' => $csvImport->id,
        ]);

        $img1 = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'A', 'make' => 'Toyota', 'model' => 'RAV4',
            'year' => 1997, 'title' => 'A', 'source_url' => 'https://example.com/a.jpg',
            'thumbnail_url' => 'https://example.com/a.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);

        $img2 = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'B', 'make' => 'Toyota', 'model' => 'RAV4',
            'year' => 1997, 'title' => 'B', 'source_url' => 'https://example.com/b.png',
            'thumbnail_url' => 'https://example.com/b.png',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'zip');

        $builder = app(BatchZipBuilder::class);
        $added = $builder->buildToFile(collect([$img1, $img2]), $tmpFile);

        $this->assertSame(2, $added);

        $zip = new ZipArchive();
        $opened = $zip->open($tmpFile);
        $this->assertTrue($opened === true, 'ZIP should open successfully');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        sort($names);

        $this->assertSame(['1997 Toyota RAV4 2.png', '1997 Toyota RAV4.jpg'], $names);

        $this->assertSame('AAAA', $zip->getFromName('1997 Toyota RAV4.jpg'));
        $this->assertSame('BBBB', $zip->getFromName('1997 Toyota RAV4 2.png'));

        $zip->close();
        unlink($tmpFile);
    }

    public function test_sends_descriptive_user_agent_when_fetching_images(): void
    {
        Http::fake([
            '*' => Http::response('AAAA', 200),
        ]);

        $user = User::factory()->create();
        $search = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id,
        ]);
        $img = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'A', 'make' => 'Toyota', 'model' => 'RAV4',
            'year' => 1997, 'title' => 'A', 'source_url' => 'https://upload.wikimedia.org/a.jpg',
            'thumbnail_url' => 'https://upload.wikimedia.org/a.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'zip');
        app(BatchZipBuilder::class)->buildToFile(collect([$img]), $tmpFile);
        @unlink($tmpFile);

        Http::assertSent(function ($request) {
            $ua = $request->header('User-Agent')[0] ?? '';

            return str_contains($ua, 'CarsImagesApi');
        });
    }

    public function test_returns_zero_when_all_image_fetches_fail(): void
    {
        Http::fake([
            '*' => Http::response('Forbidden', 403),
        ]);

        $user = User::factory()->create();
        $search = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 1997, 'to_year' => 1997,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id,
        ]);
        $img = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'A', 'make' => 'Toyota', 'model' => 'RAV4',
            'year' => 1997, 'title' => 'A', 'source_url' => 'https://upload.wikimedia.org/a.jpg',
            'thumbnail_url' => 'https://upload.wikimedia.org/a.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'zip');
        $added = app(BatchZipBuilder::class)->buildToFile(collect([$img]), $tmpFile);
        @unlink($tmpFile);

        $this->assertSame(0, $added);
    }

    public function test_fetches_width_capped_wikimedia_thumbnail(): void
    {
        config(['cars-images.download_max_width' => 1600]);

        Http::fake([
            '*' => Http::response('IMG', 200),
        ]);

        $user = User::factory()->create();
        $search = CarSearch::create([
            'make' => 'Acura', 'model' => 'NSX',
            'from_year' => 1999, 'to_year' => 1999,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id,
        ]);
        $img = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'A', 'make' => 'Acura', 'model' => 'NSX',
            'year' => 1999, 'title' => 'A',
            'source_url' => 'https://upload.wikimedia.org/wikipedia/commons/4/47/Foo.jpg',
            'thumbnail_url' => 'https://upload.wikimedia.org/wikipedia/commons/4/47/Foo.jpg',
            'width' => 4000, 'height' => 3000, 'download_status' => 'not_downloaded',
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'zip');
        $added = app(BatchZipBuilder::class)->buildToFile(collect([$img]), $tmpFile);
        @unlink($tmpFile);

        $this->assertSame(1, $added);

        Http::assertSent(function ($request) {
            return $request->url()
                === 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/47/Foo.jpg/1600px-Foo.jpg';
        });
    }

    public function test_falls_back_to_original_when_thumbnail_fetch_fails(): void
    {
        config(['cars-images.download_max_width' => 1600]);

        $thumbUrl = 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/47/Foo.jpg/1600px-Foo.jpg';
        $originalUrl = 'https://upload.wikimedia.org/wikipedia/commons/4/47/Foo.jpg';

        Http::fake([
            $thumbUrl => Http::response('boom', 500),
            $originalUrl => Http::response('ORIGINAL', 200),
        ]);

        $user = User::factory()->create();
        $search = CarSearch::create([
            'make' => 'Acura', 'model' => 'NSX',
            'from_year' => 1999, 'to_year' => 1999,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => 'completed', 'requested_by' => $user->id,
        ]);
        $img = CarImage::create([
            'car_search_id' => $search->id, 'provider' => 'wikimedia',
            'provider_image_id' => 'A', 'make' => 'Acura', 'model' => 'NSX',
            'year' => 1999, 'title' => 'A',
            'source_url' => $originalUrl,
            'thumbnail_url' => $originalUrl,
            'width' => 4000, 'height' => 3000, 'download_status' => 'not_downloaded',
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'zip');
        $added = app(BatchZipBuilder::class)->buildToFile(collect([$img]), $tmpFile);

        $this->assertSame(1, $added);

        $zip = new ZipArchive();
        $zip->open($tmpFile);
        $this->assertSame('ORIGINAL', $zip->getFromName('1999 Acura NSX.jpg'));
        $zip->close();
        @unlink($tmpFile);
    }
}
