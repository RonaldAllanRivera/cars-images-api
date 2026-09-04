<?php

namespace App\Filament\Resources\ErrorEventResource\Pages;

use App\Filament\Resources\ErrorEventResource;
use App\Models\ErrorEvent;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewErrorEvent extends ViewRecord
{
    protected static string $resource = ErrorEventResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What happened')->schema([
                TextEntry::make('occurred_at')->label('When')->dateTime(),
                TextEntry::make('context')
                    ->formatStateUsing(fn (string $state) => ErrorEvent::contexts()[$state] ?? $state),
                TextEntry::make('severity'),
                TextEntry::make('message')->columnSpanFull(),
            ])->columns(3),

            Section::make('Exception')
                ->schema([
                    TextEntry::make('exception_class')->label('Class'),
                    TextEntry::make('exception_message')->label('Message')->columnSpanFull(),
                    TextEntry::make('trace_excerpt')
                        ->label('Trace')
                        ->fontFamily('mono')
                        ->columnSpanFull(),
                ])
                ->columns(1)
                // Hidden for the string-only rows (a rejected CSV line has no
                // exception), where the section would be three empty labels.
                ->visible(fn (ErrorEvent $record) => $record->exception_class !== null),

            Section::make('Details')
                ->schema([
                    TextEntry::make('details')
                        ->hiddenLabel()
                        ->fontFamily('mono')
                        ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                        ->columnSpanFull(),
                ])
                ->visible(fn (ErrorEvent $record) => $record->details !== null),
        ]);
    }
}
