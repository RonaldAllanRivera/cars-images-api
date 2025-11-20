<?php

namespace App\Services\Images;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WikimediaClient
{
    public function searchCars(
        string $make,
        ?string $model,
        int $year,
        ?string $color,
        bool $transparent,
        int $limit = 10
    ): Collection {
        $query = $this->buildQuery($make, $model, $year, $color, $transparent);

        $cacheKey = $this->cacheKey($query, $limit);
        $ttl = (int) config('images.wikimedia.cache_ttl', 3600);

        return Cache::remember($cacheKey, $ttl, function () use ($query, $limit) {
            return $this->searchImages($query, $limit);
        });
    }

    protected function buildQuery(
        string $make,
        ?string $model,
        int $year,
        ?string $color,
        bool $transparent
    ): string {
        $terms = [$make];

        if ($model !== null && $model !== '') {
            $terms[] = $model;
        }

        $terms[] = (string) $year;
        $terms[] = 'car';

        if ($color !== null && $color !== '') {
            $terms[] = $color;
        }

        if ($transparent) {
            $terms[] = 'transparent background';
        }

        return implode(' ', $terms);
    }

    protected function searchImages(string $query, int $limit): Collection
    {
        $baseUrl = config('images.wikimedia.base_url');
        $timeout = (int) config('images.wikimedia.timeout', 10);
        $retryTimes = (int) config('images.wikimedia.retry_times', 3);
        $retrySleep = (int) config('images.wikimedia.retry_sleep_ms', 200);
        $userAgent = (string) config('images.wikimedia.user_agent', 'CarsImagesApi/1.0 (Laravel)');

        $response = Http::withHeaders([
            'User-Agent' => $userAgent,
        ])->timeout($timeout)
            ->retry($retryTimes, $retrySleep)
            ->get($baseUrl, [
                'action' => 'query',
                'format' => 'json',
                'formatversion' => 2,
                'origin' => '*',
                'prop' => 'imageinfo',
                'generator' => 'search',
                'gsrsearch' => $query,
                'gsrnamespace' => 6,
                'gsrlimit' => $limit,
                'iiprop' => 'url|size|mime|extmetadata',
                'iiurlwidth' => 1200,
            ])
            ->throw();

        $data = $response->json();
        $pages = Arr::get($data, 'query.pages', []);

        return collect($pages)
            ->map(function (array $page) {
                return $this->mapPageToImage($page);
            })
            ->filter(function (array $image) {
                return $image['source_url'] !== null;
            })
            ->values();
    }

    protected function mapPageToImage(array $page): array
    {
        $imageInfo = $page['imageinfo'][0] ?? null;

        if ($imageInfo === null) {
            return [
                'provider' => 'wikimedia',
                'provider_image_id' => (string) ($page['pageid'] ?? ''),
                'title' => $page['title'] ?? '',
                'description' => null,
                'source_url' => null,
                'thumbnail_url' => null,
                'width' => null,
                'height' => null,
                'license' => null,
                'attribution' => null,
                'metadata' => $page,
            ];
        }

        $ext = $imageInfo['extmetadata'] ?? [];

        $license = $this->getExtValue($ext, 'LicenseShortName');
        $artist = $this->getExtValue($ext, 'Artist');
        $credit = $this->getExtValue($ext, 'Credit');
        $usage = $this->getExtValue($ext, 'UsageTerms');

        $attributionParts = array_filter([
            $artist,
            $credit,
            $usage,
        ]);

        return [
            'provider' => 'wikimedia',
            'provider_image_id' => (string) ($page['pageid'] ?? ''),
            'title' => $page['title'] ?? '',
            'description' => $this->getExtValue($ext, 'ImageDescription'),
            'source_url' => $imageInfo['url'] ?? null,
            'thumbnail_url' => $imageInfo['thumburl'] ?? ($imageInfo['url'] ?? null),
            'width' => $imageInfo['width'] ?? null,
            'height' => $imageInfo['height'] ?? null,
            'license' => $license,
            'attribution' => $attributionParts ? implode(' | ', $attributionParts) : null,
            'metadata' => $page,
        ];
    }

    protected function getExtValue(array $ext, string $key): ?string
    {
        if (! isset($ext[$key]['value'])) {
            return null;
        }

        $value = trim((string) $ext[$key]['value']);

        return $value !== '' ? $value : null;
    }

    protected function cacheKey(string $query, int $limit): string
    {
        return 'wikimedia_cars_'.md5($query.'|'.$limit);
    }
}
