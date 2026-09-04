<?php

namespace App\Services\Imports;

use App\Models\CarSearch;
use App\Models\CsvImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CsvQueryImporter
{
    private const REQUIRED_COLUMNS = ['Make', 'Model', 'Year'];

    public function import(UploadedFile $file, User $user): CsvImportResult
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
            $uniqueCombos = []; // key: "year|make|model" → first occurrence row data

            while (($row = fgetcsv($handle)) !== false) {
                $totalRows++;
                $make = trim($row[$makeIdx] ?? '');
                $model = trim($row[$modelIdx] ?? '');
                $year = trim($row[$yearIdx] ?? '');

                if ($make === '' || $model === '' || $year === '' || ! ctype_digit($year)) {
                    $skippedInvalid++;

                    continue;
                }

                $yearInt = (int) $year;
                if ($yearInt < $minYear || $yearInt > $maxYear) {
                    $skippedInvalid++;

                    continue;
                }

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

            return DB::transaction(function () use ($file, $user, $totalRows, $uniqueCount, $uniqueCombos, $imagesPerYear, $skippedInvalid) {
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
        } finally {
            fclose($handle);
        }
    }
}
