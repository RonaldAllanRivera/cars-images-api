<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\ApiTestCase;

class ExportDownloadTest extends ApiTestCase
{
    /**
     * A real JPEG. ImageResizer runs GD over whatever the ZIP builder fetches,
     * so arbitrary bytes would make the build fail for the wrong reason - and a
     * 502 could then pass for a working test. Same helper as
     * ErrorEventInstrumentationTest.
     */
    private function jpegBytes(): string
    {
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function mint(string $format, array $filters = []): string
    {
        return $this->postJson('/api/v1/exports', ['format' => $format] + $filters)
            ->assertCreated()
            ->json('url');
    }

    public function test_a_csv_link_downloads_only_the_filtered_rows(): void
    {
        $user = $this->actingAsApiUser();
        $search = $this->search($user);
        $this->image($search, ['review_status' => 'approved', 'title' => 'File:Kept.jpg']);
        $this->image($search, ['review_status' => 'rejected', 'title' => 'File:Dropped.jpg']);

        $response = $this->get($this->mint('csv', ['review_status' => 'approved']));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('Dropped', $response->streamedContent());
    }

    public function test_the_download_needs_no_bearer_token(): void
    {
        // The whole point: the system browser follows this link with no token.
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));
        $url = $this->mint('csv');

        $this->app['auth']->forgetGuards();

        $this->get($url)->assertOk();
    }

    public function test_a_zip_link_works_once_and_is_gone_the_second_time(): void
    {
        Http::fake(['*' => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg'])]);
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));
        $url = $this->mint('zip');

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->get($url)->assertStatus(410);
    }

    public function test_a_zip_marks_its_images_downloaded(): void
    {
        // As the panel's export does. Single-use is what makes a side effect on
        // this GET tolerable: it fires once per mint, not once per visit.
        Http::fake(['*' => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg'])]);
        $user = $this->actingAsApiUser();
        $image = $this->image($this->search($user));

        $this->get($this->mint('zip'))->assertOk();

        $this->assertSame('downloaded', $image->refresh()->download_status);
    }

    public function test_a_tampered_link_is_rejected(): void
    {
        $user = $this->actingAsApiUser();
        $this->image($this->search($user), ['review_status' => 'approved']);
        $url = $this->mint('csv', ['review_status' => 'approved']);

        // Widening the filter by editing the URL must fail the signature.
        $this->get(str_replace('review_status=approved', 'review_status=rejected', $url))
            ->assertForbidden();
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));
        $url = $this->mint('csv');

        $this->travel(6)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_a_zip_that_grew_past_the_cap_after_minting_is_refused(): void
    {
        // Images can arrive in the minutes between mint and download. A ZIP that
        // quietly grew past the cap would exceed max_execution_time.
        Http::fake(['*' => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg'])]);
        config()->set('cars-images.bulk_download_max_images', 1);
        $user = $this->actingAsApiUser();
        $search = $this->search($user);
        $this->image($search);
        $url = $this->mint('zip');

        $this->image($search);

        $this->get($url)->assertStatus(422);
    }

    public function test_a_zip_where_every_fetch_failed_is_an_error_not_an_empty_archive(): void
    {
        // Matches the panel's "No images could be downloaded" notification.
        Http::fake(['*' => Http::response('gone', 404)]);
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));

        $this->get($this->mint('zip'))->assertStatus(502);
    }

    /**
     * The ImageId column of an exported CSV, as ints.
     *
     * By column, not by substring: the CSV carries no title, and a bare id such
     * as "2" would also match inside a URL or a year.
     *
     * @return list<int>
     */
    private function exportedImageIds(string $csv): array
    {
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $header = array_shift($rows);
        $column = array_search('ImageId', $header, true);

        return array_map(fn (array $row): int => (int) $row[$column], $rows);
    }

    public function test_a_false_verdict_filter_survives_the_signed_url(): void
    {
        /*
         * The app sends make_confirmed as a JSON boolean. It is validated,
         * serialized into the signed query string, and parsed back here. If any
         * step dropped a `false`, the filter would vanish and "export the images
         * that did not match" would silently become "export everything" - no
         * error, just a bigger file.
         */
        $user = $this->actingAsApiUser();
        $search = $this->search($user);
        $this->image($search, ['make_confirmed' => true]);
        $notMatched = $this->image($search, ['make_confirmed' => false]);
        $this->image($search, ['make_confirmed' => null]);

        $url = $this->postJson('/api/v1/exports', ['format' => 'csv', 'make_confirmed' => false])
            ->assertCreated()
            ->assertJsonPath('count', 1)
            ->json('url');

        $csv = $this->get($url)->assertOk()->streamedContent();

        // Exactly the not-matched image: neither the matched one, nor the
        // nullable "unknown", which a false filter must not sweep in.
        $this->assertSame([$notMatched->id], $this->exportedImageIds($csv));
    }

    public function test_a_true_verdict_filter_survives_the_signed_url(): void
    {
        $user = $this->actingAsApiUser();
        $search = $this->search($user);
        $matched = $this->image($search, ['make_confirmed' => true]);
        $this->image($search, ['make_confirmed' => false]);

        $url = $this->postJson('/api/v1/exports', ['format' => 'csv', 'make_confirmed' => true])->json('url');

        $this->assertSame([$matched->id], $this->exportedImageIds($this->get($url)->assertOk()->streamedContent()));
    }
}
