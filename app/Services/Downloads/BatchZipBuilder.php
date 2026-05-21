<?php

namespace App\Services\Downloads;

use App\Models\CarImage;
use Illuminate\Http\Client\Response;
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
     * Image binaries are fetched web-sized (see fetchImageBinary()).
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

        $usedNames = [];
        $baseCounters = [];
        $added = 0;

        foreach ($images as $image) {
            /** @var CarImage $image */
            $response = $this->fetchImageBinary($image, $userAgent);

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
     * Fetch an image binary, preferring the web-sized thumbnail.
     *
     * Each CarImage stores `thumbnail_url` — the ~1280px thumbnail Wikimedia
     * generated when the image was found (via the API's iiurlwidth). It is
     * ~85-90% smaller than the full-resolution original.
     *
     * Note: Wikimedia will NOT generate an arbitrary-width thumbnail from a
     * constructed URL — direct requests for non-pre-generated widths return
     * HTTP 400. A future configurable download width would require batched
     * MediaWiki API calls (iiurlwidth) at download time.
     *
     * Falls back to the full-resolution `source_url` when the thumbnail is
     * absent or its fetch fails, so an image is never silently dropped.
     * Returns null only when every attempt fails.
     */
    private function fetchImageBinary(CarImage $image, string $userAgent): ?Response
    {
        $sourceUrl = (string) $image->source_url;
        $thumbnailUrl = (string) ($image->thumbnail_url ?: $sourceUrl);

        $response = Http::withHeaders(['User-Agent' => $userAgent])
            ->timeout(30)
            ->get($thumbnailUrl);

        if ($response->successful()) {
            return $response;
        }

        // Thumbnail fetch failed — retry the original before giving up.
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
