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
 * A search for one make must not store photographs of another.
 *
 * Real defect this pins: searching "Acura 2.2CL/3.0CL 1997" returned four
 * Honda Accord photographs. The model string normalizes to "CL", and
 * Wikimedia's full-text search matches the Accord's chassis code "CL3",
 * so an Acura query came back full of Hondas. They were correctly flagged
 * `make_confirmed = false`, but flagging is not enough when the whole
 * result set is the wrong car — the reviewer is left with nothing usable.
 */
class OffMakeFilteringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $acura = CarMake::create(['name' => 'Acura']);
        $acura->models()->create(['name' => 'CL']);
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

    private function acuraSearch(User $user): CarSearch
    {
        return app(CarImageSearchService::class)->createSearch(
            $user, 'Acura', 'CL', 1997, 1997, null, null, false, 10
        );
    }

    public function test_photographs_of_another_make_are_not_stored(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [
                $this->page(1, 'File:Honda Accord CL3 europe.jpg', 'Silver Honda sedans|Honda Accord (1997, Europe)'),
                $this->page(2, 'File:Clx.jpg', 'Acura CL-X|Self-published work'),
            ]]], 200),
        ]);

        $user = User::factory()->create();
        $search = $this->acuraSearch($user);

        app(CarImageSearchService::class)->runSearch($search);

        $stored = CarImage::where('car_search_id', $search->id)->get();

        $this->assertCount(1, $stored, 'Only the Acura image belongs in an Acura search.');
        $this->assertSame('File:Clx.jpg', $stored->first()->title);
    }

    public function test_a_badge_engineered_page_naming_both_makes_is_kept(): void
    {
        // Wikimedia genuinely files some Acuras under Honda. When the page
        // names the searched make too, it is the right car and must survive.
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [
                $this->page(3, 'File:Honda Accord (Acura CL) 1997.jpg', 'Honda Accord|Acura CL'),
            ]]], 200),
        ]);

        $user = User::factory()->create();
        $search = $this->acuraSearch($user);

        app(CarImageSearchService::class)->runSearch($search);

        $this->assertSame(1, CarImage::where('car_search_id', $search->id)->count());
    }

    public function test_an_image_naming_no_make_is_kept_and_flagged_for_review(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [
                $this->page(4, 'File:Silver coupe on a driveway.jpg', 'Self-published work'),
            ]]], 200),
        ]);

        $user = User::factory()->create();
        $search = $this->acuraSearch($user);

        app(CarImageSearchService::class)->runSearch($search);

        $image = CarImage::where('car_search_id', $search->id)->first();

        $this->assertNotNull($image, 'An unidentifiable car is kept — absence of evidence is not evidence of a wrong car.');
        $this->assertFalse((bool) $image->make_confirmed, 'It must still be flagged as unconfirmed for review.');
    }

    public function test_the_search_still_completes_when_every_result_is_off_make(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [
                $this->page(5, 'File:Honda Accord VI.jpg', 'Honda Accord (1997, North America)'),
            ]]], 200),
        ]);

        $user = User::factory()->create();
        $search = $this->acuraSearch($user);

        app(CarImageSearchService::class)->runSearch($search);

        $this->assertSame(0, CarImage::where('car_search_id', $search->id)->count());
        $this->assertSame('completed', $search->fresh()->status, 'An empty result set is a completed search, not a failed one.');
    }
}
