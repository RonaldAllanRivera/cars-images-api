<?php

namespace App\Services\Imports;

use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\ErrorEvent;
use App\Models\User;
use App\Services\Logging\ErrorEventLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CsvQueryImporter
{
    private const REQUIRED_COLUMNS = ['Make', 'Model', 'Year'];

    public function __construct(
        protected ErrorEventLogger $errorLog,
    ) {}

    /**
     * Import a CSV, recording anything it had to reject.
     *
     * Rejections are logged here rather than at the Filament page, so that
     * every caller of the importer is covered by one edit — the same reason
     * RunSearchQueryAction logs rather than the bulk-run loop above it.
     */
    public function import(UploadedFile $file, User $user): CsvImportResult
    {
        try {
            return $this->runImport($file, $user);
        } catch (CsvImportException $e) {
            // A rejected upload never becomes a CsvImport row, so there is no
            // import to link to — the filename is the only handle the operator
            // has on which upload this was.
            $this->errorLog->record(
                ErrorEvent::CONTEXT_CSV_UPLOAD,
                $e,
                details: ['filename' => $file->getClientOriginalName()],
            );

            throw $e;
        }
    }

    private function runImport(UploadedFile $file, User $user): CsvImportResult
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new CsvImportException('Unable to open uploaded CSV.');
        }

        try {
            $headers = fgetcsv($handle);
            if ($headers === false) {
                throw new CsvImportException('CSV is empty.');
            }

            $headers = array_map('trim', $headers);
            $missing = array_diff(self::REQUIRED_COLUMNS, $headers);
            if (! empty($missing)) {
                throw new CsvImportException(
                    'Missing required columns: '.implode(', ', $missing).'. Required: Make, Model, Year.'
                );
            }

            $columnIndex = array_flip($headers);
            $makeIdx = $columnIndex['Make'];
            $modelIdx = $columnIndex['Model'];
            $yearIdx = $columnIndex['Year'];
            $transmissionIdx = $columnIndex['Transmission'] ?? null;

            $maxYear = (int) date('Y') + 1;
            $minYear = 1900;
            $totalRows = 0;
            $skippedInvalid = 0;
            $rejected = [];
            $uniqueCombos = []; // key: "year|make|model" → first occurrence row data

            while (($row = fgetcsv($handle)) !== false) {
                $totalRows++;
                $make = trim($row[$makeIdx] ?? '');
                $model = trim($row[$modelIdx] ?? '');
                $year = trim($row[$yearIdx] ?? '');

                $reason = $this->rejectionReason($make, $model, $year, $minYear, $maxYear);
                if ($reason !== null) {
                    $rejected[] = [
                        'row_number' => $totalRows,
                        'raw_row' => implode(',', array_map(strval(...), $row)),
                        'reason' => $reason,
                    ];
                    $skippedInvalid++;

                    continue;
                }

                $yearInt = (int) $year;

                $key = $yearInt.'|'.$make.'|'.$model;
                if (! isset($uniqueCombos[$key])) {
                    $uniqueCombos[$key] = [
                        'year' => $yearInt,
                        'make' => $make,
                        'model' => $model,
                        'transmission' => $transmissionIdx !== null ? (trim($row[$transmissionIdx] ?? '') ?: null) : null,
                    ];
                }
            }

            $uniqueCount = count($uniqueCombos);
            $maxCombos = (int) config('cars-images.csv_import_max_combos');
            if ($uniqueCount > $maxCombos) {
                throw new CsvImportException(
                    "CSV produces {$uniqueCount} unique queries, which exceeds the limit of {$maxCombos}. Split the CSV externally and retry."
                );
            }

            $imagesPerYear = (int) config('cars-images.csv_import_default_images_per_year');

            // Second ceiling: what this CSV commits us to downloading from the
            // Wikimedia API. The combo cap above bounds queries, not images, so
            // raising images-per-year could otherwise smuggle a run many times
            // longer than intended past it. Rejecting here costs a second;
            // discovering it mid-run costs the whole run.
            $projectedImages = $uniqueCount * $imagesPerYear;
            $maxProjectedImages = (int) config('cars-images.csv_import_max_projected_images');
            if ($projectedImages > $maxProjectedImages) {
                throw new CsvImportException(
                    "CSV produces {$uniqueCount} unique queries x {$imagesPerYear} images = {$projectedImages} image downloads, "
                    ."which exceeds the API download limit of {$maxProjectedImages}. "
                    .'Split the CSV, or lower CSV_IMPORT_DEFAULT_IMAGES_PER_YEAR, and retry.'
                );
            }

            $result = DB::transaction(function () use ($file, $user, $totalRows, $uniqueCount, $uniqueCombos, $imagesPerYear, $skippedInvalid) {
                $csvImport = CsvImport::create([
                    'original_filename' => $file->getClientOriginalName(),
                    'total_rows' => $totalRows,
                    'unique_combos' => $uniqueCount,
                    'duplicates_skipped' => $totalRows - $uniqueCount - $skippedInvalid,
                    'imported_by' => $user->id,
                ]);

                $now = now();
                $rows = [];
                foreach ($uniqueCombos as $combo) {
                    $rows[] = [
                        'make' => $combo['make'],
                        'model' => $combo['model'],
                        'from_year' => $combo['year'],
                        'to_year' => $combo['year'],
                        'color' => null,
                        'transmission' => $combo['transmission'],
                        'transparent_background' => false,
                        'images_per_year' => $imagesPerYear,
                        'status' => 'pending',
                        'requested_by' => $user->id,
                        'csv_import_id' => $csvImport->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                // Bulk insert in chunks to keep memory steady
                foreach (array_chunk($rows, 500) as $chunk) {
                    CarSearch::insert($chunk);
                }

                return new CsvImportResult($csvImport, $skippedInvalid);
            });

            // The import row does not exist until that transaction commits, so
            // rejections are buffered during the parse and written here, where
            // they can carry the csv_import_id that makes them findable.
            foreach ($rejected as $row) {
                $this->errorLog->record(
                    ErrorEvent::CONTEXT_CSV_ROW,
                    $row['reason'],
                    links: ['csv_import_id' => $result->csvImport->id],
                    details: ['row_number' => $row['row_number'], 'raw_row' => $row['raw_row']],
                    severity: 'warning',
                );
            }

            return $result;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Why a row cannot become a query, or null if it can.
     *
     * Returns prose rather than a code: this string is what the operator reads
     * in the log to work out how to fix their spreadsheet.
     */
    private function rejectionReason(string $make, string $model, string $year, int $minYear, int $maxYear): ?string
    {
        $missing = [];

        if ($make === '') {
            $missing[] = 'Make';
        }
        if ($model === '') {
            $missing[] = 'Model';
        }
        if ($year === '') {
            $missing[] = 'Year';
        }

        if ($missing !== []) {
            return implode(', ', $missing).' missing.';
        }

        if (! ctype_digit($year)) {
            return "Year '{$year}' is not a number.";
        }

        $yearInt = (int) $year;

        if ($yearInt < $minYear || $yearInt > $maxYear) {
            return "Year {$yearInt} is outside the accepted range {$minYear}-{$maxYear}.";
        }

        return null;
    }
}
