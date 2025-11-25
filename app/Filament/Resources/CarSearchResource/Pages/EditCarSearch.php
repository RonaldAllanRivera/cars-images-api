<?php

namespace App\Filament\Resources\CarSearchResource\Pages;

use App\Filament\Resources\CarSearchResource;
use App\Services\Images\CarImageSearchService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCarSearch extends EditRecord
{
    protected static string $resource = CarSearchResource::class;

    protected function afterSave(): void
    {
        $search = $this->getRecord();

        $service = app(CarImageSearchService::class);

        $service->refreshSearch($search);

        Notification::make()
            ->title('Search updated and re-run with new filters')
            ->success()
            ->send();
    }
}
