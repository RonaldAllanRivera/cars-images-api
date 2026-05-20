<?php

namespace App\Filament\Resources;

use App\Exceptions\WikimediaBlockedException;
use App\Filament\Resources\SearchQueryResource\Pages;
use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Services\Search\RunSearchQueryAction;
use BackedEnum;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use UnitEnum;

class SearchQueryResource extends Resource
{
    protected static ?string $model = CarSearch::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-queue-list';

    protected static UnitEnum|string|null $navigationGroup = 'Cars';

    protected static ?string $navigationLabel = 'Search Queries';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'search-queries';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('csv_import_id');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('3s')
            ->columns([
                Tables\Columns\TextColumn::make('from_year')->label('Year')->sortable(),
                Tables\Columns\TextColumn::make('make')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('model')->searchable(),
                Tables\Columns\TextColumn::make('csvImport.original_filename')->label('Source CSV')->limit(30),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'pending',
                        'warning' => 'running',
                        'success' => 'completed',
                        'danger' => 'failed',
                    ]),
                Tables\Columns\TextColumn::make('images_count')
                    ->label('Images')
                    ->counts('images'),
            ])
            ->defaultSort('id', 'asc')
            ->filters([
                SelectFilter::make('csv_import_id')
                    ->label('CSV Import')
                    ->options(fn () => CsvImport::pluck('original_filename', 'id')->all()),
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'running' => 'Running',
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                ]),
            ])
            ->recordActions([
                Actions\Action::make('run')
                    ->label('Run')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->visible(fn (CarSearch $record) => in_array($record->status, ['pending', 'failed'], true))
                    ->action(function (CarSearch $record) {
                        try {
                            app(RunSearchQueryAction::class)->execute($record);

                            Notification::make()
                                ->title('Query complete')
                                ->body("{$record->from_year} {$record->make} {$record->model} — done.")
                                ->success()
                                ->send();
                        } catch (WikimediaBlockedException $e) {
                            Notification::make()
                                ->title('Wikimedia blocked')
                                ->body("HTTP {$e->statusCode} — see Block Events. Retry-After: " . ($e->retryAfterSeconds ?? 'n/a') . 's')
                                ->danger()
                                ->persistent()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Query failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Actions\Action::make('viewResults')
                    ->label('View results')
                    ->icon('heroicon-o-photo')
                    ->color('success')
                    ->visible(fn (CarSearch $record) => $record->status === 'completed')
                    ->url(fn (CarSearch $record) => route('filament.admin.pages.results', ['searchId' => $record->id])),
            ])
            ->toolbarActions([
                Actions\BulkAction::make('runSelected')
                    ->label('Run Selected')
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Runs up to ' . config('cars-images.bulk_run_max_queries_per_chunk') . ' queries OR ' . config('cars-images.bulk_run_max_seconds_per_chunk') . ' seconds, whichever first. Click again to continue.')
                    ->action(function ($records) {
                        $maxQueries = (int) config('cars-images.bulk_run_max_queries_per_chunk');
                        $maxSeconds = (int) config('cars-images.bulk_run_max_seconds_per_chunk');
                        $sleepSeconds = (int) config('cars-images.bulk_run_sleep_seconds_between_queries');

                        $start = microtime(true);
                        $processed = 0;
                        $blocked = false;
                        $blockMessage = null;

                        foreach ($records as $record) {
                            if ($processed >= $maxQueries) {
                                break;
                            }
                            if (microtime(true) - $start >= $maxSeconds) {
                                break;
                            }
                            if (! in_array($record->status, ['pending', 'failed'], true)) {
                                continue;
                            }

                            try {
                                app(RunSearchQueryAction::class)->execute($record);
                            } catch (WikimediaBlockedException $e) {
                                $blocked = true;
                                $blockMessage = "HTTP {$e->statusCode} after {$processed} queries. Retry-After: " . ($e->retryAfterSeconds ?? 'n/a') . 's';
                                break;
                            } catch (Throwable $e) {
                                // continue past individual non-block failures
                            }

                            $processed++;
                            if ($sleepSeconds > 0) {
                                sleep($sleepSeconds);
                            }
                        }

                        if ($blocked) {
                            Notification::make()
                                ->title('Bulk run paused — Wikimedia blocked')
                                ->body($blockMessage)
                                ->danger()
                                ->persistent()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Bulk run finished')
                                ->body("Processed {$processed} queries this chunk. Click 'Run Selected' again to continue.")
                                ->success()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSearchQueries::route('/'),
        ];
    }
}
