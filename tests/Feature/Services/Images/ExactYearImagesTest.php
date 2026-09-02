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
 * The reported defect, end to end.
 *
 * Searching Acura 2.3CL/3.0CL for 1997, 1998 and 1999 stored one photograph
 * three times — and it was File:Clx.jpg, the Acura CL-X *concept car*, found
 * by a year-less full-text query that returned the same result for every year.
 *
 * Category:Acura CL YA1 holds the real cars. 1997 and 1999 are named exactly;
 * 1998 exists only inside "1998-1999" ranges, which the exact-year policy
 * excludes. So 1998 stores nothing, on purpose.
 */
class ExactYearImagesTest extends TestCase
{
    use RefreshDatabase;

    /** Titles as they really appear in Category:Acura CL YA1. */
    private const CL_FILES = [
        [1, 'File:1997 Acura CL -- 01-28-2010.jpg'],
        [2, 'File:1997 Acura CL, rear 8.2.20.jpg'],
        [3, 'File:1998-1999 Acura CL -- 04-11-2012 1.JPG'],
        [4, "File:'98-'99 Acura CL.jpg"],
        [5, 'File:1999 Acura CL 3.0.jpg'],
        [6, 'File:1999 Acura CL.jpg'],
        [7, 'File:1st gen Acura CL.JPG'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Http::fake(function ($request) {
            $data = $request->data();

            if (isset($data['titles'])) {
                $exists = $data['titles'] === 'Category:Acura CL';

                return Http::response(['query' => ['pages' => [
                    $exists
                        ? ['title' => $data['titles'], 'pageid' => 1, 'categoryinfo' => ['files' => 17, 'subcats' => 0]]
                        : ['title' => $data['titles'], 'missing' => true],
                ]]], 200);
            }

            return Http::response(['query' => ['pages' => array_map(
                fn (array $file) => [
                    'pageid' => $file[0],
                    'title' => $file[1],
                    'imageinfo' => [[
                        'url' => "https://example.com/{$file[0]}.jpg",
                        'thumburl' => "https://example.com/{$file[0]}-thumb.jpg",
                        'width' => 800,
                        'height' => 600,
                        'mime' => 'image/jpeg',
                        'extmetadata' => [],
                    ]],
                ],
                self::CL_FILES,
            )]], 200);
        });
    }

    private function runYear(int $year): CarSearch
    {
        $search = app(CarImageSearchService::class)->createSearch(
            User::factory()->create(), 'Acura', '2.3CL/3.0CL', $year, $year, null, null, false, 10
        );

        app(CarImageSearchService::class)->runSearch($search);

        return $search->fresh();
    }

    public function test_each_year_gets_only_photographs_naming_that_year(): void
    {
        $search = $this->runYear(1997);

        $titles = CarImage::where('car_search_id', $search->id)->pluck('title')->all();
        sort($titles);

        $this->assertSame([
            'File:1997 Acura CL -- 01-28-2010.jpg',
            'File:1997 Acura CL, rear 8.2.20.jpg',
        ], $titles);
    }

    public function test_a_year_named_only_inside_a_range_stores_nothing(): void
    {
        $search = $this->runYear(1998);

        $this->assertSame(0, CarImage::where('car_search_id', $search->id)->count());
        $this->assertSame('completed', $search->status, 'An empty result is a completed search, not a failed one.');
        $this->assertSame('Acura CL', $search->commons_category, 'The category resolved; it simply had no 1998 photograph.');
    }

    public function test_adjacent_years_never_share_a_photograph(): void
    {
        $first = $this->runYear(1997);
        $second = $this->runYear(1999);

        $a = CarImage::where('car_search_id', $first->id)->pluck('provider_image_id');
        $b = CarImage::where('car_search_id', $second->id)->pluck('provider_image_id');

        $this->assertNotEmpty($a);
        $this->assertNotEmpty($b);
        $this->assertEmpty($a->intersect($b), 'A title naming 1997 cannot also name 1999.');
    }

    public function test_the_concept_car_is_no_longer_reachable(): void
    {
        $search = $this->runYear(1999);

        $this->assertNotContains(
            'File:Clx.jpg',
            CarImage::where('car_search_id', $search->id)->pluck('title')->all()
        );
    }

    public function test_images_per_year_does_not_truncate_retrieval(): void
    {
        // Regression guard: applying the limit to the fetch instead of to the
        // filtered result would hand the year filter an arbitrary slice.
        $search = app(CarImageSearchService::class)->createSearch(
            User::factory()->create(), 'Acura', '2.3CL/3.0CL', 1999, 1999, null, null, false, 1
        );

        app(CarImageSearchService::class)->runSearch($search);

        $this->assertSame(1, CarImage::where('car_search_id', $search->id)->count());
        $this->assertStringContainsString(
            '1999',
            (string) CarImage::where('car_search_id', $search->id)->value('title'),
            'The one stored image must still be a 1999 car, not an arbitrary first file.'
        );
    }

    public function test_a_model_with_no_category_stores_nothing(): void
    {
        $search = app(CarImageSearchService::class)->createSearch(
            User::factory()->create(), 'Saturn', 'L200', 2003, 2003, null, null, false, 10
        );

        app(CarImageSearchService::class)->runSearch($search);

        $this->assertSame(0, CarImage::where('car_search_id', $search->id)->count());
        $this->assertNull($search->fresh()->commons_category);
        $this->assertSame('completed', $search->fresh()->status);
    }

    public function test_stored_images_are_marked_year_confirmed(): void
    {
        $search = $this->runYear(1997);

        $this->assertTrue(
            CarImage::where('car_search_id', $search->id)->get()
                ->every(fn (CarImage $image) => $image->year_confirmed === true)
        );
    }
}
