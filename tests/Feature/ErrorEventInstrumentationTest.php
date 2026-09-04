<?php

namespace Tests\Feature;

use App\Exceptions\WikimediaBlockedException;
use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\ErrorEvent;
use App\Models\User;
use App\Models\WikimediaBlockEvent;
use App\Services\Downloads\BatchZipBuilder;
use App\Services\Images\CarImageZipService;
use App\Services\Imports\CsvImportException;
use App\Services\Imports\CsvQueryImporter;
use App\Services\Search\RunSearchQueryAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every place in the pipeline that used to lose the reason for a failure now
 * records one. Each test asserts the surrounding behaviour is unchanged too:
 * logging is an addition to these paths, not a redesign of them.
 */
class ErrorEventInstrumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_search_run_records_why(): void
    {
        Http::fake(['*' => Http::response('upstream exploded', 500)]);

        $search = $this->importedSearch();

        try {
            app(RunSearchQueryAction::class)->execute($search);
        } catch (\Throwable) {
            // The action rethrows by design; the log row is what is under test.
        }

        $event = ErrorEvent::where('context', ErrorEvent::CONTEXT_SEARCH_RUN)->sole();

        $this->assertSame($search->id, $event->car_search_id);
        $this->assertSame($search->csv_import_id, $event->csv_import_id);
        $this->assertNotNull($event->exception_class);
        $this->assertStringContainsString('Toyota', $event->message);
        $this->assertSame('failed', $search->fresh()->status);
    }

    public function test_a_wikimedia_block_is_mirrored_into_the_log(): void
    {
        Http::fake(['*' => Http::response('Rate limit exceeded', 429, ['Retry-After' => '60'])]);

        $search = $this->importedSearch();

        try {
            app(RunSearchQueryAction::class)->execute($search);
            $this->fail('Expected WikimediaBlockedException');
        } catch (WikimediaBlockedException) {
            // Expected: the bulk-run caller relies on this to halt the loop.
        }

        // The purpose-built table still drives the halt logic and must be intact.
        $this->assertSame(1, WikimediaBlockEvent::count());

        $event = ErrorEvent::where('context', ErrorEvent::CONTEXT_WIKIMEDIA_BLOCK)->sole();

        $this->assertSame(429, $event->details['http_status']);
        $this->assertSame(60, $event->details['retry_after_seconds']);
        $this->assertStringContainsString('Rate limit', $event->details['response_excerpt']);
        $this->assertSame($search->id, $event->car_search_id);
    }

    public function test_an_image_the_batch_zip_cannot_fetch_is_logged_and_the_zip_still_builds(): void
    {
        Http::fake([
            'https://example.com/good.jpg' => Http::response($this->jpegBytes(), 200),
            'https://example.com/gone.jpg' => Http::response('Not Found', 404),
        ]);

        $search = $this->importedSearch();
        $good = $this->image($search, 'GOOD', 'https://example.com/good.jpg');
        $gone = $this->image($search, 'GONE', 'https://example.com/gone.jpg');

        $target = tempnam(sys_get_temp_dir(), 'zip');
        $added = app(BatchZipBuilder::class)->buildToFile(collect([$good, $gone]), $target);

        $this->assertSame(1, $added, 'The good image is still archived; only the failure is skipped.');

        $event = ErrorEvent::where('context', ErrorEvent::CONTEXT_IMAGE_DOWNLOAD)->sole();

        $this->assertSame($gone->id, $event->car_image_id);
        $this->assertSame(404, $event->details['http_status']);
        $this->assertSame('https://example.com/gone.jpg', $event->details['url']);

        @unlink($target);
    }

    public function test_an_image_the_per_search_zip_cannot_fetch_is_logged(): void
    {
        Http::fake([
            'https://example.com/good.jpg' => Http::response('AAAA', 200),
            'https://example.com/gone.jpg' => Http::response('Not Found', 404),
        ]);

        $search = $this->importedSearch();
        $good = $this->image($search, 'GOOD', 'https://example.com/good.jpg');
        $gone = $this->image($search, 'GONE', 'https://example.com/gone.jpg');

        // A good image is included because ZipArchive writes no file at all for
        // an empty archive, and this path serves the file it just built.
        app(CarImageZipService::class)->downloadZip(collect([$good, $gone]));

        $event = ErrorEvent::where('context', ErrorEvent::CONTEXT_IMAGE_DOWNLOAD)->sole();

        $this->assertSame($gone->id, $event->car_image_id);
        $this->assertSame(404, $event->details['http_status']);
    }

    public function test_a_rejected_csv_upload_is_logged_with_its_filename(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('broken.csv', "Colour,Size\nred,large\n");

        try {
            app(CsvQueryImporter::class)->import($file, $user);
            $this->fail('Expected CsvImportException');
        } catch (CsvImportException) {
            // Expected: the upload is rejected before anything is stored.
        }

        $event = ErrorEvent::where('context', ErrorEvent::CONTEXT_CSV_UPLOAD)->sole();

        $this->assertStringContainsString('Missing required columns', $event->message);
        $this->assertSame('broken.csv', $event->details['filename']);
        $this->assertNull($event->csv_import_id, 'A rejected upload never becomes an import.');
    }

    public function test_each_rejected_csv_row_is_logged_against_the_import(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('rows.csv', implode("\n", [
            'Make,Model,Year',
            'Toyota,Corolla,2019',
            'Honda,,2020',
            'Ford,Focus,nineteen',
            'Mazda,MX-5,1899',
            '',
        ]));

        $result = app(CsvQueryImporter::class)->import($file, $user);

        $this->assertSame(3, $result->skippedInvalidRows, 'The existing count is unchanged.');

        $events = ErrorEvent::where('context', ErrorEvent::CONTEXT_CSV_ROW)->get();

        $this->assertCount(3, $events);
        $this->assertTrue($events->every(fn ($e) => $e->csv_import_id === $result->csvImport->id));

        $byRow = $events->keyBy(fn ($e) => $e->details['row_number']);

        $this->assertStringContainsString('Honda', $byRow[2]->details['raw_row']);
        $this->assertStringContainsString('missing', strtolower($byRow[2]->message));
        $this->assertStringContainsString('nineteen', $byRow[3]->details['raw_row']);
        $this->assertStringContainsString('1899', $byRow[4]->message);
    }

    private function importedSearch(): CarSearch
    {
        $user = User::factory()->create();

        $import = CsvImport::create([
            'original_filename' => 'test.csv',
            'total_rows' => 1,
            'unique_combos' => 1,
            'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        return CarSearch::create([
            'make' => 'Toyota',
            'model' => 'RAV4',
            'from_year' => 1997,
            'to_year' => 1997,
            'color' => null,
            'transmission' => null,
            'transparent_background' => false,
            'images_per_year' => 5,
            'status' => 'pending',
            'requested_by' => $user->id,
            'csv_import_id' => $import->id,
        ]);
    }

    private function image(CarSearch $search, string $id, string $url): CarImage
    {
        return CarImage::create([
            'car_search_id' => $search->id,
            'provider' => 'wikimedia',
            'provider_image_id' => $id,
            'make' => 'Toyota',
            'model' => 'RAV4',
            'year' => 1997,
            'title' => $id,
            'source_url' => $url,
            'thumbnail_url' => $url,
            'width' => 800,
            'height' => 600,
            'download_status' => 'not_downloaded',
        ]);
    }

    private function jpegBytes(): string
    {
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
