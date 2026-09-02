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
                    ? ['title' => $titles, 'pageid' => 1, 'categoryinfo' => ['files' => 17, 'subcats' => 2]]
                    : ['title' => $titles, 'missing' => true],
            ]]], 200);
        });

        $client = app(WikimediaClient::class);

        $this->assertSame('Acura CL', $client->resolveCategory('Acura CL'));
        $this->assertNull($client->resolveCategory('Acura Nonexistent'));
    }

    public function test_an_invalid_title_does_not_read_as_an_existing_category(): void
    {
        // Verbatim shape of the live Commons response for a title containing
        // ">". Note there is no "missing" key at all — checking only for
        // `missing` reports the category as EXISTING, and the locator then
        // locks onto a name that can never hold files. Hits never expire, so
        // that poisons the lookup permanently.
        Http::fake(fn () => Http::response(['batchcomplete' => true, 'query' => ['pages' => [[
            'title' => 'Category:Ford F150 > 8500 lbs GVWR',
            'invalidreason' => 'The requested page title contains invalid characters: ">".',
            'invalid' => true,
        ]]]], 200));

        $this->assertNull(app(WikimediaClient::class)->resolveCategory('Ford F150 > 8500 lbs GVWR'));
    }

    public function test_an_empty_redirect_stub_does_not_count_as_an_existing_category(): void
    {
        // Category:Ford F150 is a {{Category redirect}} to Category:Ford F-150.
        // The page exists, so it is neither "missing" nor "invalid", and it is
        // not a MediaWiki hard redirect either — the redirect is a template.
        // It holds nothing. Accepting it stopped the candidate walk on a dead
        // name, cached forever, killing all 654 Ford F150 rows in the CSV.
        Http::fake(fn () => Http::response(['query' => ['pages' => [[
            'pageid' => 12345,
            'title' => 'Category:Ford F150',
            'categoryinfo' => ['size' => 0, 'pages' => 0, 'files' => 0, 'subcats' => 0],
        ]]]], 200));

        $this->assertNull(app(WikimediaClient::class)->resolveCategory('Ford F150'));
    }

    public function test_a_category_holding_only_subcategories_counts_as_existing(): void
    {
        // Category:Ford F-150 has 0 direct files but 17 subcategories, and
        // deepcategory: reads through them. Requiring direct files alone would
        // reject the very category that holds the photographs.
        Http::fake(fn () => Http::response(['query' => ['pages' => [[
            'pageid' => 999,
            'title' => 'Category:Ford F-150',
            'categoryinfo' => ['size' => 17, 'pages' => 0, 'files' => 0, 'subcats' => 17],
        ]]]], 200));

        $this->assertSame('Ford F-150', app(WikimediaClient::class)->resolveCategory('Ford F-150'));
    }

    public function test_a_category_redirect_is_followed_to_the_page_that_holds_the_files(): void
    {
        // {{category redirect}} is a template, not a MediaWiki redirect, so the
        // API cannot follow it and neither could any candidate built from the
        // CSV - "F150" can never be spelled "F-150". It is read out of the
        // wikitext instead.
        Http::fake(function ($request) {
            $name = str_replace('Category:', '', $request->data()['titles'] ?? '');

            if ($name === 'Ford F150') {
                return Http::response(['query' => ['pages' => [[
                    'pageid' => 1,
                    'title' => 'Category:Ford F150',
                    'categoryinfo' => ['files' => 0, 'subcats' => 0],
                    'revisions' => [['slots' => ['main' => ['content' => '{{category redirect|Ford F-150}}']]]],
                ]]]], 200);
            }

            return Http::response(['query' => ['pages' => [[
                'pageid' => 2,
                'title' => 'Category:'.$name,
                'categoryinfo' => ['files' => 0, 'subcats' => 17],
            ]]]], 200);
        });

        $this->assertSame('Ford F-150', app(WikimediaClient::class)->resolveCategory('Ford F150'));
    }

    public function test_a_category_redirect_cycle_terminates(): void
    {
        Http::fake(fn ($request) => Http::response(['query' => ['pages' => [[
            'pageid' => 1,
            'title' => $request->data()['titles'] ?? '',
            'categoryinfo' => ['files' => 0, 'subcats' => 0],
            'revisions' => [['slots' => ['main' => ['content' => '{{category redirect|Loop}}']]]],
        ]]]], 200));

        $this->assertNull(app(WikimediaClient::class)->resolveCategory('Loop'));
    }

    public function test_a_year_scopes_the_search_and_keys_the_cache(): void
    {
        // The 500-file cap is not enough on its own: Category:Toyota Corolla
        // holds 7,777 files and model year 2012 has no title in the first 500,
        // so the search stored nothing and looked exactly like a year Commons
        // has no photograph of. intitle: spends the budget on year-relevant
        // files - it narrows that category from 7,777 hits to 72.
        Http::fake(['*' => Http::response(['query' => ['pages' => [
            $this->page(1, 'File:2012 Toyota Corolla.jpg'),
        ]]], 200)]);

        $client = app(WikimediaClient::class);
        $client->filesInCategory('Toyota Corolla', 2012);

        Http::assertSent(fn ($request) => ($request->data()['gsrsearch'] ?? '')
            === 'deepcategory:"Toyota Corolla" intitle:2012');

        // Different years must not share a cache entry, or the first year
        // searched would answer for every other.
        $client->filesInCategory('Toyota Corolla', 2013);
        Http::assertSentCount(2);
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

    public function test_pagination_echoes_the_whole_continue_object(): void
    {
        // A generator=search query carrying prop=imageinfo continues in TWO
        // dimensions, and for this query shape the live API returns
        //   {"iicontinue": "...", "continue": "||"}
        // with NO gsroffset. Reading only gsroffset stops after one page and
        // silently truncates every category. MediaWiki's documented protocol
        // is to echo the entire continue object back, so that is what is
        // pinned here.
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            $data = $request->data();

            if (! isset($data['iicontinue'])) {
                return Http::response([
                    'continue' => ['iicontinue' => 'Second.jpg|20210805222232', 'continue' => '||'],
                    'query' => ['pages' => [$this->page(1, 'File:1997 Acura CL.jpg')]],
                ], 200);
            }

            return Http::response(['query' => ['pages' => [$this->page(2, 'File:1999 Acura CL.jpg')]]], 200);
        });

        $files = app(WikimediaClient::class)->filesInCategory('Acura CL');

        $this->assertCount(2, $files, 'A continued response must be followed, not treated as the end.');
        $this->assertSame(2, $calls);
        Http::assertSent(fn ($request) => ($request->data()['iicontinue'] ?? null) === 'Second.jpg|20210805222232');
    }

    public function test_a_file_repeated_across_pages_is_stored_once(): void
    {
        // Continuing on iicontinue re-lists pages whose imageinfo was
        // incomplete, so the same pageid legitimately arrives twice. Left
        // undeduped it consumes images_per_year slots and can collide with
        // the (car_search_id, year, provider, provider_image_id) unique index.
        Http::fake(function ($request) {
            if (! isset($request->data()['iicontinue'])) {
                return Http::response([
                    'continue' => ['iicontinue' => 'Repeat.jpg|1', 'continue' => '||'],
                    'query' => ['pages' => [$this->page(1, 'File:1997 Acura CL.jpg')]],
                ], 200);
            }

            return Http::response(['query' => ['pages' => [
                $this->page(1, 'File:1997 Acura CL.jpg'),
                $this->page(2, 'File:1999 Acura CL.jpg'),
            ]]], 200);
        });

        $files = app(WikimediaClient::class)->filesInCategory('Acura CL');

        $this->assertSame(['1', '2'], $files->pluck('provider_image_id')->all());
    }

    public function test_pagination_stops_when_a_continuation_never_ends(): void
    {
        // A category that keeps returning a continue token must not spin
        // forever inside a synchronous Filament request.
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;

            return Http::response([
                'continue' => ['iicontinue' => "page-{$calls}|1", 'continue' => '||'],
                'query' => ['pages' => [$this->page($calls, "File:1997 Acura CL {$calls}.jpg")]],
            ], 200);
        });

        app(WikimediaClient::class)->filesInCategory('Acura CL');

        $this->assertLessThanOrEqual(10, $calls, 'The request loop must be hard-bounded.');
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
