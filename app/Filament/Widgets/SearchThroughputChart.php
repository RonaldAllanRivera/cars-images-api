<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\BucketsByDay;
use App\Models\CarSearch;
use Filament\Widgets\ChartWidget;

/**
 * Throughput answers the question the error log cannot: the pipeline stopped
 * producing and *nothing failed* — it simply was never run. A flat line here
 * with an empty error log is still a problem.
 */
class SearchThroughputChart extends ChartWidget
{
    use BucketsByDay;

    protected static ?int $sort = -1;

    protected ?string $heading = 'Searches run (30 days)';

    private const WINDOW_DAYS = 30;

    /**
     * Both series are always present, even when empty, so the legend does not
     * change shape between a quiet week and a busy one.
     *
     * @return array{labels: array<int, string>, datasets: array<int, array<string, mixed>>}
     */
    public function series(): array
    {
        $labels = $this->dayLabels(self::WINDOW_DAYS);

        $rows = CarSearch::query()
            ->whereIn('status', ['completed', 'failed'])
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS - 1)->startOfDay())
            ->selectRaw('status, DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('status', 'day')
            ->get();

        $datasets = [];

        foreach (['completed' => ['Completed', '#16a34a'], 'failed' => ['Failed', '#dc2626']] as $status => [$label, $colour]) {
            $datasets[] = [
                'label' => $label,
                'data' => $this->countsForDays($rows->where('status', $status), $labels),
                'borderColor' => $colour,
                'backgroundColor' => $colour,
            ];
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    protected function getData(): array
    {
        return $this->series();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
