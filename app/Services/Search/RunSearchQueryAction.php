<?php

namespace App\Services\Search;

use App\Exceptions\WikimediaBlockedException;
use App\Models\CarSearch;
use App\Models\WikimediaBlockEvent;
use App\Services\Images\CarImageSearchService;
use Throwable;

class RunSearchQueryAction
{
    public function __construct(
        protected CarImageSearchService $searchService,
    ) {}

    /**
     * Run a single CarSearch synchronously.
     *
     * Marks the search as `completed` on success, `failed` on any throw.
     * On WikimediaBlockedException, records a wikimedia_block_events row
     * before re-throwing so the bulk-run caller can stop the loop.
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

            throw $e;
        } catch (Throwable $e) {
            $this->markFailed($search);

            throw $e;
        }
    }

    private function markFailed(CarSearch $search): void
    {
        // CarImageSearchService wraps runSearch in a DB transaction, so a
        // throw inside rolls back the status='running' update — the row is
        // back at 'pending' here. Force it to 'failed' explicitly.
        $search->forceFill(['status' => 'failed'])->save();
    }
}
