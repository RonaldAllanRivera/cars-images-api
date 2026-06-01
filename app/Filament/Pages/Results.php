<?php

namespace App\Filament\Pages;

use App\Models\CarImage;
use App\Services\Downloads\BatchCsvExporter;
use App\Services\Downloads\BatchZipBuilder;
use BackedEnum;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class Results extends Page implements HasTable
{
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-photo';

    protected static UnitEnum|string|null $navigationGroup = 'Cars';

    protected static ?string $navigationLabel = 'Results';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.results';

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $query = CarImage::query()
                    ->whereHas('search', fn (Builder $q) => $q->whereNotNull('csv_import_id'))
                    ->with('search.csvImport');

                $searchId = request()->query('searchId');
                if ($searchId !== null && $searchId !== '') {
                    $query->where('car_search_id', (int) $searchId);
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
            ])
            ->filters([
                SelectFilter::make('csv_import_id')
                    ->label('CSV Import')
                    ->relationship('search.csvImport', 'original_filename'),
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
                    ->action(function (Collection $records, BatchZipBuilder $builder) {
                        $images = $records->loadMissing('search');

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

                        $filename = 'cars-batch-' . now()->format('Ymd-His') . '.zip';

                        return response()->download($tmpPath, $filename, [
                            'Content-Type' => 'application/zip',
                        ])->deleteFileAfterSend(true);
                    }),
                Actions\BulkAction::make('exportCsv')
                    ->label('Export Selected as CSV')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(function (Collection $records, BatchCsvExporter $exporter) {
                        $images = $records->loadMissing('search');
                        $filename = 'cars-batch-' . now()->format('Ymd-His') . '.csv';

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

    public static function getRouteName(?Panel $panel = null): string
    {
        return 'filament.admin.pages.results';
    }
}
