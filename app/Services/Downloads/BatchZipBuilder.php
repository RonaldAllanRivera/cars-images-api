<?php

namespace App\Services\Downloads;

use App\Models\CarImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class BatchZipBuilder
{
    public function __construct(
        protected FilenameBuilder $filenames,
    ) {
    }

    /**
     * Build a ZIP at $targetPath containing each image renamed.
     * Image binaries are fetched from CarImage::source_url via HTTP.
     */
    public function buildToFile(Collection $images, string $targetPath): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException("Cannot open ZIP at {$targetPath}: code {$opened}");
        }

        $usedNames = [];
        $baseCounters = [];

        foreach ($images as $image) {
            /** @var CarImage $image */
            $extension = $this->extensionFromUrl($image->source_url);

            $filename = $this->buildUniqueByBase(
                (int) $image->year,
                (string) $image->make,
                (string) $image->model,
                $extension,
                $usedNames,
                $baseCounters,
            );

            $response = Http::timeout(30)->get($image->source_url);
            if (! $response->successful()) {
                // Skip individual fetch failures rather than aborting the whole ZIP.
                continue;
            }

            $zip->addFromString($filename, $response->body());
        }

        $zip->close();
    }

    /**
     * Generate a filename that is unique within $usedNames, tracking counters
     * by base name so that different extensions on the same Year/Make/Model still
     * share the same suffix counter sequence.
     *
     * e.g. "1997 Toyota RAV4.jpg" then "1997 Toyota RAV4 2.png" (not "1997 Toyota RAV4.png").
     *
     * @param  array<string,true>  $usedNames    full filenames already issued (mutated)
     * @param  array<string,int>   $baseCounters  next counter per base string (mutated)
     */
    private function buildUniqueByBase(
        int $year,
        string $make,
        string $model,
        string $extension,
        array &$usedNames,
        array &$baseCounters,
    ): string {
        $full = $this->filenames->build($year, $make, $model, $extension);
        $extPos = strrpos($full, '.');
        $baseName = substr($full, 0, $extPos);
        $ext = substr($full, $extPos); // includes the dot

        if (! isset($baseCounters[$baseName])) {
            $baseCounters[$baseName] = 2;
            $candidate = $baseName . $ext;
        } else {
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
