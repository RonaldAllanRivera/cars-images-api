<?php

namespace App\Services\Images;

use App\Exceptions\WikimediaBlockedException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WikimediaClient
{
    /**
     * One Commons API call, with the shared retry policy, block detection and
     * etiquette headers.
     *
     * Every request to Commons goes through here, so a 429 is handled
     * identically no matter which method triggered it — including the category
     * probes, where a block must surface as an exception rather than be
     * mistaken for "this category does not exist" and cached as a miss.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function request(array $params): array
    {
        $baseUrl = config('images.wikimedia.base_url');
        $timeout = (int) config('images.wikimedia.timeout', 10);
        $retryTimes = (int) config('images.wikimedia.retry_times', 3);
        $retrySleep = (int) config('images.wikimedia.retry_sleep_ms', 200);
        $userAgent = (string) config('images.wikimedia.user_agent', 'CarsImagesApi/1.0 (Laravel)');

        $blockStatuses = [429, 403, 503];

        $response = Http::withHeaders([
            'User-Agent' => $userAgent,
        ])->timeout($timeout)
            ->retry($retryTimes, function (int $attempt) use ($retrySleep) {
                // Exponential backoff: base * 2^(attempt-1)
                return $retrySleep * (2 ** ($attempt - 1));
            }, function ($exception, $request) use ($blockStatuses) {
                if ($exception instanceof RequestException) {
                    return ! in_array($exception->response->status(), $blockStatuses, true);
                }

                return true;
            }, false)
            ->get($baseUrl, array_merge([
                'action' => 'query',
                'format' => 'json',
                'formatversion' => 2,
                'origin' => '*',
                'maxlag' => config('images.wikimedia.maxlag', 5),
            ], $params));

        if (in_array($response->status(), $blockStatuses, true)) {
            throw new WikimediaBlockedException(
                statusCode: $response->status(),
                retryAfterSeconds: $response->header('Retry-After') !== ''
                    ? (int) $response->header('Retry-After')
                    : null,
                responseExcerpt: mb_substr($response->body(), 0, 1024),
            );
        }

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Whether a Commons category page exists.
     *
     * `CommonsCategoryLocator` probes candidates with this, most specific
     * first, so a false answer must mean "absent" and never "request failed".
     * A network error or a block propagates out of `request()` rather than
     * being reported as a miss and cached as one.
     */
    public function categoryExists(string $category): bool
    {
        $response = $this->request([
            'titles' => 'Category:'.$category,
        ]);

        $page = $response['query']['pages'][0] ?? null;

        return $page !== null && ! ($page['missing'] ?? false);
    }

    /**
     * Every image file in a category and its subcategories.
     *
     * Returns the WHOLE category, deliberately: the caller filters by model
     * year afterwards, and `images_per_year` is applied to what survives that
     * filter. Truncating here would hand the year filter an arbitrary slice —
     * Category:Cadillac STS holds 56 files of which 6 name 2005, so a ten-file
     * fetch finds none of them.
     */
    public function filesInCategory(string $category): Collection
    {
        $ttl = (int) config('images.wikimedia.cache_ttl', 3600);

        return Cache::remember(
            $this->categoryCacheKey($category),
            $ttl,
            fn () => $this->fetchCategoryFiles($category),
        );
    }

    public function forgetCategory(string $category): void
    {
        Cache::forget($this->categoryCacheKey($category));
    }

    protected function categoryCacheKey(string $category): string
    {
        return 'wikimedia_category_'.md5($category);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function fetchCategoryFiles(string $category): Collection
    {
        $max = (int) config('images.wikimedia.category_max_files', 500);
        $pageSize = (int) config('images.wikimedia.category_page_size', 200);

        $files = collect();
        $offset = 0;

        do {
            $response = $this->request([
                'prop' => 'imageinfo',
                'generator' => 'search',
                'gsrsearch' => 'deepcategory:"'.$category.'"',
                'gsrnamespace' => 6,
                'gsrlimit' => max(1, min($pageSize, $max - $files->count())),
                'gsroffset' => $offset,
                'iiprop' => 'url|size|mime|extmetadata',
                'iiurlwidth' => 1200,
            ]);

            $files = $files->merge(
                collect(Arr::get($response, 'query.pages', []))
                    ->map(fn (array $page) => $this->mapPageToImage($page))
                    // Namespace 6 (File:) includes PDFs, DjVu and other
                    // documents. Keep only actual raster/vector images.
                    ->filter(fn (array $image) => $image['source_url'] !== null
                        && str_starts_with((string) ($image['mime'] ?? ''), 'image/'))
            );

            $offset = Arr::get($response, 'continue.gsroffset');
        } while ($offset !== null && $files->count() < $max);

        return $files->take($max)->values();
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
                'mime' => null,
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
            'mime' => $imageInfo['mime'] ?? null,
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
}
