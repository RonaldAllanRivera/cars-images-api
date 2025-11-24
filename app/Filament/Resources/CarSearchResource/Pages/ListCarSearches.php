<?php

namespace App\Filament\Resources\CarSearchResource\Pages;

use App\Filament\Resources\CarSearchResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCarSearches extends ListRecords
{
    protected static string $resource = CarSearchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
