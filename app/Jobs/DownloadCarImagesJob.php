<?php

namespace App\Jobs;

use App\Models\CarImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DownloadCarImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param array<int> $imageIds
     */
    public function __construct(public array $imageIds)
    {
    }

    public function handle(): void
    {
        foreach ($this->imageIds as $id) {
            $image = CarImage::find($id);

            if (! $image || ! $image->source_url) {
                continue;
            }

            try {
                $image->update([
                    'download_status' => 'downloading',
                ]);

                $response = Http::timeout(30)->get($image->source_url);

                if (! $response->successful()) {
                    $image->update([
                        'download_status' => 'failed',
                    ]);

                    continue;
                }

                $path = $this->buildPath($image);

                Storage::disk('cars')->put($path, $response->body());

                $image->update([
                    'download_status' => 'downloaded',
                    'download_path' => $path,
                ]);
            } catch (Throwable $e) {
                $image->update([
                    'download_status' => 'failed',
                ]);

                throw $e;
            }
        }
    }

    protected function buildPath(CarImage $image): string
    {
        $make = Str::slug($image->make ?? 'unknown');
        $model = Str::slug($image->model ?? 'unknown');
        $year = $image->year ?? 'unknown';

        $extension = pathinfo(parse_url($image->source_url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';

        $filename = ($image->provider_image_id ?: Str::uuid()->toString()).'.'.$extension;

        return implode('/', [
            $make,
            $model,
            (string) $year,
            $filename,
        ]);
    }
}
