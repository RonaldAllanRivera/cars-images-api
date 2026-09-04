<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ErrorEventResource;
use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\ErrorEvent;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four numbers that decide whether the operator needs to open anything.
 *
 * Every one is scoped to a window. "Is the pipeline broken now" is a different
 * question from "has it ever broken", and only the first belongs on a landing
 * page — an all-time error count would read as alarming forever.
 */
class PipelineHealthOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    public function recentErrorCount(): int
    {
        return ErrorEvent::where('occurred_at', '>=', now()->subDay())->count();
    }

    public function failedSearchCount(): int
    {
        return CarSearch::where('status', 'failed')
            ->where('created_at', '>=', now()->subWeek())
            ->count();
    }

    public function imagesCollectedCount(): int
    {
        return CarImage::where('created_at', '>=', now()->subWeek())->count();
    }

    /**
     * Errors per day over the last week, oldest first, for the sparkline.
     */
    public function weeklyErrorTrend(): array
    {
        $counts = ErrorEvent::query()
            ->where('occurred_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(occurred_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return array_map(
            fn (int $ago): int => (int) ($counts[now()->subDays($ago)->toDateString()] ?? 0),
            range(6, 0),
        );
    }

    private function latestImport(): ?CsvImport
    {
        return CsvImport::latest('id')->first();
    }

    public function latestImportLabel(): string
    {
        return $this->latestImport()?->original_filename ?? 'None yet';
    }

    /**
     * `csv_imports` records what an import contained, never whether it worked.
     * The only honest verdict available is the errors linked back to it.
     */
    public function latestImportDescription(): string
    {
        $import = $this->latestImport();

        if ($import === null) {
            return 'No CSV uploaded yet';
        }

        $errors = ErrorEvent::where('csv_import_id', $import->id)->count();

        return match ($errors) {
            0 => 'No errors',
            1 => '1 error',
            default => "{$errors} errors",
        };
    }

    protected function getStats(): array
    {
        $errors = $this->recentErrorCount();
        $failedSearches = $this->failedSearchCount();

        return [
            Stat::make('Errors (24h)', $errors)
                ->description($errors === 0 ? 'Pipeline is quiet' : 'Open the error log')
                ->descriptionIcon($errors === 0 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->chart($this->weeklyErrorTrend())
                ->color($errors === 0 ? 'success' : 'danger')
                ->url(ErrorEventResource::getUrl()),

            Stat::make('Failed searches (7d)', $failedSearches)
                ->description('Queries that ended in failure')
                ->descriptionIcon('heroicon-m-magnifying-glass')
                ->color($failedSearches === 0 ? 'success' : 'warning'),

            Stat::make('Images collected (7d)', $this->imagesCollectedCount())
                ->description('New rows in the image library')
                ->descriptionIcon('heroicon-m-photo')
                ->color('primary'),

            Stat::make('Latest import', $this->latestImportLabel())
                ->description($this->latestImportDescription())
                ->descriptionIcon('heroicon-m-document-arrow-up')
                ->color('gray'),
        ];
    }
}
