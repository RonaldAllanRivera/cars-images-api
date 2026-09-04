<?php

namespace Tests\Feature\Filament\Widgets;

use App\Filament\Widgets\ErrorsByContextChart;
use App\Models\ErrorEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Three failures a day tells you nothing; three Wikimedia blocks a day tells
 * you to slow the crawl down. The split by context is the whole point of the
 * chart, so that is what these tests pin.
 */
class ErrorsByContextChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-04 12:00:00');
    }

    private function error(string $context, string $occurredAt): void
    {
        ErrorEvent::create([
            'context' => $context,
            'severity' => 'error',
            'message' => 'something broke',
            'occurred_at' => $occurredAt,
        ]);
    }

    private function series(): array
    {
        return Livewire::actingAs(User::factory()->create())
            ->test(ErrorsByContextChart::class)
            ->instance()
            ->series();
    }

    public function test_it_labels_every_day_in_the_window_including_the_quiet_ones(): void
    {
        $this->error(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-04 09:00:00');

        $series = $this->series();

        // 14 days ending today, so a gap reads as a gap rather than closing up.
        $this->assertCount(14, $series['labels']);
        $this->assertSame('2026-08-22', $series['labels'][0]);
        $this->assertSame('2026-09-04', $series['labels'][13]);
    }

    public function test_it_counts_each_context_separately_per_day(): void
    {
        $this->error(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-04 09:00:00');
        $this->error(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-04 10:00:00');
        $this->error(ErrorEvent::CONTEXT_WIKIMEDIA_BLOCK, '2026-09-03 10:00:00');

        $series = $this->series();

        $byLabel = [];
        foreach ($series['datasets'] as $dataset) {
            $byLabel[$dataset['label']] = $dataset['data'];
        }

        $this->assertSame(2, $byLabel['Search run'][13]);
        $this->assertSame(0, $byLabel['Search run'][12]);
        $this->assertSame(1, $byLabel['Wikimedia block'][12]);
    }

    public function test_it_omits_contexts_that_never_failed_in_the_window(): void
    {
        $this->error(ErrorEvent::CONTEXT_SEARCH_RUN, '2026-09-04 09:00:00');
        // Outside the 14-day window: its context must not earn a legend entry.
        $this->error(ErrorEvent::CONTEXT_CSV_UPLOAD, '2026-08-01 09:00:00');

        $labels = array_column($this->series()['datasets'], 'label');

        $this->assertSame(['Search run'], $labels);
    }
}
