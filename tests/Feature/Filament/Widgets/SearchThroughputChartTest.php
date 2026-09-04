<?php

namespace Tests\Feature\Filament\Widgets;

use App\Filament\Widgets\SearchThroughputChart;
use App\Models\CarSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Throughput answers the question the error log cannot: the pipeline stopped
 * producing, and nothing failed — it simply was not run.
 */
class SearchThroughputChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-04 12:00:00');
    }

    private function search(User $user, string $status, string $createdAt): void
    {
        CarSearch::create([
            'make' => 'Toyota', 'model' => 'RAV4',
            'from_year' => 2019, 'to_year' => 2019,
            'transparent_background' => false, 'images_per_year' => 5,
            'status' => $status, 'requested_by' => $user->id,
        ])->forceFill(['created_at' => $createdAt])->save();
    }

    public function test_it_splits_each_day_into_completed_and_failed(): void
    {
        $user = User::factory()->create();

        $this->search($user, 'completed', '2026-09-04 08:00:00');
        $this->search($user, 'completed', '2026-09-04 09:00:00');
        $this->search($user, 'failed', '2026-09-04 10:00:00');
        $this->search($user, 'completed', '2026-09-03 10:00:00');
        // Still queued: neither completed nor failed, so it counts as neither.
        $this->search($user, 'pending', '2026-09-04 11:00:00');

        $series = Livewire::actingAs($user)
            ->test(SearchThroughputChart::class)
            ->instance()
            ->series();

        $byLabel = [];
        foreach ($series['datasets'] as $dataset) {
            $byLabel[$dataset['label']] = $dataset['data'];
        }

        $this->assertCount(30, $series['labels']);
        $this->assertSame('2026-09-04', $series['labels'][29]);
        $this->assertSame(2, $byLabel['Completed'][29]);
        $this->assertSame(1, $byLabel['Failed'][29]);
        $this->assertSame(1, $byLabel['Completed'][28]);
        $this->assertSame(0, $byLabel['Failed'][28]);
    }

    public function test_it_reports_zeroes_when_nothing_has_run(): void
    {
        $series = Livewire::actingAs(User::factory()->create())
            ->test(SearchThroughputChart::class)
            ->instance()
            ->series();

        $this->assertSame([0], array_unique($series['datasets'][0]['data']));
    }
}
