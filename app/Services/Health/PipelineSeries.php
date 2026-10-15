<?php

namespace App\Services\Health;

use App\Models\CarSearch;
use App\Models\ErrorEvent;
use Illuminate\Support\Collection;

/**
 * The two daily series behind the health charts: searches run, and failures by
 * kind.
 *
 * Extracted from the dashboard's SearchThroughputChart and ErrorsByContextChart
 * so the panel and the API count with one implementation. The widgets keep
 * what is their own - Chart.js's labels, datasets and colours - and layer it on
 * top, as Results layers its deep links on ImportCoverage. Nothing here carries
 * a colour: that is presentation, and each client has its own.
 *
 * The grouping is done in SQL - `DATE(...)` and a `COUNT(*)` over an indexed
 * window - rather than by pulling rows into PHP, because a bad week can put
 * thousands of rows inside the window and none of them are wanted individually.
 */
class PipelineSeries
{
    public const THROUGHPUT_DAYS = 30;

    public const ERRORS_DAYS = 14;

    /**
     * @return array{
     *     throughput: array{days: list<string>, completed: list<int>, failed: list<int>},
     *     errors_by_context: array{days: list<string>, series: array<string, list<int>>}
     * }
     */
    public function build(): array
    {
        return [
            'throughput' => $this->throughput(),
            'errors_by_context' => $this->errorsByContext(),
        ];
    }

    /**
     * Completed and failed are both always present, even when empty, so a
     * legend does not change shape between a quiet week and a busy one.
     *
     * @return array{days: list<string>, completed: list<int>, failed: list<int>}
     */
    public function throughput(): array
    {
        $days = $this->days(self::THROUGHPUT_DAYS);

        $rows = CarSearch::query()
            ->whereIn('status', ['completed', 'failed'])
            ->where('created_at', '>=', now()->subDays(self::THROUGHPUT_DAYS - 1)->startOfDay())
            ->selectRaw('status, DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('status', 'day')
            ->get();

        return [
            'days' => $days,
            'completed' => $this->countsForDays($rows->where('status', 'completed'), $days),
            'failed' => $this->countsForDays($rows->where('status', 'failed'), $days),
        ];
    }

    /**
     * Only contexts that failed in the window get a series: five permanently
     * empty ones would bury the one that matters. An absent context means zero.
     *
     * Ordered as ErrorEvent::contexts() declares them, not as the database
     * happens to return groups, so the same failures always produce the same
     * response. A context the model no longer declares keeps its series, after
     * the declared ones.
     *
     * @return array{days: list<string>, series: array<string, list<int>>}
     */
    public function errorsByContext(): array
    {
        $days = $this->days(self::ERRORS_DAYS);

        $byContext = ErrorEvent::query()
            ->where('occurred_at', '>=', now()->subDays(self::ERRORS_DAYS - 1)->startOfDay())
            ->selectRaw('context, DATE(occurred_at) as day, COUNT(*) as total')
            ->groupBy('context', 'day')
            ->get()
            ->groupBy('context');

        $declared = array_keys(ErrorEvent::contexts());
        $order = array_merge(
            array_values(array_intersect($declared, $byContext->keys()->all())),
            $byContext->keys()->diff($declared)->sort()->values()->all(),
        );

        $series = [];
        foreach ($order as $context) {
            $series[$context] = $this->countsForDays($byContext[$context], $days);
        }

        return ['days' => $days, 'series' => $series];
    }

    /**
     * The window's days as `Y-m-d` strings, oldest first.
     *
     * Every day is listed, including the quiet ones, so a gap in the data reads
     * as a gap rather than closing up and shifting its neighbours.
     *
     * @return list<string>
     */
    private function days(int $count): array
    {
        return array_map(
            fn (int $ago): string => now()->subDays($ago)->toDateString(),
            range($count - 1, 0),
        );
    }

    /**
     * @param  Collection<int, object>  $rows  rows carrying `day` and `total`
     * @param  list<string>  $days
     * @return list<int>
     */
    private function countsForDays(Collection $rows, array $days): array
    {
        $byDay = $rows->pluck('total', 'day');

        return array_map(fn (string $day): int => (int) ($byDay[$day] ?? 0), $days);
    }
}
