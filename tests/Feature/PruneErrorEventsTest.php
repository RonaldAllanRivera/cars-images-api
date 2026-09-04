<?php

namespace Tests\Feature;

use App\Models\ErrorEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneErrorEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_events_older_than_the_retention_window(): void
    {
        config(['cars-images.error_log_retention_days' => 30]);

        $stale = $this->event(now()->subDays(31));
        $fresh = $this->event(now()->subDays(29));

        $this->artisan('error-events:prune')
            ->expectsOutputToContain('1')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('error_events', ['id' => $stale->id]);
        $this->assertDatabaseHas('error_events', ['id' => $fresh->id]);
    }

    public function test_keeps_an_event_exactly_on_the_boundary(): void
    {
        config(['cars-images.error_log_retention_days' => 30]);

        $boundary = $this->event(now()->subDays(30)->addMinute());

        $this->artisan('error-events:prune')->assertExitCode(0);

        $this->assertDatabaseHas('error_events', ['id' => $boundary->id]);
    }

    public function test_reports_when_there_is_nothing_to_prune(): void
    {
        $this->artisan('error-events:prune')
            ->expectsOutputToContain('0')
            ->assertExitCode(0);
    }

    private function event(\DateTimeInterface $occurredAt): ErrorEvent
    {
        return ErrorEvent::create([
            'context' => ErrorEvent::CONTEXT_SEARCH_RUN,
            'severity' => 'error',
            'message' => 'something broke',
            'occurred_at' => $occurredAt,
        ]);
    }
}
