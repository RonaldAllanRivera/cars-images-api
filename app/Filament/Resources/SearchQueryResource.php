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
            // Rendered inside the page's Livewire component, so wire:poll binds
            // to ListSearchQueries. Returning null when idle matters: a poll
            // that is always present would drive runNextChunk once a second on
            // a page where nobody is running anything.
            ->header(fn ($livewire) => $livewire instanceof Pages\ListSearchQueries
                && ($livewire->runActive || $livewire->runBlockMessage !== null)
                    ? view('filament.bulk-run-progress', [
                        'active' => $livewire->runActive,
                        'total' => $livewire->runTotal,
                        'processed' => $livewire->runProcessed,
                        'failed' => $livewire->runFailed,
                        'blockMessage' => $livewire->runBlockMessage,
                        'secondsRemaining' => $livewire->runSecondsRemaining(),
                    ])
                    : null)
            ->poll('3s')
            ->columns([
                Tables\Columns\TextColumn::make('from_year')->label('Year')->sortable(),
                Tables\Columns\TextColumn::make('make')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('model')->searchable(),
                Tables\Columns\TextColumn::make('commons_category')
                    ->label('Commons category')
                    ->placeholder('none found')
                    ->toggleable()
                    ->tooltip('The Commons category this search read. Empty means no category could be resolved from the model string. Set, with zero images, means the category holds no photograph naming that year.'),
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
                                ->body("HTTP {$e->statusCode} — see Block Events. Retry-After: ".($e->retryAfterSeconds ?? 'n/a').'s')
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
                    ->modalDescription('Runs every selected query, a few at a time, and reports progress as it goes. You can pause at any point, and Wikimedia rate-limiting stops it automatically.')
                    // Seeds the queue and returns immediately. The work happens
                    // in ListSearchQueries::runNextChunk(), one bounded request
                    // per poll — doing it here would put the whole selection
                    // inside a single request and back into max_execution_time
                    // territory.
                    ->action(fn ($records, $livewire) => $livewire->startBulkRun(
                        collect($records)->pluck('id')->all(),
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSearchQueries::route('/'),
        ];
    }
}
