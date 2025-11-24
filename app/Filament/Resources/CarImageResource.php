<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CarImageResource\Pages;
use App\Models\CarImage;
use BackedEnum;
use UnitEnum;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CarImageResource extends Resource
{
    protected static ?string $model = CarImage::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-photo';

    protected static UnitEnum|string|null $navigationGroup = 'Cars';

    protected static ?string $navigationLabel = 'Car Images';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail_url')
                    ->label('Image')
                    ->square(),
                Tables\Columns\TextColumn::make('make')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('model')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('year')
                    ->sortable(),
                Tables\Columns\TextColumn::make('color')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('license')
                    ->limit(20)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('download_status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([])
            ->actions([
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\DeleteBulkAction::make(),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(100);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCarImages::route('/'),
        ];
    }
}
