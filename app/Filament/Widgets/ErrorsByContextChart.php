<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\BucketsByDay;
use App\Models\ErrorEvent;
use Filament\Widgets\ChartWidget;

/**
 * Three failures a day tells the operator nothing. Three *Wikimedia blocks* a
 * day tells them to slow the crawl down, and three *CSV row* rejections tells
 * them to fix the spreadsheet. The split by context is the whole widget.
 */
class ErrorsByContextChart extends ChartWidget
{
    use BucketsByDay;

    protected static ?int $sort = -2;

    protected ?string $heading = 'Failures by kind (14 days)';

    private const WINDOW_DAYS = 14;

    /**
     * @return array{labels: array<int, string>, datasets: array<int, array<string, mixed>>}
     */
    public function series(): array
    {
        $labels = $this->dayLabels(self::WINDOW_DAYS);

        $rows = ErrorEvent::query()
            ->where('occurred_at', '>=', now()->subDays(self::WINDOW_DAYS - 1)->startOfDay())
            ->selectRaw('context, DATE(occurred_at) as day, COUNT(*) as total')
            ->groupBy('context', 'day')
            ->get();

        $datasets = [];

        // Only contexts that actually failed in the window get a legend entry:
        // five permanently-empty series would bury the one that matters.
        foreach ($rows->groupBy('context') as $context => $contextRows) {
            $datasets[] = [
                'label' => ErrorEvent::contexts()[$context] ?? $context,
                'data' => $this->countsForDays($contextRows, $labels),
                'backgroundColor' => ErrorEvent::contextChartColor($context),
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
        return 'bar';
    }

    protected function getOptions(): array
    {
        // Stacked, because the question is "how bad was that day" first and
        // "which kind" second; side-by-side bars invert that.
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
