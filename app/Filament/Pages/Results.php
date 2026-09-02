<?php

namespace App\Filament\Pages;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Services\Downloads\BatchCsvExporter;
use App\Services\Downloads\BatchZipBuilder;
use BackedEnum;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

class Results extends Page implements HasTable
{
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-photo';

    protected static UnitEnum|string|null $navigationGroup = 'Cars';

    protected static ?string $navigationLabel = 'Results';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.results';

    /**
     * Optional scope: show only the images of one search query.
     *
     * This must be component state, not `request()->query()`. Livewire's
     * update requests carry no query string, so reading the request would
     * drop the scope on the first pagination, sort, search, or bulk action —
     * silently widening the table (and `DeleteBulkAction`'s select-all) to
     * every csv-imported image in the database.
     *
     * `#[Url]` keeps it in the address bar so the link from Search Queries
     * still works and the page stays shareable.
     */
    #[Url]
    public ?string $searchId = null;

    public function table(Table $table): Table
    {
        return $table
            // Sits above the toolbar, not in place of it, so search and filters
            // are untouched. Returns null when there is no import to describe.
            ->header(fn () => ($coverage = $this->coverage()) === null
                ? null
                : view('filament.pages.results-coverage', ['coverage' => $coverage]))
            ->query(function () {
                $query = CarImage::query()
                    ->whereHas('search', fn (Builder $q) => $q->whereNotNull('csv_import_id'))
                    ->with('search.csvImport');

                if ($this->searchId !== null && $this->searchId !== '') {
                    $query->where('car_search_id', (int) $this->searchId);
                }

                return $query;
            })
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail_url')
                    ->label('Thumbnail')
                    ->imageSize(120),
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Name')
                    ->state(fn (CarImage $record) => "{$record->year} {$record->make} {$record->model}")
                    // Sorting lived on the separate Year/Make columns, which are now
                    // hidden by default. Sorting this one covers both.
                    ->sortable(['year', 'make', 'model'])
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function ($q) use ($search) {
                            $q->where('make', 'like', "%{$search}%")
                                ->orWhere('model', 'like', "%{$search}%")
                                ->orWhere('year', 'like', "%{$search}%");
                        });
                    }),
                Tables\Columns\TextColumn::make('search.csvImport.original_filename')
                    ->label('Source CSV')
                    ->limit(30)
                    // Drops out below `lg`, where width is scarcest. The "CSV Import"
                    // filter still answers "which import is this?" on small screens.
                    ->visibleFrom('lg'),
                // Year, Make and Model repeat what the Name column already shows, at a
                // cost of ~270px. Hidden by default and restorable from the table's
                // "Columns" menu for anyone who wants to sort or scan a single field.
                Tables\Columns\TextColumn::make('year')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('make')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('model')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('make_confirmed')
                    ->label('Make match')
                    // Below `sm` these two badges are the only thing still forcing a
                    // sideways scroll. Both have a filter, so nothing is unreachable.
                    ->visibleFrom('sm')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match (true) {
                        $state === true || $state === 1 => 'Confirmed',
                        $state === false || $state === 0 => 'Not confirmed',
                        default => 'Unknown',
                    })
                    ->color(fn ($state) => match (true) {
                        $state === true || $state === 1 => 'success',
                        $state === false || $state === 0 => 'warning',
                        default => 'gray',
                    })
                    ->tooltip('Whether the searched make actually appears in the image title, description, or categories.'),
                Tables\Columns\TextColumn::make('year_confirmed')
                    ->label('Year match')
                    // Below `sm` these two badges are the only thing still forcing a
                    // sideways scroll. Both have a filter, so nothing is unreachable.
                    ->visibleFrom('sm')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match (true) {
                        $state === true || $state === 1 => 'Year-specific',
                        $state === false || $state === 0 => 'Not year-specific',
                        default => 'Unknown',
                    })
                    ->color(fn ($state) => match (true) {
                        $state === true || $state === 1 => 'success',
                        $state === false || $state === 0 => 'warning',
                        default => 'gray',
                    })
                    ->tooltip('"Not year-specific" means the year search found nothing usable and the image came from a search with the year dropped. Adjacent years can legitimately return the same photograph.'),
            ])
            ->filters([
                SelectFilter::make('csv_import_id')
                    ->label('CSV Import')
                    ->relationship('search.csvImport', 'original_filename'),
                SelectFilter::make('make_confirmed')
                    ->label('Make match')
                    ->options([
                        '1' => 'Confirmed',
                        '0' => 'Not confirmed',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if ($data['value'] === '1') {
                            return $query->where('make_confirmed', true);
                        }
                        if ($data['value'] === '0') {
                            return $query->where('make_confirmed', false);
                        }

                        return $query;
                    }),
                SelectFilter::make('year_confirmed')
                    ->label('Year match')
                    ->options([
                        '1' => 'Year-specific',
                        '0' => 'Not year-specific',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if ($data['value'] === '1') {
                            return $query->where('year_confirmed', true);
                        }
                        if ($data['value'] === '0') {
                            return $query->where('year_confirmed', false);
                        }

                        return $query;
                    }),
            ])
            /*
             * Grouped, not inline. Rendered side by side these three actions took a
             * ~283px column - on their own more than the table overflowed its
             * scroll container by - so Delete sat past the right edge and could
             * only be reached by scrolling sideways. As a dropdown the column is
             * one icon wide and every action is reachable at any viewport width.
             */
            ->recordActions([
                Actions\ActionGroup::make([
                    Actions\Action::make('preview')
                        ->label('Preview')
                        ->icon('heroicon-o-eye')
                        ->url(fn (CarImage $record) => $record->source_url, true),
                    Actions\Action::make('download')
                        ->label('Download')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function (CarImage $record) {
                            return redirect()->route('car-images.download', ['carImage' => $record->id]);
                        }),
                    Actions\DeleteAction::make(),
                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Preview, download or delete this image'),
            ])
            ->toolbarActions([
                Actions\BulkAction::make('downloadZip')
                    ->label('Download Selected as ZIP')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->action(fn (Collection $records, BatchZipBuilder $builder) => $this->zipDownload($records, $builder)),
                Actions\BulkAction::make('downloadConfirmedZip')
                    ->label('Download Confirmed as ZIP')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->action(function (Collection $records, BatchZipBuilder $builder) {
                        $confirmed = $records->filter(fn (CarImage $image) => (bool) $image->make_confirmed)->values();

                        if ($confirmed->isEmpty()) {
                            Notification::make()
                                ->title('No confirmed images in selection')
                                ->body('None of the selected images have a confirmed make match. Use the "Make match" badge/filter to find confirmed ones.')
                                ->warning()
                                ->send();

                            return null;
                        }

                        return $this->zipDownload($confirmed, $builder);
                    }),
                Actions\BulkAction::make('exportCsv')
                    ->label('Export Selected as CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(function (Collection $records, BatchCsvExporter $exporter) {
                        $images = $records->loadMissing('search');
                        $filename = 'cars-batch-'.now()->format('Ymd-His').'.csv';

                        return response()->streamDownload(
                            function () use ($exporter, $images) {
                                $handle = fopen('php://output', 'w');
                                $exporter->streamTo($handle, $images);
                                fclose($handle);
                            },
                            $filename,
                            ['Content-Type' => 'text/csv; charset=UTF-8'],
                        );
                    }),
                Actions\DeleteBulkAction::make(),
            ])
            ->paginated([24, 48, 96]);
    }

    /**
     * How much of the CSV import in view has actually been searched.
     *
     * This table can only show images that exist, so a run that stopped
     * early and a run that finished having found little look identical:
     * both render a short list under a confident "Showing 1 to N of N
     * results". Counting the *searches* behind those rows separates the
     * two — how many never ran, and how many ran and came back empty.
     *
     * Null when there is nothing to describe, which hides the panel.
     *
     * @return array{importName: ?string, total: int, searched: int, notRun: int, failed: int, withImages: int, noImages: int, notRunUrl: string, noImagesUrl: string}|null
     */
    public function coverage(): ?array
    {
        $importId = $this->coverageImportId();

        // Re-built per count rather than cloned: `whereHas` on a shared
        // builder would leak its subquery into the counts that follow.
        $searches = fn (): Builder => CarSearch::query()
            ->whereNotNull('csv_import_id')
            ->when($importId !== null, fn (Builder $q) => $q->where('csv_import_id', $importId));

        $total = $searches()->count();

        if ($total === 0) {
            return null;
        }

        $notRun = $searches()->whereIn('status', ['pending', 'running'])->count();

        return [
            'importName' => $importId === null
                ? null
                : CsvImport::whereKey($importId)->value('original_filename'),
            'total' => $total,
            'searched' => $total - $notRun,
            'notRun' => $notRun,
            'failed' => $searches()->where('status', 'failed')->count(),
            'withImages' => $searches()->whereHas('images')->count(),
            'noImages' => $searches()->where('status', 'completed')->whereDoesntHave('images')->count(),
            'notRunUrl' => $this->searchQueriesUrl($importId, 'not_run'),
            'noImagesUrl' => $this->searchQueriesUrl($importId, 'no_images'),
        ];
    }

    /**
     * The import the coverage panel describes: the filtered one, else the
     * one owning the single search being viewed, else every import.
     */
    private function coverageImportId(): ?int
    {
        $filtered = $this->getTableFilterState('csv_import_id')['value'] ?? null;

        if (filled($filtered)) {
            return (int) $filtered;
        }

        if (filled($this->searchId)) {
            return CarSearch::whereKey((int) $this->searchId)->value('csv_import_id');
        }

        return null;
    }

    /**
     * Deep-link into Search Queries with its Coverage filter pre-applied, so
     * "23 not run yet" lands on exactly those 23 rows, ready to run.
     */
    private function searchQueriesUrl(?int $importId, string $coverage): string
    {
        $filters = ['coverage' => ['value' => $coverage]];

        if ($importId !== null) {
            $filters['csv_import_id'] = ['value' => (string) $importId];
        }

        // Filament 5 reads table filters from `filters` in the query string
        // (v3's `tableFilters` is gone). Wrong key = a link that silently
        // lands on the unfiltered list, which is worse than no link.
        return route('filament.admin.resources.search-queries.index', ['filters' => $filters]);
    }

    /**
     * Build and stream a web-optimized ZIP of the given images.
     *
     * Shared by the "Download Selected" and "Download Confirmed" bulk
     * actions. Enforces the bulk_download_max_images cap (the ZIP is built
     * synchronously in one web request, so large sets would time out on
     * shared hosting), and surfaces clear notifications instead of failing.
     */
    protected function zipDownload(Collection $images, BatchZipBuilder $builder): mixed
    {
        $images = $images->loadMissing('search');

        $max = (int) config('cars-images.bulk_download_max_images', 100);
        if ($images->count() > $max) {
            Notification::make()
                ->title('Too many images selected')
                ->body("Bulk ZIP is limited to {$max} images per download on this server. Select fewer and try again.")
                ->warning()
                ->send();

            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'cars-batch-');
        $added = $builder->buildToFile($images, $tmpPath);

        if ($added === 0) {
            @unlink($tmpPath);

            Notification::make()
                ->title('No images could be downloaded')
                ->body('None of the selected images could be fetched from Wikimedia. Please try again in a moment.')
                ->danger()
                ->send();

            return null;
        }

        CarImage::whereIn('id', $images->pluck('id'))
            ->update(['download_status' => 'downloaded']);

        $filename = 'cars-batch-'.now()->format('Ymd-His').'.zip';

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public static function getRouteName(?Panel $panel = null): string
    {
        return 'filament.admin.pages.results';
    }
}
