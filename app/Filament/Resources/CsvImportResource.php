<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CsvImportResource\Pages;
use App\Models\CsvImport;
use BackedEnum;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class CsvImportResource extends Resource
{
    protected static ?string $model = CsvImport::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static UnitEnum|string|null $navigationGroup = 'Cars';

    protected static ?string $navigationLabel = 'Upload CSV';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        // The upload form is implemented in CreateCsvImport (custom page).
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('File')
                    ->searchable()
                    ->limit(40),
                Tables\Columns\TextColumn::make('unique_combos')
                    ->label('Queries')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('duplicates_skipped')
                    ->label('Dupes skipped')
                    ->numeric(),
                Tables\Columns\TextColumn::make('importer.name')
                    ->label('Imported by'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('This will also delete all imported queries and their images.'),
            ])
            ->toolbarActions([
                Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCsvImports::route('/'),
            'create' => Pages\CreateCsvImport::route('/create'),
            'view' => Pages\ViewCsvImport::route('/{record}'),
        ];
    }
}
