<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;

class SearchesTest extends ApiTestCase
{
    public function test_listing_requires_the_search_read_ability(): void
    {
        $this->getJson('/api/v1/searches')->assertUnauthorized();

        $this->actingAsApiUser([TokenAbilities::REVIEW_WRITE]);
        $this->getJson('/api/v1/searches')->assertForbidden();
    }

    public function test_the_list_is_newest_first_with_image_counts(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $older = $this->search($user);
        $newer = $this->search($user, ['make' => 'Honda', 'model' => 'Civic']);
        $this->image($older);
        $this->image($older);

        $response = $this->getJson('/api/v1/searches')->assertOk();

        $this->assertSame([$newer->id, $older->id], $response->json('data.*.id'));
        $this->assertSame([0, 2], $response->json('data.*.images_count'));
        $response->assertJsonStructure(['meta' => ['next_cursor', 'prev_cursor', 'per_page']]);
    }

    public function test_the_list_filters_by_status_and_rejects_unknown_statuses(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $failed = $this->search($user, ['status' => 'failed']);
        $this->search($user, ['status' => 'completed']);

        $ids = $this->getJson('/api/v1/searches?status=failed')->assertOk()->json('data.*.id');
        $this->assertSame([$failed->id], $ids);

        $this->getJson('/api/v1/searches?status=exploded')->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_show_returns_the_search_with_its_image_count(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $search = $this->search($user, ['commons_category' => 'Toyota RAV4 (XA10)']);
        $this->image($search);

        $this->getJson("/api/v1/searches/{$search->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $search->id)
            ->assertJsonPath('data.commons_category', 'Toyota RAV4 (XA10)')
            ->assertJsonPath('data.images_count', 1)
            ->assertJsonStructure(['data' => [
                'id', 'make', 'model', 'commons_category', 'from_year', 'to_year', 'color', 'transmission',
                'transparent_background', 'images_per_year', 'status', 'csv_import_id', 'requested_by',
                'images_count', 'created_at', 'updated_at',
            ]]);

        $this->getJson('/api/v1/searches/999999')->assertNotFound();
    }

    public function test_a_searchs_images_are_scoped_to_it_and_filterable(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $mine = $this->search($user);
        $other = $this->search($user, ['make' => 'Honda', 'model' => 'Civic']);
        $approved = $this->image($mine, ['review_status' => 'approved']);
        $pending = $this->image($mine);
        $this->image($other, ['review_status' => 'approved']);

        $all = $this->getJson("/api/v1/searches/{$mine->id}/images")->assertOk()->json('data.*.id');
        $this->assertEqualsCanonicalizing([$approved->id, $pending->id], $all);

        $only = $this->getJson("/api/v1/searches/{$mine->id}/images?review_status=approved")->assertOk()->json('data.*.id');
        $this->assertSame([$approved->id], $only);
    }
}
