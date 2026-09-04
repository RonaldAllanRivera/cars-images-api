<?php

namespace App\Filament\Resources\CsvImportResource\Pages;

use App\Filament\Resources\CsvImportResource;
use App\Models\CsvImport;
use App\Services\Imports\CsvImportException;
use App\Services\Imports\CsvQueryImporter;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;

class CreateCsvImport extends CreateRecord
{
    protected static string $resource = CsvImportResource::class;

    protected static ?string $title = 'Upload CSV';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make('Before you upload — what a full CSV actually costs')
                ->description(self::apiDownloadCostNote())
                ->icon(Heroicon::ExclamationTriangle)
                ->color('warning'),

            FileUpload::make('csv_file')
                ->label('CSV file (Make,Model,Year,Transmission)')
                ->helperText(self::importLimitNote())
                ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                ->maxSize((int) config('cars-images.csv_import_max_upload_kb'))
                ->storeFiles(false)
                ->required(),
        ]);
    }

    protected function handleRecordCreation(array $data): CsvImport
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

    /**
     * Upload limits shown to the admin. Reads the caps from config so the note
     * cannot drift from what CsvQueryImporter and the upload rule enforce.
     */
    public static function importLimitNote(): string
    {
        return sprintf(
            'Up to %s unique queries per upload, max %s MB. Rows are deduplicated by Year + Make + Model, so the cap applies to the de-duplicated count, not the raw row count.',
            number_format((int) config('cars-images.csv_import_max_combos')),
            rtrim(rtrim(number_format((int) config('cars-images.csv_import_max_upload_kb') / 1024, 1), '0'), '.'),
        );
    }

    /**
     * The standing reminder: a CSV that passes the row cap still commits the
     * app to a long, paced run against the Wikimedia API and to downloading
     * the results in many separate ZIPs. Every figure is derived from config,
     * so changing a limit in .env rewrites this note rather than stranding it.
     */
    public static function apiDownloadCostNote(): string
    {
        $maxCombos = (int) config('cars-images.csv_import_max_combos');
        $imagesPerQuery = (int) config('cars-images.csv_import_default_images_per_year');
        $maxProjected = (int) config('cars-images.csv_import_max_projected_images');
        $sleepSeconds = (float) config('cars-images.bulk_run_sleep_seconds_between_queries');
        $imagesPerZip = (int) config('cars-images.bulk_download_max_images');

        $projectedImages = $maxCombos * $imagesPerQuery;
        $runMinutes = (int) ceil(($maxCombos * $sleepSeconds) / 60);
        $zipCount = $imagesPerZip > 0 ? (int) ceil($projectedImages / $imagesPerZip) : 0;

        return sprintf(
            'A full %s-query CSV fetches %s images each — up to %s image downloads from the Wikimedia API, '
            .'hard-capped at %s. Runs are paced at %ss per query, so a full CSV takes roughly %s minutes of '
            .'continuous running, and the results leave the app %s images per ZIP — about %s separate downloads. '
            .'Raising CSV_IMPORT_DEFAULT_IMAGES_PER_YEAR multiplies all three at once.',
            number_format($maxCombos),
            number_format($imagesPerQuery),
            number_format($projectedImages),
            number_format($maxProjected),
            rtrim(rtrim(number_format($sleepSeconds, 1), '0'), '.'),
            number_format($runMinutes),
            number_format($imagesPerZip),
            number_format($zipCount),
        );
    }
}
