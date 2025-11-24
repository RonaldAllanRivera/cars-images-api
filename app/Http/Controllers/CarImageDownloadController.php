<?php

namespace App\Http\Controllers;

use App\Models\CarImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CarImageDownloadController
{
    public function __invoke(Request $request, CarImage $carImage)
    {
        $sourceUrl = $carImage->source_url;

        if (! $sourceUrl) {
            abort(404, 'Image source URL is missing.');
        }

        $userAgent = (string) config('images.wikimedia.user_agent', 'CarsImagesApi/1.0 (Laravel)');

        $response = Http::withHeaders([
            'User-Agent' => $userAgent,
        ])->timeout(30)->get($sourceUrl);

        if ($response->failed()) {
            Log::warning('Failed to download car image from source URL.', [
                'car_image_id' => $carImage->id,
                'source_url' => $sourceUrl,
                'status' => $response->status(),
            ]);

            abort(502, 'Unable to download image from provider.');
        }

        $content = $response->body();
        $contentType = $response->header('Content-Type', 'application/octet-stream');

        $filename = $this->buildFilename($carImage, $sourceUrl, $contentType);

        return response()->streamDownload(function () use ($content) {
            echo $content;
        }, $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    protected function buildFilename(CarImage $image, string $url, string $contentType): string
    {
        $ext = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);

        if ($ext === '') {
            $ext = match ($contentType) {
                'image/png' => 'png',
                'image/gif' => 'gif',
                default => 'jpg',
            };
        }

        $parts = array_filter([
            $image->make,
            $image->model,
            $image->year,
        ]);

        $base = $parts ? implode('-', $parts) : 'car-image';
        $base = Str::slug($base) ?: 'car-image';

        return $base.'.'.$ext;
    }
}
