<?php

namespace Tests\Feature\Services\Images;

use App\Models\CommonsCategoryLookup;
use App\Services\Images\CommonsCategoryLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommonsCategoryLocatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, string>  $existing
     */
    private function fakeExistingCategories(array $existing): void
    {
        Http::fake(function ($request) use ($existing) {
            $titles = $request->data()['titles'] ?? '';
            $name = str_replace('Category:', '', $titles);

            return Http::response(['query' => ['pages' => [
                in_array($name, $existing, true)
                    ? ['title' => $titles, 'pageid' => 1]
                    : ['title' => $titles, 'missing' => true],
            ]]], 200);
        });
    }

    public function test_it_returns_the_most_specific_category_that_exists(): void
    {
        $this->fakeExistingCategories(['Hyundai Santa Fe']);

        $category = app(CommonsCategoryLocator::class)->locate('Hyundai', 'Santa Fe XL AWD');

        $this->assertSame('Hyundai Santa Fe', $category);
    }

    public function test_a_resolved_category_is_persisted_and_not_re_probed(): void
    {
        $this->fakeExistingCategories(['Acura CL']);

        $locator = app(CommonsCategoryLocator::class);
        $this->assertSame('Acura CL', $locator->locate('Acura', '2.3CL/3.0CL'));

        $callsAfterFirst = count(Http::recorded());

        $this->assertSame('Acura CL', $locator->locate('Acura', '2.3CL/3.0CL'));
        $this->assertCount($callsAfterFirst, Http::recorded(), 'A cached hit must cost no API calls.');

        $this->assertDatabaseHas('commons_category_lookups', [
            'make' => 'Acura', 'model' => '2.3CL/3.0CL', 'category' => 'Acura CL',
        ]);
    }

    public function test_a_miss_is_recorded_and_not_re_probed(): void
    {
        $this->fakeExistingCategories([]);

        $locator = app(CommonsCategoryLocator::class);
        $this->assertNull($locator->locate('Saturn', 'L200'));

        $callsAfterFirst = count(Http::recorded());

        $this->assertNull($locator->locate('Saturn', 'L200'));
        $this->assertCount($callsAfterFirst, Http::recorded(), 'A known miss must cost no API calls.');

        $this->assertDatabaseHas('commons_category_lookups', [
            'make' => 'Saturn', 'model' => 'L200', 'category' => null,
        ]);
    }

    public function test_a_stale_miss_is_re_probed(): void
    {
        // Commons categories are created over time, so a miss expires.
        CommonsCategoryLookup::create([
            'make' => 'Acura', 'model' => '2.3CL/3.0CL',
            'category' => null, 'checked_at' => now()->subDays(31),
        ]);

        $this->fakeExistingCategories(['Acura CL']);

        $this->assertSame('Acura CL', app(CommonsCategoryLocator::class)->locate('Acura', '2.3CL/3.0CL'));
    }

    public function test_a_search_with_no_model_resolves_nothing(): void
    {
        // Category:Acura is the whole marque. A make-only search has no
        // category narrow enough to be meaningful, so it resolves to null
        // and stores nothing rather than attaching arbitrary Acuras.
        $this->fakeExistingCategories(['Acura']);

        $this->assertNull(app(CommonsCategoryLocator::class)->locate('Acura', null));
        $this->assertNull(app(CommonsCategoryLocator::class)->locate('Acura', '  '));
        Http::assertNothingSent();
    }

    public function test_a_resolved_category_never_expires(): void
    {
        // A category that exists does not stop existing, so an old hit is
        // still trusted and costs nothing to reuse.
        CommonsCategoryLookup::create([
            'make' => 'Acura', 'model' => '2.3CL/3.0CL',
            'category' => 'Acura CL', 'checked_at' => now()->subYears(3),
        ]);

        $this->fakeExistingCategories([]);

        $this->assertSame('Acura CL', app(CommonsCategoryLocator::class)->locate('Acura', '2.3CL/3.0CL'));
        Http::assertNothingSent();
    }
}
