<?php

namespace Tests\Feature;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BulkDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function setupBatchWithTwoImages(): array
    {
        Http::fake([
            'https://example.com/a.jpg' => Http::response('AAAA', 200),
            'https://example.com/b.jpg' => Http::response('BBBB', 200),
        ]);

        $user = User::factory()->create();
        $csvImport = CsvImport::create([
            'original_filename' => 'test.csv',
            'total_rows' => 1, 'unique_combos' => 1, 'duplicates_skipped' => 0,
            'imported_by' => $user->id,
        ]);

        $search = CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 1997, 'to_year' => 1997,
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
            'year' => 1997, 'title' => 'B', 'source_url' => 'https://example.com/b.jpg',
            'thumbnail_url' => 'https://example.com/b.jpg',
            'width' => 800, 'height' => 600, 'download_status' => 'not_downloaded',
        ]);

        return [$user, [$img1, $img2]];
    }

    public function test_zip_endpoint_returns_zip_with_renamed_files(): void
    {
        [$user, $images] = $this->setupBatchWithTwoImages();

        $response = $this->actingAs($user)->post('/batch-downloads/zip', [
            'image_ids' => [$images[0]->id, $images[1]->id],
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.zip', $disposition);
    }

    public function test_zip_endpoint_fails_cleanly_when_no_images_downloadable(): void
    {
        // Every image fetch is rejected by the provider (e.g. 403).
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

        $response = $this->actingAs($user)->post('/batch-downloads/zip', [
            'image_ids' => [$img->id],
        ]);

        // A clean 422 — not a 500 FileNotFoundException on a missing temp file.
        $response->assertStatus(422);
    }

    public function test_csv_endpoint_returns_manifest_with_renamed_filenames(): void
    {
        [$user, $images] = $this->setupBatchWithTwoImages();

        $response = $this->actingAs($user)->post('/batch-downloads/csv', [
            'image_ids' => [$images[0]->id, $images[1]->id],
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $response->streamedContent();
        $this->assertStringContainsString('Year,Make,Model,Transmission,Filename,SourceUrl,SearchId,ImageId', $body);
        $this->assertStringContainsString('1997 Toyota RAV4.jpg', $body);
        $this->assertStringContainsString('1997 Toyota RAV4 2.jpg', $body);
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->postJson('/batch-downloads/zip', ['image_ids' => [1]])->assertStatus(401);
        $this->postJson('/batch-downloads/csv', ['image_ids' => [1]])->assertStatus(401);
    }

    public function test_endpoints_reject_empty_selection(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/batch-downloads/zip', ['image_ids' => []])->assertStatus(422);
        $this->actingAs($user)->postJson('/batch-downloads/csv', ['image_ids' => []])->assertStatus(422);
    }
}
