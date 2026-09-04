<?php

namespace App\Services\Search;

use App\Exceptions\WikimediaBlockedException;
use App\Models\CarSearch;
use App\Models\ErrorEvent;
use App\Models\WikimediaBlockEvent;
use App\Services\Images\CarImageSearchService;
use App\Services\Logging\ErrorEventLogger;
use Throwable;

class RunSearchQueryAction
{
    public function __construct(
        protected CarImageSearchService $searchService,
        protected ErrorEventLogger $errorLog,
    ) {}

    /**
     * Run a single CarSearch synchronously.
     *
     * Marks the search as `completed` on success, `failed` on any throw.
     * On WikimediaBlockedException, records a wikimedia_block_events row
     * before re-throwing so the bulk-run caller can stop the loop.
     *
     * Both failure paths also write an error_events row. This is the narrowest
     * point every admin path passes through, so recording here covers the bulk
     * run and the single-row action at once — and the bulk loop can go on
     * swallowing the exception, because the reason is already stored.
     */
    public function execute(CarSearch $search): void
    {
        try {
            $this->searchService->runSearch($search);
        } catch (WikimediaBlockedException $e) {
            $this->markFailed($search);

            WikimediaBlockEvent::create([
                'car_search_id' => $search->id,
                'csv_import_id' => $search->csv_import_id,
                'status_code' => $e->statusCode,
                'retry_after_seconds' => $e->retryAfterSeconds,
                'response_excerpt' => $e->responseExcerpt,
                'occurred_at' => now(),
            ]);

            $this->errorLog->record(
                ErrorEvent::CONTEXT_WIKIMEDIA_BLOCK,
                $e,
                links: ['car_search_id' => $search->id, 'csv_import_id' => $search->csv_import_id],
                details: [
                    'http_status' => $e->statusCode,
                    'retry_after_seconds' => $e->retryAfterSeconds,
                    'response_excerpt' => $e->responseExcerpt,
                ],
                message: $this->describe($search).' — blocked by Wikimedia (HTTP '.$e->statusCode.')',
            );

            throw $e;
        } catch (Throwable $e) {
            $this->markFailed($search);

            $this->errorLog->record(
                ErrorEvent::CONTEXT_SEARCH_RUN,
                $e,
                links: ['car_search_id' => $search->id, 'csv_import_id' => $search->csv_import_id],
                message: $this->describe($search).' — search failed',
            );

            throw $e;
        }
    }

    /**
     * How a query is named in the log, so a row reads as the car it was for
     * rather than as an id the reader has to look up.
     */
    private function describe(CarSearch $search): string
    {
        $years = $search->from_year === $search->to_year
            ? (string) $search->from_year
            : "{$search->from_year}-{$search->to_year}";

        return "{$search->make} {$search->model} {$years}";
    }

    private function markFailed(CarSearch $search): void
    {
        // CarImageSearchService wraps runSearch in a DB transaction, so a
        // throw inside rolls back the status='running' update — the row is
        // back at 'pending' here. Force it to 'failed' explicitly.
        $search->forceFill(['status' => 'failed'])->save();
    }
}
