<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\ErrorEvent;
use App\Models\WikimediaBlockEvent;
use Illuminate\Support\Facades\Http;

class SearchCreateTest extends ApiTestCase
{
    private const PAYLOAD = ['make' => 'Toyota', 'model' => 'RAV4', 'from_year' => 1997, 'to_year' => 1997, 'images_per_year' => 5];

    private function fakeEmptyWikimedia(): void
    {
        Http::fake(['*' => Http::response(['query' => ['search' => []]], 200)]);
    }

    public function test_creating_requires_the_search_write_ability(): void
    {
        $this->postJson('/api/v1/searches', self::PAYLOAD)->assertUnauthorized();

        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->postJson('/api/v1/searches', self::PAYLOAD)->assertForbidden();
    }

    public function test_a_year_span_wider_than_the_cap_is_rejected(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);

        $this->postJson('/api/v1/searches', ['from_year' => 2018, 'to_year' => 2022] + self::PAYLOAD)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_year']);

        // Three years apart (four inclusive) is the widest allowed.
        $this->fakeEmptyWikimedia();
        $this->postJson('/api/v1/searches', ['from_year' => 2018, 'to_year' => 2021] + self::PAYLOAD)
            ->assertCreated();
    }

    public function test_more_images_per_year_than_the_cap_is_rejected(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);

        $this->postJson('/api/v1/searches', ['images_per_year' => 6] + self::PAYLOAD)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['images_per_year']);
    }

    public function test_a_non_integer_year_gets_only_the_integer_error(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);

        $response = $this->postJson('/api/v1/searches', ['from_year' => 'abc'] + self::PAYLOAD)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from_year']);

        $this->assertArrayNotHasKey('to_year', $response->json('errors'), 'the span rule must not run on a year that failed validation');
    }

    public function test_a_search_is_created_run_inline_and_returned_completed(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        $this->fakeEmptyWikimedia();

        $response = $this->postJson('/api/v1/searches', self::PAYLOAD);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.make', 'Toyota')
            ->assertJsonPath('data.requested_by', $user->id)
            ->assertJsonPath('data.images_count', 0)
            ->assertJsonPath('data.images', []);
    }

    public function test_reversed_years_are_stored_in_order(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        $this->fakeEmptyWikimedia();

        $this->postJson('/api/v1/searches', ['from_year' => 1999, 'to_year' => 1997] + self::PAYLOAD)
            ->assertCreated()
            ->assertJsonPath('data.from_year', 1997)
            ->assertJsonPath('data.to_year', 1999);
    }

    public function test_an_identical_completed_search_is_returned_without_touching_wikimedia(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        $existing = $this->search($user); // Toyota RAV4 1997, 5/yr, completed
        Http::fake();

        $this->postJson('/api/v1/searches', self::PAYLOAD)
            ->assertOk()
            ->assertJsonPath('data.id', $existing->id);

        Http::assertNothingSent();
    }

    public function test_a_wikimedia_block_answers_503_with_retry_after(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        Http::fake(['*' => Http::response('Rate limit exceeded', 429, ['Retry-After' => '120'])]);

        $response = $this->postJson('/api/v1/searches', self::PAYLOAD);

        $response->assertStatus(503)
            ->assertHeader('Retry-After', '120')
            ->assertJsonPath('retry_after_seconds', 120)
            ->assertJsonPath('data.status', 'failed');
        $this->assertSame(1, WikimediaBlockEvent::count());
        $this->assertSame(1, ErrorEvent::where('context', ErrorEvent::CONTEXT_WIKIMEDIA_BLOCK)->count());
    }

    public function test_any_other_failure_answers_502_and_is_logged(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        Http::fake(['*' => Http::response('Internal server error', 500)]);

        $this->postJson('/api/v1/searches', self::PAYLOAD)
            ->assertStatus(502)
            ->assertJsonPath('data.status', 'failed');

        $this->assertSame(0, WikimediaBlockEvent::count());
        $this->assertSame(1, ErrorEvent::where('context', ErrorEvent::CONTEXT_SEARCH_RUN)->count());
    }

    public function test_creation_is_throttled_to_ten_per_minute(): void
    {
        $this->actingAsApiUser([TokenAbilities::SEARCH_WRITE]);
        $this->fakeEmptyWikimedia();

        // First creates; the next nine hit the dedupe path. All ten count.
        for ($i = 0; $i < 10; $i++) {
            $this->assertContains($this->postJson('/api/v1/searches', self::PAYLOAD)->status(), [200, 201]);
        }

        $this->postJson('/api/v1/searches', self::PAYLOAD)->assertStatus(429);
    }
}
