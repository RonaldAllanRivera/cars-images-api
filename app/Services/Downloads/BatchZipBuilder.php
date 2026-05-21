<?php

namespace App\Services\Downloads;

use App\Models\CarImage;
use App\Services\Images\WikimediaThumbnailUrlBuilder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class BatchZipBuilder
{
    public function __construct(
        protected FilenameBuilder $filenames,
        protected WikimediaThumbnailUrlBuilder $thumbnailUrls,
    ) {
    }

    /**
     * Build a ZIP at $targetPath containing each image renamed.
     * Image binaries are fetched from CarImage::source_url via HTTP.
     *
     * Returns the number of images actually written into the archive.
     * When that is 0, ZipArchive writes no file at all — callers must
     * check the return value before trying to serve $targetPath.
     */
    public function buildToFile(Collection $images, string $targetPath): int
    {
        $zip = new ZipArchive();
        $opened = $zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException("Cannot open ZIP at {$targetPath}: code {$opened}");
        }

        // Wikimedia's upload host (upload.wikimedia.org) returns HTTP 403 to
        // requests without a descriptive User-Agent, so binary fetches must
        // send the same UA the rest of the app uses.
        $userAgent = (string) config('images.wikimedia.user_agent', 'CarsImagesApi/1.0 (Laravel)');

        $maxWidth = (int) config('cars-images.download_max_width', 1600);

        $usedNames = [];
        $baseCounters = [];
        $added = 0;

        foreach ($images as $image) {
            /** @var CarImage $image */
            $response = $this->fetchImageBinary((string) $image->source_url, $userAgent, $maxWidth);

            if ($response === null) {
                // Skip individual fetch failures rather than aborting the whole ZIP.
                continue;
            }

            // Only consume a filename/counter once the fetch has succeeded, so
            // skipped images never leave gaps in the duplicate-suffix sequence.
            $filename = $this->buildUniqueByBase(
                (int) $image->year,
                (string) $image->make,
                (string) $image->model,
                $this->extensionFromUrl($image->source_url),
                $usedNames,
                $baseCounters,
            );

            $zip->addFromString($filename, $response->body());
            $added++;
        }

        $zip->close();

        return $added;
    }

    /**
     * Fetch an image binary, preferring a web-sized Wikimedia thumbnail.
     *
     * When the thumbnail fetch fails, falls back to the original URL so an
     * image is never silently dropped. Returns null only when both fail.
     */
    private function fetchImageBinary(string $sourceUrl, string $userAgent, int $maxWidth): ?Response
    {
        $thumbnailUrl = $this->thumbnailUrls->forWidth($sourceUrl, $maxWidth);

        $response = Http::withHeaders(['User-Agent' => $userAgent])
            ->timeout(30)
            ->get($thumbnailUrl);

        if ($response->successful()) {
            return $response;
        }

        // The thumbnail URL was a transformed one and it failed — retry the
        // untouched original before giving up on this image.
        if ($thumbnailUrl !== $sourceUrl) {
            $original = Http::withHeaders(['User-Agent' => $userAgent])
                ->timeout(30)
                ->get($sourceUrl);

            if ($original->successful()) {
                return $original;
            }
        }

        return null;
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
