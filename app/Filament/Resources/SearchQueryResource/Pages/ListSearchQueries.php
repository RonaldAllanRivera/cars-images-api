<?php

namespace App\Filament\Resources\SearchQueryResource\Pages;

use App\Exceptions\WikimediaBlockedException;
use App\Filament\Resources\SearchQueryResource;
use App\Models\CarSearch;
use App\Services\Search\RunSearchQueryAction;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

/**
 * Drives a bulk run from the browser, one bounded chunk per poll.
 *
 * There is no queue worker on this host, and a single request cannot outlast
 * PHP's max_execution_time, so a large selection can never be one long call.
 * It used to be one chunk per click, which held that line but made the admin
 * the scheduler: four clicks for 58 rows, with no idea how long each took.
 *
 * The click now only seeds a queue. `wire:poll.keep-alive` calls
 * runNextChunk() repeatedly, and every poll is its own request bounded by the
 * same limits as before — so the execution-time protection is unchanged while
 * the run advances on its own.
 *
 * Run state lives on the component rather than in a table. A run therefore
 * does not survive a refresh, which is the honest behaviour: nothing should
 * keep calling Wikimedia once the browser driving it has gone. Nothing is
 * lost either way, since finished rows are saved as they go and the rest are
 * still `pending`.
 *
 * That last point is why `runAllPending` exists. Resuming used to mean
 * paging through the table hand-selecting the rows that had not run — for a
 * 58-row import spread over three pages, tedious enough to leave undone. It
 * now takes the whole filtered remainder in one click.
 */
class ListSearchQueries extends ListRecords
{
    protected static string $resource = SearchQueryResource::class;

    /** @var array<int, int> */
    public array $runQueue = [];

    public int $runTotal = 0;

    public int $runProcessed = 0;

    public int $runFailed = 0;

    public bool $runActive = false;

    public ?string $runBlockMessage = null;

    /**
     * Seconds spent actually running queries, used to age the estimate into
     * this run's real pace instead of a figure guessed up front.
     */
    public float $runElapsed = 0.0;

    /**
     * Cached per request: the header action asks for this to decide whether
     * to show itself, to label itself, and once more to start the run.
     *
     * @var array<int, int>|null
     */
    private ?array $runnableIdsCache = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('runAllPending')
                ->label(fn (): string => 'Run all pending ('.count($this->runnableSearchIds()).')')
                ->icon('heroicon-o-play')
                ->color('primary')
                // Hidden mid-run: a second seeding would replace the queue of
                // the run already in flight.
                ->visible(fn (): bool => ! $this->runActive && $this->runnableSearchIds() !== [])
                ->requiresConfirmation()
                ->modalHeading('Run every pending search')
                ->modalDescription(fn (): string => count($this->runnableSearchIds())
                    .' searches match the current filters and have not run yet. They run a few at a '
                    .'time and the progress bar keeps them going — leave this tab open until it finishes.')
                ->modalSubmitActionLabel('Run them')
                ->action(fn () => $this->startBulkRun($this->runnableSearchIds())),
        ];
    }

    /**
     * Every search the current filters select that still has work to do.
     *
     * Filter-scoped rather than table-wide so "CSV Import: 100.csv" plus this
     * button means "finish that import", not "run everything in the database".
     *
     * @return array<int, int>
     */
    public function runnableSearchIds(): array
    {
        // Ordered explicitly: the filtered query carries no sort of its own,
        // and a resumed run should continue in the same order the import
        // created its searches — CSV order — not whatever the engine returns.
        return $this->runnableIdsCache ??= $this->getFilteredTableQuery()
            ?->whereIn('status', ['pending', 'failed'])
            ->reorder('id')
            ->pluck('id')
            ->all() ?? [];
    }

    /**
     * @param  array<int, int>  $ids
     */
    public function startBulkRun(array $ids): void
    {
        $this->runQueue = array_values($ids);
        $this->runTotal = count($ids);
        $this->runProcessed = 0;
        $this->runFailed = 0;
        $this->runElapsed = 0.0;
        $this->runBlockMessage = null;
        $this->runActive = $this->runTotal > 0;
    }

    public function pauseBulkRun(): void
    {
        $this->runActive = false;
    }

    public function resumeBulkRun(): void
    {
        $this->runActive = $this->runQueue !== [];
    }

    public function cancelBulkRun(): void
    {
        $this->runActive = false;
        $this->runQueue = [];
        $this->runTotal = 0;
        $this->runBlockMessage = null;
    }

    /**
     * One chunk. Bounded by both a query count and a wall-clock budget, so a
     * slow Commons cannot drag a single poll towards max_execution_time.
     */
    public function runNextChunk(): void
    {
        if (! $this->runActive || $this->runQueue === []) {
            return;
        }

        $maxQueries = (int) config('cars-images.bulk_run_max_queries_per_chunk');
        $maxSeconds = (int) config('cars-images.bulk_run_auto_chunk_seconds');
        $sleepSeconds = (int) config('cars-images.bulk_run_sleep_seconds_between_queries');

        $startedAt = microtime(true);
        $inChunk = 0;

        while ($this->runQueue !== [] && $inChunk < $maxQueries) {
            if ($inChunk > 0 && microtime(true) - $startedAt >= $maxSeconds) {
                break;
            }

            $search = CarSearch::find(array_shift($this->runQueue));

            // A row deleted mid-run, or already finished by an earlier click,
            // is not work — but it still leaves the queue, or the run could
            // never drain.
            if ($search === null || ! in_array($search->status, ['pending', 'failed'], true)) {
                continue;
            }

            try {
                app(RunSearchQueryAction::class)->execute($search);
                $this->runProcessed++;
            } catch (WikimediaBlockedException $e) {
                $this->haltOnBlock($e);

                return;
            } catch (Throwable) {
                // One search failing is not a reason to abandon the rest; it is
                // already marked `failed` and can be re-selected later.
                $this->runFailed++;
            }

            $inChunk++;

            if ($sleepSeconds > 0 && $this->runQueue !== []) {
                sleep($sleepSeconds);
            }
        }

        $this->runElapsed += microtime(true) - $startedAt;

        if ($this->runQueue === []) {
            $this->finish();
        }
    }

    /**
     * Seconds still to go, from this run's observed pace.
     *
     * Falls back to the configured courtesy pause plus a second of work until
     * enough has run to measure, so the first estimate is not wildly wrong.
     */
    public function runSecondsRemaining(): int
    {
        $done = $this->runProcessed + $this->runFailed;

        $perQuery = $done > 0 && $this->runElapsed > 0
            ? $this->runElapsed / $done
            : (float) config('cars-images.bulk_run_sleep_seconds_between_queries') + 1.0;

        return (int) ceil(count($this->runQueue) * $perQuery);
    }

    private function haltOnBlock(WikimediaBlockedException $e): void
    {
        $this->runActive = false;
        $this->runBlockMessage = "HTTP {$e->statusCode} after {$this->runProcessed} queries. Retry-After: "
            .($e->retryAfterSeconds ?? 'n/a').'s';

        Notification::make()
            ->title('Bulk run paused — Wikimedia blocked')
            ->body($this->runBlockMessage)
            ->danger()
            ->persistent()
            ->send();

        $this->dispatch('bulk-run-finished', status: 'blocked', processed: $this->runProcessed);
    }

    private function finish(): void
    {
        $this->runActive = false;

        $body = "Processed {$this->runProcessed} of {$this->runTotal} selected.";

        if ($this->runFailed > 0) {
            $body .= " {$this->runFailed} failed and can be re-selected.";
        }

        Notification::make()
            ->title('Bulk run finished')
            ->body($body)
            ->success()
            // Persistent because a run takes minutes and the admin will be on
            // another tab; a toast that fades on its own is one they never see.
            ->persistent()
            ->send();

        $this->dispatch('bulk-run-finished', status: 'finished', processed: $this->runProcessed);
    }
}
