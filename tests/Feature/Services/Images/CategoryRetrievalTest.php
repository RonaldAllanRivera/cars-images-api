<?php

namespace Tests\Feature\Services\Images;

use App\Services\Images\WikimediaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CategoryRetrievalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function page(int $id, string $title, string $mime = 'image/jpeg'): array
    {
        return [
            'pageid' => $id,
            'title' => $title,
            'imageinfo' => [[
                'url' => "https://example.com/{$id}.jpg",
                'thumburl' => "https://example.com/{$id}-thumb.jpg",
                'width' => 800,
                'height' => 600,
                'mime' => $mime,
                'extmetadata' => [],
            ]],
        ];
    }

    public function test_category_exists_reports_presence(): void
    {
        Http::fake(function ($request) {
            $titles = $request->data()['titles'] ?? '';

            return Http::response(['query' => ['pages' => [
                str_contains($titles, 'Acura CL')
                    ? ['title' => $titles, 'pageid' => 1]
                    : ['title' => $titles, 'missing' => true],
            ]]], 200);
        });

        $client = app(WikimediaClient::class);

        $this->assertTrue($client->categoryExists('Acura CL'));
        $this->assertFalse($client->categoryExists('Acura Nonexistent'));
    }

    public function test_files_in_category_are_returned_with_image_info(): void
    {
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [
                $this->page(1, 'File:1997 Acura CL.jpg'),
            ]]], 200),
        ]);

        $files = app(WikimediaClient::class)->filesInCategory('Acura CL');

        $this->assertCount(1, $files);
        $this->assertSame('File:1997 Acura CL.jpg', $files->first()['title']);
        $this->assertSame('https://example.com/1.jpg', $files->first()['source_url']);
    }

    public function test_the_query_asks_for_the_deep_category(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => []]], 200)]);

        app(WikimediaClient::class)->filesInCategory('Acura CL');

        Http::assertSent(fn ($request) => ($request->data()['gsrsearch'] ?? '') === 'deepcategory:"Acura CL"');
    }

    public function test_non_image_files_are_excluded(): void
    {
        // Commons categories contain PDFs and DjVu documents.
        Http::fake([
            '*' => Http::response(['query' => ['pages' => [
                $this->page(1, 'File:Acura brochure.pdf', 'application/pdf'),
                $this->page(2, 'File:1997 Acura CL.jpg'),
            ]]], 200),
        ]);

        $files = app(WikimediaClient::class)->filesInCategory('Acura CL');

        $this->assertCount(1, $files);
        $this->assertSame('File:1997 Acura CL.jpg', $files->first()['title']);
    }

    public function test_pagination_follows_the_continue_cursor(): void
    {
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            $offset = (int) ($request->data()['gsroffset'] ?? 0);

            if ($offset === 0) {
                return Http::response([
                    'continue' => ['gsroffset' => 1],
                    'query' => ['pages' => [$this->page(1, 'File:1997 Acura CL.jpg')]],
                ], 200);
            }

            return Http::response(['query' => ['pages' => [$this->page(2, 'File:1999 Acura CL.jpg')]]], 200);
        });

        $files = app(WikimediaClient::class)->filesInCategory('Acura CL');

        $this->assertCount(2, $files, 'A truncated first page must be followed.');
        $this->assertSame(2, $calls);
    }

    public function test_results_are_cached_per_category(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => [
            $this->page(1, 'File:1997 Acura CL.jpg'),
        ]]], 200)]);

        $client = app(WikimediaClient::class);
        $client->filesInCategory('Acura CL');
        $client->filesInCategory('Acura CL');

        Http::assertSentCount(1);
    }

    public function test_forgetting_a_category_forces_a_refetch(): void
    {
        Http::fake(['*' => Http::response(['query' => ['pages' => [
            $this->page(1, 'File:1997 Acura CL.jpg'),
        ]]], 200)]);

        $client = app(WikimediaClient::class);
        $client->filesInCategory('Acura CL');
        $client->forgetCategory('Acura CL');
        $client->filesInCategory('Acura CL');

        Http::assertSentCount(2);
    }
}
