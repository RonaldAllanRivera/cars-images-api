<?php

namespace App\Filament\Resources\CarSearchResource\Pages;

use App\Filament\Resources\CarSearchResource;
use App\Services\Images\CarImageSearchService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewCarSearch extends ViewRecord
{
    protected static string $resource = CarSearchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
            Actions\Action::make('refreshFromWikimedia')
                ->label('Refresh from Wikimedia')
                ->requiresConfirmation()
                ->color('primary')
                ->action(function () {
                    $search = $this->getRecord();

                    $service = app(CarImageSearchService::class);

                    $service->refreshSearch($search);

                    Notification::make()
                        ->title('Search refreshed from Wikimedia')
                        ->success()
                        ->send();
                }),
        ];
    }
}
