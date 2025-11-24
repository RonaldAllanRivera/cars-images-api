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
                    ->square()
                    ->action(
                        Actions\Action::make('previewImage')
                            ->modalHeading('Image preview')
                            ->modalContent(fn (CarImage $record) => view('filament.components.car-image-preview', [
                                'imageUrl' => $record->thumbnail_url ?? $record->source_url,
                                'sourceUrl' => $record->source_url,
                                'title' => $record->title,
                            ]))
                            ->modalSubmitAction(false)
                            ->modalCancelAction(fn ($action) => $action->label('Close'))
                            ->extraModalFooterActions([
                                Actions\Action::make('download')
                                    ->label('Download')
                                    ->icon('heroicon-o-arrow-down-tray')
                                    ->color('primary')
                                    ->url(fn (CarImage $record) => route('car-images.download', $record))
                                    ->openUrlInNewTab(),
                            ])
                    ),
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
                Actions\Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-magnifying-glass-plus')
                    ->modalHeading('Image preview')
                    ->modalContent(fn (CarImage $record) => view('filament.components.car-image-preview', [
                        'imageUrl' => $record->thumbnail_url ?? $record->source_url,
                        'sourceUrl' => $record->source_url,
                        'title' => $record->title,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelAction(fn ($action) => $action->label('Close'))
                    ->extraModalFooterActions([
                        Actions\Action::make('download')
                            ->label('Download')
                            ->icon('heroicon-o-arrow-down-tray')
                            ->color('primary')
                            ->url(fn (CarImage $record) => route('car-images.download', $record))
                            ->openUrlInNewTab(),
                    ]),
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
