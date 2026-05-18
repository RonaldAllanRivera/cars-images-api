<?php

namespace App\Filament\Resources\CsvImportResource\Pages;

use App\Filament\Resources\CsvImportResource;
use Filament\Actions;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewCsvImport extends ViewRecord
{
    protected static string $resource = CsvImportResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('original_filename')->label('File'),
            TextEntry::make('total_rows')->label('Total rows'),
            TextEntry::make('unique_combos')->label('Unique queries imported'),
            TextEntry::make('duplicates_skipped'),
            TextEntry::make('importer.name')->label('Imported by'),
            TextEntry::make('created_at')->dateTime(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('goToQueries')
                ->label('Go to Search Queries')
                ->url(fn () => route('filament.admin.resources.search-queries.index', ['tableFilters' => ['csv_import_id' => ['value' => $this->record->id]]]))
                ->icon('heroicon-o-arrow-right'),
        ];
    }
}
