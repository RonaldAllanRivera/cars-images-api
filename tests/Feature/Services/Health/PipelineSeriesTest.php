<?php

namespace Tests\Feature\Services\Health;

use App\Models\ErrorEvent;
use App\Models\User;
use App\Services\Health\PipelineSeries;
use Tests\Feature\Api\ApiTestCase;

/**
 * Extends ApiTestCase for its search() helper, not for anything HTTP - there
 * are no model factories for searches in this project.
 */
class PipelineSeriesTest extends ApiTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-15 12:00:00');
        $this->user = User::factory()->create();
    }

    private function searchAt(string $status, string $createdAt): void
    {
        $this->search($this->user, ['status' => $status])
            ->forceFill(['created_at' => $createdAt])
            ->save();
    }

    private function errorAt(string $context, string $occurredAt): void
    {
        ErrorEvent::create([
            'context' => $context,
            'severity' => 'error',
            'message' => 'something broke',
            'occurred_at' => $occurredAt,
        ]);
    }

    public function test_throughput_lists_every_day_of_the_window_including_quiet_ones(): void
    {
        $throughput = app(PipelineSeries::class)->throughput();

        $this->assertCount(30, $throughput['days']);
        $this->assertSame('2026-08-17', $throughput['days'][0]);
        $this->assertSame('2026-09-15', $throughput['days'][29]);
        // Both series always present and as long as the window, so an empty
        // day reads as a gap rather than closing up and shifting its neighbours.
        $this->assertSame(array_fill(0, 30, 0), $throughput['completed']);
        $this->assertSame(array_fill(0, 30, 0), $throughput['failed']);
    }

    public function test_throughput_counts_completed_and_failed_separately(): void
    {
        $this->searchAt('completed', '2026-09-15 08:00:00');
        $this->searchAt('completed', '2026-09-15 09:00:00');
        $this->searchAt('failed', '2026-09-15 10:00:00');
        $this->searchAt('completed', '2026-09-14 10:00:00');
        // Neither finished, so neither counts as run.
        $this->searchAt('pending', '2026-09-15 11:00:00');
        $this->searchAt('running', '2026-09-15 11:00:00');

        $throughput = app(PipelineSeries::class)->throughput();

        $this->assertSame(2, $throughput['completed'][29]);
        $this->assertSame(1, $throughput['failed'][29]);
        $this->assertSame(1, $throughput['completed'][28]);
        $this->assertSame(0, $throughput['failed'][28]);
    }

    public function test_throughput_starts_at_midnight_on_the_first_day(): void
    {
        $this->searchAt('completed', '2026-08-17 00:00:00');
        $this->searchAt('completed', '2026-08-16 23:59:59');

        $completed = app(PipelineSeries::class)->throughput()['completed'];

        $this->assertSame(1, $completed[0]);
        $this->assertSame(1, array_sum($completed));
    }

    public function test_failures_list_every_day_and_only_the_contexts_that_failed(): void
    {
        $this->errorAt(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-15 09:00:00');
        $this->errorAt(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-15 10:00:00');
        $this->errorAt(ErrorEvent::CONTEXT_WIKIMEDIA_BLOCK, '2026-09-14 10:00:00');
        // Outside the 14-day window: its context must not get a series.
        $this->errorAt(ErrorEvent::CONTEXT_CSV_UPLOAD, '2026-09-01 09:00:00');

        $errors = app(PipelineSeries::class)->errorsByContext();

        $this->assertCount(14, $errors['days']);
        $this->assertSame('2026-09-02', $errors['days'][0]);
        $this->assertSame('2026-09-15', $errors['days'][13]);
        $this->assertSame(['search_run', 'wikimedia_block'], array_keys($errors['series']));
        $this->assertSame(2, $errors['series']['search_run'][13]);
        $this->assertSame(0, $errors['series']['search_run'][12]);
        $this->assertSame(1, $errors['series']['wikimedia_block'][12]);
        $this->assertCount(14, $errors['series']['wikimedia_block']);
    }

    public function test_failures_are_ordered_as_the_model_declares_contexts(): void
    {
        // Inserted, and alphabetised, in a different order from the declaration
        // (csv_upload, csv_row, search_run, ...): the response must not depend
        // on which order the database hands groups back in.
        $this->errorAt(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-15 09:00:00');
        $this->errorAt(ErrorEvent::CONTEXT_CSV_ROW, '2026-09-15 09:00:00');
        $this->errorAt(ErrorEvent::CONTEXT_CSV_UPLOAD, '2026-09-15 09:00:00');

        $series = app(PipelineSeries::class)->errorsByContext()['series'];

        $this->assertSame(['csv_upload', 'csv_row', 'search_run'], array_keys($series));
    }

    public function test_a_context_the_model_no_longer_declares_is_still_charted_last(): void
    {
        // Rows outlive code: a retired context keeps its rows until the prune
        // catches up, and the panel has always charted them under their raw
        // key rather than hiding them.
        $this->errorAt('legacy_context', '2026-09-15 09:00:00');
        $this->errorAt(ErrorEvent::CONTEXT_WIKIMEDIA_BLOCK, '2026-09-15 09:00:00');

        $series = app(PipelineSeries::class)->errorsByContext()['series'];

        $this->assertSame(['wikimedia_block', 'legacy_context'], array_keys($series));
    }

    public function test_a_quiet_fortnight_has_no_series_at_all(): void
    {
        $this->assertSame([], app(PipelineSeries::class)->errorsByContext()['series']);
    }

    public function test_build_returns_both_windows_and_no_colours(): void
    {
        $this->searchAt('failed', '2026-09-15 08:00:00');
        $this->errorAt(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-15 09:00:00');

        $built = app(PipelineSeries::class)->build();

        $this->assertSame(['throughput', 'errors_by_context'], array_keys($built));
        $this->assertSame(['days', 'completed', 'failed'], array_keys($built['throughput']));
        $this->assertSame(['days', 'series'], array_keys($built['errors_by_context']));
        // Colour is presentation: each client brings its own.
        $this->assertStringNotContainsString('#', json_encode($built));
    }
}
