<?php

namespace App\Filament\Resources\CarSearchResource\RelationManagers;

use App\Models\CarImage;
use App\Services\Images\CarImageZipService;
use Filament\Actions;
use Filament\Resources\RelationManagers\RelationManager;
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
                Tables\Columns\TextColumn::make('year')
                    ->sortable(),
                Tables\Columns\TextColumn::make('color')
                    ->getStateUsing(function (CarImage $record): string {
                        return $record->color ?? 'All';
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('license')
                    ->limit(20)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('download_status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'downloaded' => 'success',
                        'downloading' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([])
            ->headerActions([])
            /*
             * Grouped to match the Results table. Two actions overflow far
             * less than three did there, so this is consistency rather than
             * rescue: row actions live behind the same dropdown, in the same
             * place, on every image table in the panel.
             */
            ->recordActions([
                Actions\ActionGroup::make([
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
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->tooltip('Preview or delete this image'),
            ])
            ->toolbarActions([
                Actions\BulkAction::make('downloadSelected')
                    ->label('Download selected')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->tooltip('The more images are selected, the slower the download.')
                    ->action(function ($records) {
                        $service = app(CarImageZipService::class);

                        return $service->downloadZip($records);
                    }),
                Actions\DeleteBulkAction::make(),
            ])
            ->poll('1s');
    }
}
