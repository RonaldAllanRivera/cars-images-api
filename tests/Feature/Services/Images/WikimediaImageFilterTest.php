<?php

namespace Tests\Feature\Services\Images;

use App\Services\Images\WikimediaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WikimediaImageFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_non_image_files_are_excluded_from_results(): void
    {
        Http::fake([
            '*' => Http::response([
                'query' => [
                    'pages' => [
                        [
                            'pageid' => 1,
                            'title' => 'File:Toyota Camry car.jpg',
                            'imageinfo' => [[
                                'url' => 'https://example.com/camry.jpg',
                                'thumburl' => 'https://example.com/camry-thumb.jpg',
                                'width' => 800,
                                'height' => 600,
                                'mime' => 'image/jpeg',
                                'extmetadata' => [],
                            ]],
                        ],
                        [
                            'pageid' => 2,
                            // Title contains "vehicles" so isCarImage() would keep it —
                            // the MIME filter must drop it first.
                            'title' => 'File:Trade-in-vehicles.pdf',
                            'imageinfo' => [[
                                'url' => 'https://example.com/doc.pdf',
                                'thumburl' => 'https://example.com/doc.pdf',
                                'width' => 0,
                                'height' => 0,
                                'mime' => 'application/pdf',
                                'extmetadata' => [],
                            ]],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $results = app(WikimediaClient::class)->searchCars('Toyota', 'Camry', 2020, null, null, false, 10);

        $this->assertCount(1, $results);
        $this->assertSame('https://example.com/camry.jpg', $results->first()['source_url']);
        $this->assertSame('image/jpeg', $results->first()['mime']);
    }
}
