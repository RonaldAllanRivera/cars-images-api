<?php

namespace App\Services\Imports;

use App\Models\CarSearch;
use Illuminate\Database\Eloquent\Builder;

/**
 * How much of a CSV import has actually been searched.
 *
 * The images table can only show images that exist, so a run that stopped
 * early and a run that finished having found little look identical: both
 * render a short list under a confident "Showing 1 to N of N results".
 * Counting the *searches* behind those rows separates the two - how many never
 * ran, and how many ran and came back empty.
 *
 * Extracted from App\Filament\Pages\Results so the panel and the API answer
 * this question with one implementation. The page keeps what is its own: the
 * import's name, and the deep links into the filtered Search Queries list.
 */
class ImportCoverage
{
    /**
     * Null when there is nothing to describe, which hides the panel rather
     * than rendering six zeroes - those read as a broken import rather than
     * an empty one.
     *
     * @return array{total: int, searched: int, not_run: int, failed: int, with_images: int, no_images: int}|null
     */
    public function for(?int $importId): ?array
    {
        /*
         * Re-built per count rather than cloned: `whereHas` on a shared
         * builder leaks its subquery into the counts that follow, so the
         * cheaper-looking clone silently corrupts every total after the first.
         */
        $searches = fn (): Builder => CarSearch::query()
            ->whereNotNull('csv_import_id')
            ->when($importId !== null, fn (Builder $q) => $q->where('csv_import_id', $importId));

        $total = $searches()->count();

        if ($total === 0) {
            return null;
        }

        /*
         * `running` counts as not-run. There is no queue worker on this host,
         * so a row left `running` is a request that died mid-search rather
         * than work in progress - it still needs doing.
         */
        $notRun = $searches()->whereIn('status', ['pending', 'running'])->count();

        return [
            'total' => $total,
            // A failed search still counts as searched: it ran, it just did
            // not finish.
            'searched' => $total - $notRun,
            'not_run' => $notRun,
            'failed' => $searches()->where('status', 'failed')->count(),
            'with_images' => $searches()->whereHas('images')->count(),
            'no_images' => $searches()->where('status', 'completed')->whereDoesntHave('images')->count(),
        ];
    }
}
