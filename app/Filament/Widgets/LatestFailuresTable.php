<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ErrorEventResource;
use App\Models\ErrorEvent;
use Filament\Actions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * A shortcut into the error log, not a second copy of it.
 *
 * Ten rows, no filters and no search: anything more and the operator should be
 * on the log page itself, which is exactly where every row here links.
 */
class LatestFailuresTable extends TableWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Latest failures')
            ->query(ErrorEvent::query()->latest('occurred_at')->limit(10))
            // Ten rows is the whole point; a paginator over a fixed ten would
            // only ever show one page and take up room saying so.
            ->paginated(false)
            ->emptyStateHeading('Nothing has failed')
            ->emptyStateDescription('The pipeline has recorded no errors.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('When')
                    ->since()
                    ->tooltip(fn (ErrorEvent $record): string => (string) $record->occurred_at),
                TextColumn::make('context')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ErrorEvent::contexts()[$state] ?? $state)
                    ->color(fn (string $state): string => ErrorEvent::contextColor($state)),
                TextColumn::make('message')
                    ->wrap()
                    ->limit(100),
            ])
            ->recordActions([
                Actions\ViewAction::make()
                    ->url(fn (ErrorEvent $record): string => ErrorEventResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
