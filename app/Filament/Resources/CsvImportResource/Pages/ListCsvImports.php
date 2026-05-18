<?php

namespace App\Filament\Resources\CsvImportResource\Pages;

use App\Filament\Resources\CsvImportResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCsvImports extends ListRecords
{
    protected static string $resource = CsvImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Upload CSV'),
        ];
    }
}
