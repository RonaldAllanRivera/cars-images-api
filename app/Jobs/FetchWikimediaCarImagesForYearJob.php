<?php

namespace App\Jobs;

use App\Models\CarSearch;
use App\Services\Images\CarImageSearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class FetchWikimediaCarImagesForYearJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $carSearchId,
        public int $year,
        public int $limit
    ) {
    }

    public function handle(CarImageSearchService $service): void
    {
        $search = CarSearch::find($this->carSearchId);

        if (! $search) {
            return;
        }

        try {
            $service->fetchAndStoreForYear($search, $this->year, $this->limit);
        } catch (Throwable $e) {
            // Optionally, we could update the search status here.
            throw $e;
        }
    }
}
