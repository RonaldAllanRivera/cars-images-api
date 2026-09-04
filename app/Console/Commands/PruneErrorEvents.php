<?php

namespace App\Console\Commands;

use App\Models\ErrorEvent;
use Illuminate\Console\Command;

/**
 * Delete error events past their retention window.
 *
 * Deliberately NOT registered on the scheduler: there is no cron on the
 * production host, so a scheduled prune would silently never run. Pruning is
 * something the operator triggers — from here, or from the Prune action on the
 * log page, which calls prune() below so there is one implementation of the
 * retention rule rather than two that can disagree.
 */
class PruneErrorEvents extends Command
{
    protected $signature = 'error-events:prune';

    protected $description = 'Delete error log entries older than the configured retention window';

    public function handle(): int
    {
        $days = self::retentionDays();
        $deleted = self::prune();

        $this->info("Deleted {$deleted} error event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    public static function retentionDays(): int
    {
        return (int) config('cars-images.error_log_retention_days');
    }

    public static function prunableCount(): int
    {
        return ErrorEvent::where('occurred_at', '<', now()->subDays(self::retentionDays()))->count();
    }

    public static function prune(): int
    {
        return ErrorEvent::where('occurred_at', '<', now()->subDays(self::retentionDays()))->delete();
    }
}
