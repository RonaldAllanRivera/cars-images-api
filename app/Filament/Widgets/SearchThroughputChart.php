<?php

namespace App\Filament\Widgets;

use App\Services\Health\PipelineSeries;
use Filament\Widgets\ChartWidget;

/**
 * Throughput answers the question the error log cannot: the pipeline stopped
 * producing and *nothing failed* — it simply was never run. A flat line here
 * with an empty error log is still a problem.
 */
class SearchThroughputChart extends ChartWidget
{
    protected static ?int $sort = -1;

    protected ?string $heading = 'Searches run ('.PipelineSeries::THROUGHPUT_DAYS.' days)';

    /**
     * The counting lives in PipelineSeries, shared with the mobile API. This
     * widget adds only what is Chart.js's: labels, datasets and colours. The
     * keys stay Chart.js's own rather than the service's, because the widget
     * tests assert on `labels` and `datasets[].label/data`.
     *
     * @return array{labels: array<int, string>, datasets: array<int, array<string, mixed>>}
     */
    public function series(): array
    {
        $throughput = app(PipelineSeries::class)->throughput();

        $datasets = [];

        foreach (['completed' => ['Completed', '#16a34a'], 'failed' => ['Failed', '#dc2626']] as $status => [$label, $colour]) {
            $datasets[] = [
                'label' => $label,
                'data' => $throughput[$status],
                'borderColor' => $colour,
                'backgroundColor' => $colour,
            ];
        }

        return ['labels' => $throughput['days'], 'datasets' => $datasets];
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
