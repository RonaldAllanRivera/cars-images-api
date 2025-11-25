<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CarMakeResource\Pages;
use App\Models\CarMake;
use BackedEnum;
use UnitEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class CarMakeResource extends Resource
{
    protected static ?string $model = CarMake::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static UnitEnum|string|null $navigationGroup = 'Cars';

    protected static ?string $navigationLabel = 'Car Makes';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('name')
                ->label('Make')
                ->required()
                ->maxLength(100),
            Forms\Components\Repeater::make('models')
                ->relationship()
                ->label('Models')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Model')
                        ->required()
                        ->maxLength(100),
                ])
                ->defaultItems(0),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Make')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('models_count')
                    ->label('Models')
                    ->counts('models')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([])
            ->actions([
                Actions\EditAction::make(),
            ])
            ->bulkActions([
                Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCarMakes::route('/'),
            'create' => Pages\CreateCarMake::route('/create'),
            'edit' => Pages\EditCarMake::route('/{record}/edit'),
        ];
    }
}
