<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\CarImage;

class ImagesTest extends ApiTestCase
{
    public function test_listing_requires_a_token(): void
    {
        $this->getJson('/api/v1/images')->assertUnauthorized();
    }

    public function test_listing_requires_the_search_read_ability(): void
    {
        $this->actingAsApiUser([TokenAbilities::ERRORS_READ]);

        $this->getJson('/api/v1/images')->assertForbidden();
    }

    public function test_the_list_is_cursor_paginated_newest_first(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $search = $this->search($user);
        $a = $this->image($search);
        $b = $this->image($search);
        $c = $this->image($search);

        $first = $this->getJson('/api/v1/images?per_page=2')->assertOk();
        $this->assertSame([$c->id, $b->id], $first->json('data.*.id'));
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $second = $this->getJson("/api/v1/images?per_page=2&cursor={$cursor}")->assertOk();
        $this->assertSame([$a->id], $second->json('data.*.id'));
        $this->assertNull($second->json('meta.next_cursor'));
    }

    public function test_each_filter_narrows_the_list(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $toyota = $this->search($user);
        $honda = $this->search($user, ['make' => 'Honda', 'model' => 'Civic', 'from_year' => 2010, 'to_year' => 2010]);

        $hit = $this->image($toyota, [
            'make_confirmed' => true, 'year_confirmed' => true,
            'review_status' => CarImage::REVIEW_APPROVED, 'download_status' => 'downloaded',
        ]);
        $miss = $this->image($honda, [
            'make_confirmed' => false, 'year_confirmed' => false,
            'review_status' => CarImage::REVIEW_REJECTED, 'download_status' => 'failed',
        ]);
        // Neither confirmed nor denied: a boolean filter must never match it.
        $this->image($honda);

        foreach ([
            'make=Toyota', 'model=RAV4', 'year=1997', 'make_confirmed=1', 'year_confirmed=true',
            'review_status=approved', 'download_status=downloaded',
        ] as $query) {
            $ids = $this->getJson("/api/v1/images?{$query}")->assertOk()->json('data.*.id');
            $this->assertSame([$hit->id], $ids, "filter {$query}");
        }

        $ids = $this->getJson('/api/v1/images?make_confirmed=0')->assertOk()->json('data.*.id');
        $this->assertSame([$miss->id], $ids, 'false must exclude the unknown (null) verdict');
    }

    public function test_an_invalid_filter_is_rejected(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);

        $this->getJson('/api/v1/images?review_status=maybe')->assertUnprocessable()
            ->assertJsonValidationErrors(['review_status']);
        $this->getJson('/api/v1/images?per_page=500')->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_show_returns_the_full_record(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $image = $this->image($this->search($user), [
            'license' => 'CC BY-SA 4.0',
            'attribution' => 'Photo by Example',
            'make_confirmed' => true,
            'review_status' => CarImage::REVIEW_APPROVED,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $this->getJson("/api/v1/images/{$image->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $image->id)
            ->assertJsonPath('data.license', 'CC BY-SA 4.0')
            ->assertJsonPath('data.attribution', 'Photo by Example')
            ->assertJsonPath('data.make_confirmed', true)
            ->assertJsonPath('data.review_status', 'approved')
            ->assertJsonPath('data.reviewed_by', $user->id)
            ->assertJsonStructure(['data' => [
                'id', 'car_search_id', 'make', 'model', 'year', 'color', 'title', 'description',
                'source_url', 'thumbnail_url', 'width', 'height', 'license', 'attribution',
                'make_confirmed', 'year_confirmed', 'review_status', 'reviewed_by', 'reviewed_at',
                'download_status', 'created_at',
            ]]);
    }

    public function test_show_404s_for_an_unknown_image(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);

        $this->getJson('/api/v1/images/999999')->assertNotFound();
    }
}
