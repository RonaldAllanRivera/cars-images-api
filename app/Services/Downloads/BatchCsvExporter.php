<?php

namespace App\Services\Downloads;

use App\Models\CarImage;
use Illuminate\Support\Collection;

class BatchCsvExporter
{
    public function __construct(
        protected FilenameBuilder $filenames,
    ) {}

    public const HEADER = ['Year', 'Make', 'Model', 'Transmission', 'Filename', 'SourceUrl', 'SearchId', 'ImageId'];

    /**
     * Return an array of CSV rows: [header, row1, row2, ...].
     * Filenames are deduped across the collection so they match the ZIP output.
     * Deduplication is by base name (Year Make Model), so images with the same
     * make/model/year but different extensions still get unique suffixed names.
     */
    public function buildRows(Collection $images): array
    {
        $rows = [self::HEADER];
        $usedNames = [];
        // Track base-name counters to handle cross-extension dedup
        $baseCounters = [];

        foreach ($images as $image) {
            /** @var CarImage $image */
            $search = $image->search;
            $extension = $this->extensionFromUrl($image->source_url);

            $filename = $this->buildUniqueByBase(
                (int) $image->year,
                (string) $image->make,
                (string) $image->model,
                $extension,
                $usedNames,
                $baseCounters,
            );

            $rows[] = [
                (string) $image->year,
                (string) $image->make,
                (string) $image->model,
                (string) ($search?->transmission ?? ''),
                $filename,
                (string) $image->source_url,
                (string) $image->car_search_id,
                (string) $image->id,
            ];
        }

        return $rows;
    }

    /**
     * Stream CSV rows to a PHP stream resource (for StreamedResponse).
     */
    public function streamTo($handle, Collection $images): void
    {
        foreach ($this->buildRows($images) as $row) {
            fputcsv($handle, $row);
        }
    }

    /**
     * Generate a filename that is unique within $usedNames, tracking counters
     * by base name so that different extensions on the same Year/Make/Model still
     * share the same suffix counter sequence.
     *
     * e.g. "1997 Toyota RAV4.jpg" then "1997 Toyota RAV4 2.png" (not "1997 Toyota RAV4.png").
     *
     * @param  array<string,true>  $usedNames  full filenames already issued (mutated)
     * @param  array<string,int>  $baseCounters  next counter per base string (mutated)
     */
    private function buildUniqueByBase(
        int $year,
        string $make,
        string $model,
        string $extension,
        array &$usedNames,
        array &$baseCounters,
    ): string {
        $base = $this->filenames->build($year, $make, $model, $extension);
        // Strip the extension to get the bare base for counter tracking.
        $extPos = strrpos($base, '.');
        $baseName = substr($base, 0, $extPos);
        $ext = substr($base, $extPos); // includes the dot

        if (! isset($baseCounters[$baseName])) {
            // First time we've seen this base — use no suffix.
            $baseCounters[$baseName] = 2;
            $candidate = $baseName.$ext;
        } else {
            // Already used — apply and advance the counter.
            $counter = $baseCounters[$baseName];
            $candidate = "{$baseName} {$counter}{$ext}";
            $baseCounters[$baseName] = $counter + 1;
        }

        $usedNames[$candidate] = true;

        return $candidate;
    }

    private function extensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        return pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg';
    }
}
