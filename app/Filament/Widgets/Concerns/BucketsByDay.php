<?php

namespace App\Filament\Widgets\Concerns;

use Illuminate\Support\Collection;

/**
 * Both charts ask the same question of different tables: how many rows fell on
 * each of the last N days, split by one column.
 *
 * The grouping is done in SQL — `DATE(...)` and a `COUNT(*)` over an indexed
 * window — rather than by pulling rows into PHP, because a bad week can put
 * thousands of rows inside the window and none of them are wanted individually.
 */
trait BucketsByDay
{
    /**
     * The window's days as `Y-m-d` strings, oldest first.
     *
     * Every day is listed, including the quiet ones, so a gap in the data reads
     * as a gap rather than closing up and shifting its neighbours.
     *
     * @return array<int, string>
     */
    protected function dayLabels(int $days): array
    {
        return array_map(
            fn (int $ago): string => now()->subDays($ago)->toDateString(),
            range($days - 1, 0),
        );
    }

    /**
     * @param  Collection<int, object>  $rows  rows carrying `day` and `total`
     * @param  array<int, string>  $labels
     * @return array<int, int>
     */
    protected function countsForDays(Collection $rows, array $labels): array
    {
        $byDay = $rows->pluck('total', 'day');

        return array_map(fn (string $day): int => (int) ($byDay[$day] ?? 0), $labels);
    }
}
