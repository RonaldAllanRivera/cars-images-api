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
 * three queries from the previous import dropped to zero images. Within a
 * single multi-year search the same collapse occurred across years, because
 * the year-relaxed fallback returns an identical result set for every year
 * of a sparsely-catalogued model.
 *
 * Ownership is now part of the match key: a row belongs to one
 * (search, year, file) triple.
 */
class SharedImageOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function sharedPage(): array
    {
        return [
            'pageid' => 4242,
            'title' => 'File:Toyota RAV4 shared.jpg',
            'imageinfo' => [[
                'url' => 'https://example.com/shared.jpg',
                'thumburl' => 'https://example.com/shared-thumb.jpg',
                'width' => 800,
                'height' => 600,
                'mime' => 'image/jpeg',
                'extmetadata' => ['Categories' => ['value' => 'Toyota RAV4']],
            ]],
        ];
    }

    private function search(User $user, int $from, int $to): CarSearch
    {
        return app(CarImageSearchService::class)->createSearch(
            $user, 'Toyota', 'RAV4', $from, $to, null, null, false, 10
        );
    }

    public function test_a_second_search_does_not_steal_the_first_search_images(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => [$this->sharedPage()]]], 200)]);

        $user = User::factory()->create();
        $service = app(CarImageSearchService::class);

        $a = $this->search($user, 1997, 1997);
        $service->runSearch($a);

        $b = $this->search($user, 1998, 1998);
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

    public function test_each_search_records_its_own_year_for_a_shared_image(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => [$this->sharedPage()]]], 200)]);

        $user = User::factory()->create();
        $service = app(CarImageSearchService::class);

        $a = $this->search($user, 1997, 1997);
        $service->runSearch($a);

        $b = $this->search($user, 1998, 1998);
        $service->runSearch($b);

        $this->assertSame(1997, CarImage::where('car_search_id', $a->id)->first()->year);
        $this->assertSame(
            1998,
            CarImage::where('car_search_id', $b->id)->first()->year,
            'A later search must not relabel the earlier search\'s year.'
        );
    }

    public function test_a_multi_year_search_keeps_one_row_per_year(): void
    {
        // The year-relaxed fallback returns the same file for every year of a
        // sparsely-catalogued model. Those must not collapse into a single row
        // labelled with whichever year happened to run last.
        Http::fake(['*' => Http::response(['query' => ['pages' => [$this->sharedPage()]]], 200)]);

        $user = User::factory()->create();

        $search = $this->search($user, 1997, 1999);
        app(CarImageSearchService::class)->runSearch($search);

        $years = CarImage::where('car_search_id', $search->id)->pluck('year')->sort()->values()->all();

        $this->assertSame([1997, 1998, 1999], $years, 'Each year in the range keeps its own row.');
    }

    public function test_re_running_the_same_search_does_not_duplicate_rows(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => [$this->sharedPage()]]], 200)]);

        $user = User::factory()->create();
        $service = app(CarImageSearchService::class);

        $search = $this->search($user, 1997, 1997);
        $service->runSearch($search);
        $service->runSearch($search->fresh());

        $this->assertSame(
            1,
            CarImage::where('car_search_id', $search->id)->count(),
            'Re-running a search updates its rows in place rather than duplicating them.'
        );
    }
}
