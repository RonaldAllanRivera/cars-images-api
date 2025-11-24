<?php

namespace App\Services\Images;

use App\Models\CarImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CarImageZipService
{
    /**
     * @param Collection<int, CarImage> $images
     */
    public function downloadZip(Collection $images): BinaryFileResponse
    {
        if ($images->isEmpty()) {
            abort(400, 'No images selected.');
        }

        $zipFileName = 'car-images-'.now()->format('Ymd-His').'.zip';
        $zipPath = storage_path('app/'.$zipFileName);

        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Unable to create ZIP archive.');
        }

        $userAgent = (string) config('images.wikimedia.user_agent', 'CarsImagesApi/1.0 (Laravel)');

        foreach ($images as $image) {
            if (! $image instanceof CarImage) {
                continue;
            }

            $sourceUrl = $image->source_url;

            if (! $sourceUrl) {
                continue;
            }

            $content = null;
            $contentType = 'application/octet-stream';
            $nameSource = $sourceUrl;

            if ($image->download_path && Storage::disk('cars')->exists($image->download_path)) {
                $content = Storage::disk('cars')->get($image->download_path);

                $extension = pathinfo($image->download_path, PATHINFO_EXTENSION) ?: 'jpg';
                $contentType = $this->guessContentTypeFromExtension($extension);
                $nameSource = $image->download_path;
            } else {
                $response = Http::withHeaders([
                    'User-Agent' => $userAgent,
                ])->timeout(30)->get($sourceUrl);

                if (! $response->successful()) {
                    continue;
                }

                $content = $response->body();
                $contentType = $response->header('Content-Type', 'application/octet-stream');
                $nameSource = $sourceUrl;

                $image->forceFill([
                    'download_status' => 'downloaded',
                ])->save();
            }

            if ($content === null || $content === '') {
                continue;
            }

            $filename = $this->buildFilename($image, $nameSource, $contentType);

            $zip->addFromString($filename, $content);
        }

        $zip->close();

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    protected function buildFilename(CarImage $image, string $source, string $contentType): string
    {
        $extension = pathinfo(parse_url($source, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);

        if ($extension === '') {
            $extension = match ($contentType) {
                'image/png' => 'png',
                'image/gif' => 'gif',
                default => 'jpg',
            };
        }

        $parts = array_filter([
            $image->make,
            $image->model,
            $image->year,
            $image->id,
        ]);

        $base = $parts ? implode('-', $parts) : 'car-image-'.$image->id;
        $base = Str::slug($base) ?: 'car-image-'.$image->id;

        return $base.'.'.$extension;
    }

    protected function guessContentTypeFromExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
