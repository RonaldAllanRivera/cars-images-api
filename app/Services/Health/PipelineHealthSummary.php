<?php

namespace App\Services\Health;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\ErrorEvent;

/**
 * The numbers the mobile health screen shows.
 *
 * Every key is always present so the client can render zeros rather than
 * special-case missing ones. Windows match the admin dashboard: "is the
 * pipeline broken now" is a different question from "has it ever been".
 */
class PipelineHealthSummary
{
    private const STATUSES = ['pending', 'running', 'completed', 'failed'];

    /**
     * @return array{
     *     searches_by_status: array<string, int>,
     *     errors_last_24h: int,
     *     errors_by_context_last_7d: array<string, int>,
     *     images_last_7d: int,
     *     latest_error_at: ?string
     * }
     */
    public function build(): array
    {
        $byStatus = CarSearch::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byContext = ErrorEvent::query()
            ->where('occurred_at', '>=', now()->subDays(7))
            ->selectRaw('context, COUNT(*) as total')
            ->groupBy('context')
            ->pluck('total', 'context');

        $searchesByStatus = [];
        foreach (self::STATUSES as $status) {
            $searchesByStatus[$status] = (int) ($byStatus[$status] ?? 0);
        }

        $errorsByContext = [];
        foreach (array_keys(ErrorEvent::contexts()) as $context) {
            $errorsByContext[$context] = (int) ($byContext[$context] ?? 0);
        }

        return [
            'searches_by_status' => $searchesByStatus,
            'errors_last_24h' => ErrorEvent::query()->where('occurred_at', '>=', now()->subDay())->count(),
            'errors_by_context_last_7d' => $errorsByContext,
            'images_last_7d' => CarImage::query()->where('created_at', '>=', now()->subWeek())->count(),
            'latest_error_at' => ErrorEvent::query()->orderByDesc('occurred_at')->first()?->occurred_at?->toIso8601String(),
        ];
    }
}
