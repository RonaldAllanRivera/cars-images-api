<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use Illuminate\Support\Facades\Cache;

class ExportTest extends ApiTestCase
{
    public function test_it_mints_a_signed_csv_link(): void
    {
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));

        $response = $this->postJson('/api/v1/exports', ['format' => 'csv'])
            ->assertCreated()
            ->assertJsonPath('format', 'csv')
            ->assertJsonPath('count', 1);

        $this->assertStringContainsString('signature=', $response->json('url'));
        $this->assertNotEmpty($response->json('expires_at'));
    }

    public function test_a_zip_link_carries_a_nonce_written_to_the_cache(): void
    {
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));

        $url = $this->postJson('/api/v1/exports', ['format' => 'zip'])->assertCreated()->json('url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        $this->assertNotEmpty($params['nonce'] ?? null);
        $this->assertTrue(Cache::has("export-nonce:{$params['nonce']}"));
    }

    public function test_a_csv_link_carries_no_nonce(): void
    {
        // Single-use is for the ZIP, whose replay costs Wikimedia fetches. A CSV
        // replay costs a database query.
        $user = $this->actingAsApiUser();
        $this->image($this->search($user));

        $url = $this->postJson('/api/v1/exports', ['format' => 'csv'])->json('url');

        $this->assertStringNotContainsString('nonce=', $url);
    }

    public function test_it_refuses_an_empty_set(): void
    {
        // A link to an empty file is a worse answer than saying so.
        $this->actingAsApiUser();

        $this->postJson('/api/v1/exports', ['format' => 'csv'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format']);
    }

    public function test_a_zip_over_the_cap_is_refused_but_a_csv_of_the_same_size_is_not(): void
    {
        // The cap exists because a ZIP fetches every image from Wikimedia
        // synchronously. A CSV touches nothing external and has no cap.
        config()->set('cars-images.bulk_download_max_images', 1);
        $user = $this->actingAsApiUser();
        $search = $this->search($user);
        $this->image($search);
        $this->image($search);

        $this->postJson('/api/v1/exports', ['format' => 'zip'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format']);

        $this->postJson('/api/v1/exports', ['format' => 'csv'])->assertCreated();
    }

    public function test_the_refusal_names_the_count_and_the_limit(): void
    {
        // "Narrow the filter" is only actionable if it says by how much.
        config()->set('cars-images.bulk_download_max_images', 1);
        $user = $this->actingAsApiUser();
        $search = $this->search($user);
        $this->image($search);
        $this->image($search);

        $this->postJson('/api/v1/exports', ['format' => 'zip'])
            ->assertJsonPath('errors.format.0', '2 images match; a ZIP is limited to 1. Narrow the filter.');
    }

    public function test_the_filters_travel_through_the_signature(): void
    {
        $user = $this->actingAsApiUser();
        $this->image($this->search($user), ['review_status' => 'approved']);

        $url = $this->postJson('/api/v1/exports', ['format' => 'csv', 'review_status' => 'approved'])
            ->json('url');

        $this->assertStringContainsString('review_status=approved', $url);
    }

    public function test_it_rejects_an_unknown_format(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/v1/exports', ['format' => 'pdf'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['format']);
    }

    public function test_minting_requires_exports_read(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->image($this->search($user));

        $this->postJson('/api/v1/exports', ['format' => 'csv'])->assertForbidden();
    }
}
