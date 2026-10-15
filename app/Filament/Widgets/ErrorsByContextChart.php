<?php

namespace App\Filament\Widgets;

use App\Models\ErrorEvent;
use App\Services\Health\PipelineSeries;
use Filament\Widgets\ChartWidget;

/**
 * Three failures a day tells the operator nothing. Three *Wikimedia blocks* a
 * day tells them to slow the crawl down, and three *CSV row* rejections tells
 * them to fix the spreadsheet. The split by context is the whole widget.
 */
class ErrorsByContextChart extends ChartWidget
{
    protected static ?int $sort = -2;

    protected ?string $heading = 'Failures by kind ('.PipelineSeries::ERRORS_DAYS.' days)';

    /**
     * Only contexts that failed in the window arrive from PipelineSeries, so
     * only they earn a legend entry. Colour is added here: it is the panel's,
     * not the data's.
     *
     * @return array{labels: array<int, string>, datasets: array<int, array<string, mixed>>}
     */
    public function series(): array
    {
        $errors = app(PipelineSeries::class)->errorsByContext();

        $datasets = [];

        foreach ($errors['series'] as $context => $data) {
            $datasets[] = [
                'label' => ErrorEvent::contexts()[$context] ?? $context,
                'data' => $data,
                'backgroundColor' => ErrorEvent::contextChartColor($context),
            ];
        }

        return ['labels' => $errors['days'], 'datasets' => $datasets];
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
