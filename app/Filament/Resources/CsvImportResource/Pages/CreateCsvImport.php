<?php

namespace App\Filament\Resources\CsvImportResource\Pages;

use App\Filament\Resources\CsvImportResource;
use App\Services\Imports\CsvImportException;
use App\Services\Imports\CsvQueryImporter;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;

class CreateCsvImport extends CreateRecord
{
    protected static string $resource = CsvImportResource::class;

    protected static ?string $title = 'Upload CSV';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('csv_file')
                ->label('CSV file (Make,Model,Year,Transmission)')
                ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                ->maxSize(5 * 1024) // 5 MB
                ->storeFiles(false)
                ->required(),
        ]);
    }

    protected function handleRecordCreation(array $data): \App\Models\CsvImport
    {
        /** @var UploadedFile $file */
        $file = $data['csv_file'];

        try {
            $result = app(CsvQueryImporter::class)->import($file, auth()->user());
        } catch (CsvImportException $e) {
            Notification::make()
                ->title('Import rejected')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }

        Notification::make()
            ->title('CSV imported')
            ->body("{$result->csvImport->unique_combos} queries imported, {$result->csvImport->duplicates_skipped} duplicates skipped, {$result->skippedInvalidRows} invalid rows skipped.")
            ->success()
            ->send();

        return $result->csvImport;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
