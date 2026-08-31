<?php

namespace Tests\Feature\Services\Images;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\User;
use App\Services\Images\CarImageSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One Wikimedia file may legitimately answer several searches.
 *
 * Images were upserted on `(provider, provider_image_id)` alone — a global
 * key — while `car_search_id` and `year` sat in the *update* payload. Any
 * file returned by two searches was therefore not copied but **moved**: the
 * second search stole the row and relabelled its year, and the first search
 * was left empty while still reporting `completed`.
 *
 * Observed on real data: re-importing a CSV produced three new queries
 * owning nine images while the total image count never changed, and the
 * three queries from the previous import dropped to zero images.
 *
 * Ownership is part of the match key: a row belongs to one
 * (search, year, file) triple. Two searches for the same model year — two
 * CSV imports of the same list, say — legitimately draw the same photograph
 * and must each keep their own row.
 */
class SharedImageOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Http::fake(function ($request) {
            if (isset($request->data()['titles'])) {
                return Http::response(['query' => ['pages' => [
                    ['title' => $request->data()['titles'], 'pageid' => 1],
                ]]], 200);
            }

            return Http::response(['query' => ['pages' => [[
                'pageid' => 4242,
                'title' => 'File:1997 Toyota RAV4.jpg',
                'imageinfo' => [[
                    'url' => 'https://example.com/shared.jpg',
                    'thumburl' => 'https://example.com/shared-thumb.jpg',
                    'width' => 800,
                    'height' => 600,
                    'mime' => 'image/jpeg',
                    'extmetadata' => ['Categories' => ['value' => 'Toyota RAV4']],
                ]],
            ]]]], 200);
        });
    }

    private function search(User $user): CarSearch
    {
        return app(CarImageSearchService::class)->createSearch(
            $user, 'Toyota', 'RAV4', 1997, 1997, null, null, false, 10
        );
    }

    public function test_a_second_search_does_not_steal_the_first_search_images(): void
    {
        $user = User::factory()->create();
        $service = app(CarImageSearchService::class);

        $a = $this->search($user);
        $service->runSearch($a);

        $b = $this->search($user);
        $service->runSearch($b);

        $this->assertSame(
            1,
            CarImage::where('car_search_id', $a->id)->count(),
            'The first search must keep its image when a later search returns the same file.'
        );
        $this->assertSame(
            1,
            CarImage::where('car_search_id', $b->id)->count(),
            'The second search must get its own row, not take over the first.'
        );
    }

    public function test_each_search_records_its_own_row_for_a_shared_image(): void
    {
        $user = User::factory()->create();
        $service = app(CarImageSearchService::class);

        $a = $this->search($user);
        $service->runSearch($a);

        $b = $this->search($user);
        $service->runSearch($b);

        $rows = CarImage::where('provider_image_id', '4242')->get();

        $this->assertCount(2, $rows, 'One file answering two searches is copied, not moved.');
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $rows->pluck('car_search_id')->all(),
        );
        $this->assertSame([1997, 1997], $rows->pluck('year')->all());
    }

    public function test_re_running_the_same_search_does_not_duplicate_rows(): void
    {
        $user = User::factory()->create();
        $service = app(CarImageSearchService::class);

        $search = $this->search($user);
        $service->runSearch($search);
        $service->runSearch($search->fresh());

        $this->assertSame(
            1,
            CarImage::where('car_search_id', $search->id)->count(),
            'Re-running a search updates its rows rather than adding duplicates.'
        );
    }
}
