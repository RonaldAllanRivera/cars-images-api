<?php

namespace Tests\Feature\Services\Images;

use App\Services\Images\WikimediaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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

    public function test_falls_back_to_year_less_query_when_year_search_is_empty(): void
    {
        Http::fake(function ($request) {
            $query = $request->data()['gsrsearch'] ?? '';

            // Year-specific query → empty; year-relaxed query → one image.
            if (str_contains($query, '1998')) {
                return Http::response(['query' => ['pages' => []]], 200);
            }

            return Http::response(['query' => ['pages' => [$this->imagePage()]]], 200);
        });

        $results = app(WikimediaClient::class)
            ->searchCars('Acura', 'CL', 1998, null, null, false, 10);

        $this->assertCount(1, $results);
        $this->assertSame('https://example.com/cl.jpg', $results->first()['source_url']);

        // Both the year query and the fallback query were sent.
        Http::assertSentCount(2);
    }

    public function test_does_not_fall_back_when_year_search_has_results(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [$this->imagePage()]]], 200),
        ]);

        $results = app(WikimediaClient::class)
            ->searchCars('Toyota', 'Camry', 2020, null, null, false, 10);

        $this->assertCount(1, $results);

        // Year query succeeded — no fallback call.
        Http::assertSentCount(1);
    }
}
