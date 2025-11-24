<?php

namespace App\Filament\Resources\CarSearchResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Table;

class CarImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail_url')
                    ->label('Image')
                    ->square(),
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
            ->headerActions([])
            ->actions([
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\DeleteBulkAction::make(),
            ]);
    }
}
