<?php

namespace App\Filament\Pages;

use App\Models\CarImage;
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
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function ($q) use ($search) {
                            $q->where('make', 'like', "%{$search}%")
                                ->orWhere('model', 'like', "%{$search}%")
                                ->orWhere('year', 'like', "%{$search}%");
                        });
                    }),
                Tables\Columns\TextColumn::make('search.csvImport.original_filename')
                    ->label('Source CSV')
                    ->limit(30),
                Tables\Columns\TextColumn::make('year')->sortable(),
                Tables\Columns\TextColumn::make('make')->sortable(),
                Tables\Columns\TextColumn::make('model'),
                Tables\Columns\TextColumn::make('make_confirmed')
                    ->label('Make match')
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
            ->recordActions([
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
