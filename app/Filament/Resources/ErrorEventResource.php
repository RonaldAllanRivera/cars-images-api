<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ErrorEventResource\Pages;
use App\Models\ErrorEvent;
use BackedEnum;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The pipeline error log: read-only, because nothing here is authored by hand.
 * Rows arrive from ErrorEventLogger and leave by pruning or bulk delete.
 */
class ErrorEventResource extends Resource
{
    protected static ?string $model = ErrorEvent::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static UnitEnum|string|null $navigationGroup = 'Logs';

    protected static ?string $navigationLabel = 'Error log';

    protected static ?string $modelLabel = 'error event';

    public static function form(Schema $schema): Schema
    {
        // Nothing authors an error event by hand; there is no form.
        return $schema;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Failures in the last day, or null when there are none.
     *
     * Scoped to 24 hours rather than to the whole table so the badge means
     * "something is wrong now" rather than "something went wrong once".
     */
    public static function getNavigationBadge(): ?string
    {
        $recent = ErrorEvent::where('occurred_at', '>=', now()->subDay())->count();

        return $recent > 0 ? (string) $recent : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('context')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ErrorEvent::contexts()[$state] ?? $state)
                    ->color(fn (string $state) => ErrorEvent::contextColor($state)),
                Tables\Columns\TextColumn::make('message')
                    ->searchable()
                    ->wrap()
                    ->limit(120),
                Tables\Columns\TextColumn::make('related')
                    ->label('Related')
                    ->state(fn (ErrorEvent $record) => self::relatedLabel($record))
                    ->url(fn (ErrorEvent $record) => self::relatedUrl($record))
                    ->color('primary'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('context')
                    ->options(ErrorEvent::contexts()),
                Tables\Filters\SelectFilter::make('csv_import_id')
                    ->label('CSV import')
                    ->relationship('csvImport', 'original_filename'),
                Tables\Filters\Filter::make('occurred_at')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '<=', $date))),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
            ])
            ->toolbarActions([
                Actions\DeleteBulkAction::make(),
            ]);
    }

    /**
     * The record this failure was about, named so the operator can recognise
     * it without opening the row.
     */
    private static function relatedLabel(ErrorEvent $record): ?string
    {
        if ($record->car_image_id !== null) {
            return 'Image #'.$record->car_image_id;
        }

        if ($record->car_search_id !== null) {
            $search = $record->carSearch;

            return $search ? "{$search->make} {$search->model}" : 'Query #'.$record->car_search_id;
        }

        if ($record->csv_import_id !== null) {
            return $record->csvImport?->original_filename ?? 'Import #'.$record->csv_import_id;
        }

        return null;
    }

    private static function relatedUrl(ErrorEvent $record): ?string
    {
        if ($record->car_search_id !== null) {
            return CarSearchResource::getUrl('view', ['record' => $record->car_search_id]);
        }

        if ($record->csv_import_id !== null) {
            return CsvImportResource::getUrl('view', ['record' => $record->csv_import_id]);
        }

        return null;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListErrorEvents::route('/'),
            'view' => Pages\ViewErrorEvent::route('/{record}'),
        ];
    }
}
