<?php

namespace Tests\Feature\Services\Images;

use App\Exceptions\WikimediaBlockedException;
use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\User;
use App\Services\Images\CarImageSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A refresh that fails must not destroy the images it was replacing.
 *
 * `refreshSearch()` deletes the search's images and then re-runs it.
 * The delete used to sit *outside* the transaction `runSearch()` opens,
 * so when Wikimedia answered 429 the rollback restored nothing: every
 * reviewed image was gone permanently (`CarImage` has no soft deletes),
 * and because the `status = running` update rolled back too, the row
 * still read `completed` — a search that silently lost its contents.
 *
 * Both entry points hit this: the "Refresh from Wikimedia" header action
 * on the view page, and `EditCarSearch::afterSave()`, which fires on
 * every save whether or not the filters changed.
 */
class RefreshSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function searchWithImages(int $count = 3): CarSearch
    {
        $user = User::factory()->create();

        $search = CarSearch::create([
            'make' => 'Toyota',
            'model' => 'RAV4',
            'from_year' => 2020,
            'to_year' => 2020,
            'transparent_background' => false,
            'images_per_year' => 10,
            'status' => 'completed',
            'requested_by' => $user->id,
        ]);

        foreach (range(1, $count) as $i) {
            CarImage::create([
                'car_search_id' => $search->id,
                'provider' => 'wikimedia',
                'provider_image_id' => "existing-{$i}",
                'make' => 'Toyota',
                'model' => 'RAV4',
                'year' => 2020,
                'title' => "File:Toyota RAV4 {$i}.jpg",
                'source_url' => "https://upload.wikimedia.org/{$i}.jpg",
                'thumbnail_url' => "https://upload.wikimedia.org/{$i}.jpg",
                'width' => 800,
                'height' => 600,
                'download_status' => 'not_downloaded',
            ]);
        }

        return $search;
    }

    public function test_a_blocked_refresh_keeps_the_existing_images(): void
    {
        $search = $this->searchWithImages(3);

        Http::fake(['*' => Http::response('Too Many Requests', 429)]);

        try {
            app(CarImageSearchService::class)->refreshSearch($search);
            $this->fail('A 429 from Wikimedia should surface as WikimediaBlockedException.');
        } catch (WikimediaBlockedException) {
            // expected
        }

        $this->assertSame(
            3,
            CarImage::where('car_search_id', $search->id)->count(),
            'A failed refresh must not destroy the images it was replacing.'
        );
    }

    public function test_a_blocked_refresh_marks_the_search_failed_rather_than_leaving_it_completed(): void
    {
        $search = $this->searchWithImages(2);

        Http::fake(['*' => Http::response('Too Many Requests', 429)]);

        try {
            app(CarImageSearchService::class)->refreshSearch($search);
        } catch (WikimediaBlockedException) {
            // expected
        }

        $this->assertSame(
            'failed',
            $search->fresh()->status,
            'A search whose refresh failed must not keep reporting "completed".'
        );
    }

    public function test_a_successful_refresh_replaces_the_images(): void
    {
        $search = $this->searchWithImages(3);

        Http::fake(function ($request) {
            if (isset($request->data()['titles'])) {
                return Http::response(['query' => ['pages' => [
                    ['title' => $request->data()['titles'], 'pageid' => 1],
                ]]], 200);
            }

            return Http::response(['query' => ['pages' => [[
                'pageid' => 999,
                'title' => 'File:2020 Toyota RAV4 fresh.jpg',
                'imageinfo' => [[
                    'url' => 'https://example.com/fresh.jpg',
                    'thumburl' => 'https://example.com/fresh-thumb.jpg',
                    'width' => 800,
                    'height' => 600,
                    'mime' => 'image/jpeg',
                    'extmetadata' => ['Categories' => ['value' => 'Toyota RAV4']],
                ]],
            ]]]], 200);
        });

        app(CarImageSearchService::class)->refreshSearch($search);

        $images = CarImage::where('car_search_id', $search->id)->get();

        $this->assertCount(1, $images, 'A successful refresh replaces the old set.');
        $this->assertSame('File:2020 Toyota RAV4 fresh.jpg', $images->first()->title);
        $this->assertSame('completed', $search->fresh()->status);
    }
}
