<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CarSearchResource\Pages;
use App\Filament\Resources\CarSearchResource\RelationManagers\CarImagesRelationManager;
use App\Models\CarSearch;
use BackedEnum;
use UnitEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class CarSearchResource extends Resource
{
    protected static ?string $model = CarSearch::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static UnitEnum|string|null $navigationGroup = 'Cars';

    protected static ?string $navigationLabel = 'Car Image Searches';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('make')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('model')
                ->maxLength(255),
            Forms\Components\TextInput::make('from_year')
                ->numeric()
                ->required()
                ->minValue(1900)
                ->maxValue((int) date('Y') + 1),
            Forms\Components\TextInput::make('to_year')
                ->numeric()
                ->required()
                ->minValue(1900)
                ->maxValue((int) date('Y') + 1),
            Forms\Components\TextInput::make('color')
                ->maxLength(255),
            Forms\Components\Toggle::make('transparent_background')
                ->label('Transparent background'),
            Forms\Components\TextInput::make('images_per_year')
                ->numeric()
                ->default(10)
                ->minValue(1)
                ->maxValue(50),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('make')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('model')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('from_year')
                    ->label('From')
                    ->sortable(),
                Tables\Columns\TextColumn::make('to_year')
                    ->label('To')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'running',
                        'success' => 'completed',
                        'danger' => 'failed',
                    ]),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            CarImagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCarSearches::route('/'),
            'create' => Pages\CreateCarSearch::route('/create'),
            'view' => Pages\ViewCarSearch::route('/{record}'),
        ];
    }
}
