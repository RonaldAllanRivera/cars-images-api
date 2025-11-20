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

class RunCarSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public CarSearch $search)
    {
    }

    public function handle(CarImageSearchService $service): void
    {
        $search = $this->search->fresh();

        if (! $search) {
            return;
        }

        try {
            $service->runSearch($search);
        } catch (Throwable $e) {
            $search->update(['status' => 'failed']);

            throw $e;
        }
    }
}
