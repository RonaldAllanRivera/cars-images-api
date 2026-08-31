<?php

namespace Tests\Feature\Services\Images;

use App\Models\CarImage;
use App\Models\CarMake;
use App\Models\CarSearch;
use App\Models\User;
use App\Services\Images\CarImageSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The year-relaxed recall fallback must fire on "no *usable* images", and
 * whatever it returns must not claim to be year-specific.
 *
 * Real defect this pins, reproduced against the live Commons API for
 * "Acura 2.2CL/3.0CL" and "Acura 2.3CL/3.0CL":
 *
 *   "Acura CL 1997 car" -> 10 hits, every one a Honda Accord
 *   "Acura CL 1998 car" ->  3 hits, every one a PDF (newspapers)
 *   "Acura CL 1999 car" ->  0 hits
 *   "Acura CL car"      -> 10 hits, of which File:Clx.jpg is the one Acura
 *
 * Two failures followed. 1997 returned a non-empty response, so the client
 * never fell back — then every hit was rejected as off-make and the year
 * stored nothing. 1998 and 1999 both fell back to the identical year-less
 * query, drew the identical cache entry, and stored the identical file under
 * two different years, each row asserting a year the image never matched on.
 */
class YearRelaxedFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        CarMake::create(['name' => 'Acura']);
        CarMake::create(['name' => 'Honda']);
    }

    private function page(int $id, string $title, string $categories): array
    {
        return [
            'pageid' => $id,
            'title' => $title,
            'imageinfo' => [[
                'url' => "https://example.com/{$id}.jpg",
                'thumburl' => "https://example.com/{$id}-thumb.jpg",
                'width' => 800,
                'height' => 600,
                'mime' => 'image/jpeg',
                'extmetadata' => [
                    'Categories' => ['value' => $categories],
                ],
            ]],
        ];
    }

    private function acuraSearch(int $year): CarSearch
    {
        return app(CarImageSearchService::class)->createSearch(
            User::factory()->create(), 'Acura', 'CL', $year, $year, null, null, false, 10
        );
    }

    /**
     * Answer year-bearing queries with $yearPages and year-less ones with
     * $relaxedPages.
     *
     * The split is on "does the query carry any four-digit year", not on one
     * specific year: `Http::fake()` *merges* stub callbacks rather than
     * replacing them, so a test that calls this twice leaves the first closure
     * ahead of the second in the queue. Keyed on a literal year, the stale
     * closure answered the next year's query from the wrong branch.
     */
    private function fakeByQuery(array $yearPages, array $relaxedPages): void
    {
        Http::fake(function ($request) use ($yearPages, $relaxedPages) {
            $query = $request->data()['gsrsearch'] ?? '';
            $carriesYear = preg_match('/\b\d{4}\b/', $query) === 1;

            return Http::response([
                'query' => ['pages' => $carriesYear ? $yearPages : $relaxedPages],
            ], 200);
        });
    }

    public function test_falls_back_when_the_year_search_returns_only_off_make_results(): void
    {
        // The 1997 case: a full response that survives no filtering at all.
        $this->fakeByQuery(
            yearPages: [$this->page(1, 'File:Honda Accord CL3 europe.jpg', 'Honda Accord (1997, Europe)')],
            relaxedPages: [$this->page(2, 'File:Clx.jpg', 'Acura CL-X|Self-published work')],
        );

        $search = $this->acuraSearch(1997);
        app(CarImageSearchService::class)->runSearch($search);

        $stored = CarImage::where('car_search_id', $search->id)->get();

        $this->assertCount(
            1,
            $stored,
            'Ten Hondas is a non-empty response but zero usable images — the fallback must still fire.'
        );
        $this->assertSame('File:Clx.jpg', $stored->first()->title);
    }

    public function test_year_relaxed_images_are_not_marked_year_confirmed(): void
    {
        $this->fakeByQuery(
            yearPages: [],
            relaxedPages: [$this->page(2, 'File:Clx.jpg', 'Acura CL-X|Self-published work')],
        );

        $search = $this->acuraSearch(1999);
        app(CarImageSearchService::class)->runSearch($search);

        $image = CarImage::where('car_search_id', $search->id)->first();

        $this->assertNotNull($image);
        $this->assertFalse(
            (bool) $image->year_confirmed,
            'The image was matched by a query with no year in it, so the stored year is not evidence-backed.'
        );
    }

    public function test_year_specific_images_are_marked_year_confirmed(): void
    {
        $this->fakeByQuery(
            yearPages: [$this->page(3, 'File:2020 Acura TLX.jpg', 'Acura TLX')],
            relaxedPages: [$this->page(4, 'File:Acura anything.jpg', 'Acura')],
        );

        $search = $this->acuraSearch(2020);
        app(CarImageSearchService::class)->runSearch($search);

        $image = CarImage::where('car_search_id', $search->id)->first();

        $this->assertNotNull($image);
        $this->assertSame('File:2020 Acura TLX.jpg', $image->title);
        $this->assertTrue((bool) $image->year_confirmed);
    }

    public function test_two_adjacent_years_falling_back_are_both_flagged_rather_than_silently_duplicated(): void
    {
        // The reported symptom: 1998 and 1999 both fall back to the identical
        // year-less query and legitimately draw the same file, because Commons
        // holds exactly one usable Acura CL photograph. That is allowed — but
        // neither row may claim the image is of that model year.
        foreach ([1998, 1999] as $year) {
            $this->fakeByQuery(
                yearPages: [],
                relaxedPages: [$this->page(2, 'File:Clx.jpg', 'Acura CL-X|Self-published work')],
            );

            $search = $this->acuraSearch($year);
            app(CarImageSearchService::class)->runSearch($search);
        }

        $images = CarImage::whereIn('year', [1998, 1999])->get();

        $this->assertCount(2, $images);
        $this->assertTrue(
            $images->every(fn (CarImage $image) => $image->year_confirmed === false),
            'A file shared by two years must be flagged on both, not presented as two different model years.'
        );
    }
}
