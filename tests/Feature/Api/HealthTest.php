<?php

namespace Tests\Feature\Api;

use App\Auth\TokenAbilities;
use App\Models\ErrorEvent;

class HealthTest extends ApiTestCase
{
    private function error(string $context, \DateTimeInterface $occurredAt, string $severity = 'error'): ErrorEvent
    {
        return ErrorEvent::create([
            'context' => $context,
            'severity' => $severity,
            'message' => "{$context} broke",
            'occurred_at' => $occurredAt,
        ]);
    }

    public function test_both_endpoints_require_the_errors_read_ability(): void
    {
        $this->getJson('/api/v1/health/summary')->assertUnauthorized();
        $this->getJson('/api/v1/errors')->assertUnauthorized();

        $this->actingAsApiUser([TokenAbilities::SEARCH_READ]);
        $this->getJson('/api/v1/health/summary')->assertForbidden();
        $this->getJson('/api/v1/errors')->assertForbidden();
    }

    public function test_the_summary_counts_runs_errors_and_images_in_their_windows(): void
    {
        $user = $this->actingAsApiUser([TokenAbilities::ERRORS_READ]);
        $this->search($user, ['status' => 'completed']);
        $this->search($user, ['status' => 'completed']);
        $failed = $this->search($user, ['status' => 'failed']);
        $this->image($failed);
        $this->error(ErrorEvent::CONTEXT_SEARCH_RUN, now());
        $this->error(ErrorEvent::CONTEXT_WIKIMEDIA_BLOCK, now()->subDays(3));
        $this->error(ErrorEvent::CONTEXT_CSV_ROW, now()->subDays(10)); // outside every window

        $this->getJson('/api/v1/health/summary')
            ->assertOk()
            ->assertJsonPath('data.searches_by_status', ['pending' => 0, 'running' => 0, 'completed' => 2, 'failed' => 1])
            ->assertJsonPath('data.errors_last_24h', 1)
            ->assertJsonPath('data.errors_by_context_last_7d', [
                'csv_upload' => 0, 'csv_row' => 0, 'search_run' => 1, 'image_download' => 0, 'wikimedia_block' => 1,
            ])
            ->assertJsonPath('data.images_last_7d', 1)
            ->assertJsonPath('data.latest_error_at', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_an_empty_database_still_returns_every_key(): void
    {
        $this->actingAsApiUser([TokenAbilities::ERRORS_READ]);

        $this->getJson('/api/v1/health/summary')
            ->assertOk()
            ->assertJsonPath('data.searches_by_status.completed', 0)
            ->assertJsonPath('data.errors_by_context_last_7d.csv_upload', 0)
            ->assertJsonPath('data.latest_error_at', null);
    }

    public function test_errors_are_listed_newest_first_and_filterable(): void
    {
        $this->actingAsApiUser([TokenAbilities::ERRORS_READ]);
        $old = $this->error(ErrorEvent::CONTEXT_SEARCH_RUN, now()->subHour());
        $new = $this->error(ErrorEvent::CONTEXT_IMAGE_DOWNLOAD, now(), 'warning');

        $all = $this->getJson('/api/v1/errors')->assertOk();
        $this->assertSame([$new->id, $old->id], $all->json('data.*.id'));
        $all->assertJsonStructure([
            'data' => [['id', 'context', 'severity', 'message', 'exception_class', 'exception_message',
                'trace_excerpt', 'details', 'car_search_id', 'csv_import_id', 'car_image_id', 'occurred_at']],
            'meta' => ['next_cursor', 'prev_cursor', 'per_page'],
        ]);

        $this->assertSame([$old->id], $this->getJson('/api/v1/errors?context=search_run')->json('data.*.id'));
        $this->assertSame([$new->id], $this->getJson('/api/v1/errors?severity=warning')->json('data.*.id'));

        $this->getJson('/api/v1/errors?context=meteor_strike')->assertUnprocessable()
            ->assertJsonValidationErrors(['context']);
    }
}
