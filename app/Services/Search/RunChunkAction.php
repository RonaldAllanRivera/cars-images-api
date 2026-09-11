<?php

namespace App\Services\Search;

use App\Exceptions\WikimediaBlockedException;
use App\Models\CarSearch;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * One bounded slice of a CSV import's outstanding searches.
 *
 * The mobile equivalent of ListSearchQueries::runNextChunk(), with one
 * difference that earns its keep: that method drains a queue held in Livewire
 * component state, while this one re-selects from the database each call. The
 * rows are the run state, so a client that crashed mid-run resumes by calling
 * again - there is no queue to rebuild and nothing to hand back and forth.
 *
 * That also deletes a whole category of edge case. A row deleted or finished
 * by someone else mid-run is simply not selected next time; there is no stale
 * id to detect and skip.
 *
 * Bounded by a query count AND a wall clock, because there is no queue worker
 * on this host and a chunk has to finish inside max_execution_time. A slow
 * Commons cannot drag one call past the limit.
 */
class RunChunkAction
{
    public function __construct(private readonly RunSearchQueryAction $runSearch) {}

    public function execute(int $csvImportId): RunChunkResult
    {
        $maxQueries = (int) config('cars-images.bulk_run_max_queries_per_chunk');
        $maxSeconds = (int) config('cars-images.bulk_run_auto_chunk_seconds');
        $sleepSeconds = (int) config('cars-images.bulk_run_sleep_seconds_between_queries');

        $queue = $this->runnable($csvImportId)->limit($maxQueries)->get();
        $last = $queue->count() - 1;

        $startedAt = microtime(true);
        $outcomes = [];
        $blocked = null;

        foreach ($queue as $index => $search) {
            if ($index > 0 && microtime(true) - $startedAt >= $maxSeconds) {
                break;
            }

            try {
                $this->runSearch->execute($search);
                $outcomes[] = $this->describe($search, 'completed');
            } catch (WikimediaBlockedException $e) {
                // A block is not a per-query failure. RunSearchQueryAction has
                // already written the block event and the error event; all this
                // has to do is stop asking.
                $blocked = ['status' => $e->statusCode, 'retry_after_seconds' => $e->retryAfterSeconds];

                break;
            } catch (Throwable) {
                // One bad query is already marked `failed` and can be re-run.
                // Abandoning the chunk for it would strand everything behind it.
                $outcomes[] = $this->describe($search, 'failed');
            }

            if ($sleepSeconds > 0 && $index < $last) {
                sleep($sleepSeconds);
            }
        }

        return new RunChunkResult(
            outcomes: $outcomes,
            ranSeconds: round(microtime(true) - $startedAt, 2),
            // Counted after the slice rather than inferred from what was sent,
            // so the client's figure survives a dropped response.
            remaining: $this->runnable($csvImportId)->count(),
            blocked: $blocked,
        );
    }

    /**
     * Ordered by id: CSV order, so a resumed run continues where the import
     * created its searches rather than wherever the engine happens to return
     * them.
     *
     * @return Builder<CarSearch>
     */
    private function runnable(int $csvImportId): Builder
    {
        return CarSearch::query()
            ->where('csv_import_id', $csvImportId)
            ->whereIn('status', ['pending', 'failed'])
            ->orderBy('id');
    }

    /**
     * @return array{id: int, make: string, model: ?string, from_year: int, outcome: string}
     */
    private function describe(CarSearch $search, string $outcome): array
    {
        return [
            'id' => $search->id,
            'make' => $search->make,
            'model' => $search->model,
            'from_year' => $search->from_year,
            'outcome' => $outcome,
        ];
    }
}
