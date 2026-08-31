<?php

namespace Tests\Feature\Services\Images;

use App\Services\Images\WikimediaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The client issues exactly the query it is asked for.
 *
 * The year-relaxed retry used to live here and now lives in
 * `CarImageSearchService` — see `YearRelaxedFallbackTest` for the behaviour
 * itself. What remains to pin at this layer is that the client does not
 * second-guess its caller: an empty year-specific result stays empty, so the
 * service can tell "the year found nothing" apart from "the year found only
 * off-make results" and retry on its own terms.
 */
class WikimediaRecallFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function imagePage(): array
    {
        return [
            'pageid' => 1,
            'title' => 'File:Acura CL car.jpg',
            'imageinfo' => [[
                'url' => 'https://example.com/cl.jpg',
                'thumburl' => 'https://example.com/cl-thumb.jpg',
                'width' => 800,
                'height' => 600,
                'mime' => 'image/jpeg',
                'extmetadata' => [],
            ]],
        ];
    }

    public function test_an_empty_year_search_is_not_silently_retried_without_the_year(): void
    {
        Http::fake(function ($request) {
            $query = $request->data()['gsrsearch'] ?? '';

            return Http::response([
                'query' => ['pages' => str_contains($query, '1998') ? [] : [$this->imagePage()]],
            ], 200);
        });

        $results = app(WikimediaClient::class)
            ->searchCars('Acura', 'CL', 1998, null, null, false, 10);

        $this->assertTrue($results->isEmpty(), 'The client reports the year query honestly; the caller decides what to do.');
        Http::assertSentCount(1);
    }

    public function test_a_null_year_runs_the_year_relaxed_query(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [$this->imagePage()]]], 200),
        ]);

        $results = app(WikimediaClient::class)
            ->searchCars('Acura', 'CL', null, null, null, false, 10);

        $this->assertCount(1, $results);
        $this->assertSame('https://example.com/cl.jpg', $results->first()['source_url']);

        Http::assertSent(function ($request) {
            return ($request->data()['gsrsearch'] ?? '') === 'Acura CL car';
        });
    }

    public function test_a_year_is_included_in_the_query_when_given(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [$this->imagePage()]]], 200),
        ]);

        app(WikimediaClient::class)
            ->searchCars('Toyota', 'Camry', 2020, null, null, false, 10);

        Http::assertSent(function ($request) {
            return ($request->data()['gsrsearch'] ?? '') === 'Toyota Camry 2020 car';
        });
    }
}
