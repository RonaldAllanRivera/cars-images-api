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
    public function __construct(
        protected ModelSearchTermNormalizer $modelNormalizer,
    ) {}

    /**
     * One Commons search. Pass `$year = null` for the year-relaxed variant.
     *
     * This method deliberately does NOT retry without the year on an empty
     * result. It used to, and that put the decision at the wrong layer: the
     * client can only see whether the API answered, not whether the answer
     * was usable. A query returning ten Honda Accords for an Acura looks
     * non-empty here but is rejected wholesale downstream, so the year that
     * needed the fallback most never got it. `CarImageSearchService` owns the
     * retry now, because only it knows what survives filtering.
     */
    public function searchCars(
        string $make,
        ?string $model,
        ?int $year,
        ?string $color,
        ?string $transmission,
        bool $transparent,
        int $limit = 10
    ): Collection {
        return $this->cachedSearch(
            $this->buildQuery($make, $model, $year, $color, $transmission, $transparent),
            $limit,
        );
    }

    protected function cachedSearch(string $query, int $limit): Collection
    {
        $ttl = (int) config('images.wikimedia.cache_ttl', 3600);

        return Cache::remember(
            $this->cacheKey($query, $limit),
            $ttl,
            fn () => $this->searchImages($query, $limit),
        );
    }

    protected function buildQuery(
        string $make,
        ?string $model,
        ?int $year,
        ?string $color,
        ?string $transmission,
        bool $transparent
    ): string {
        $terms = [$make];

        if ($model !== null && $model !== '') {
            $normalizedModel = $this->modelNormalizer->normalize($model);
            $terms[] = $normalizedModel !== '' ? $normalizedModel : $model;
        }

        if ($year !== null) {
            $terms[] = (string) $year;
        }

        $terms[] = 'car';

        if ($color !== null && $color !== '') {
            $terms[] = $color;
        }

        if ($transmission !== null && $transmission !== '') {
            $terms[] = $transmission;
        }

        if ($transparent) {
            $terms[] = 'transparent background';
        }

        return implode(' ', $terms);
    }

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

    protected function searchImages(string $query, int $limit): Collection
    {
        $data = $this->request([
            'prop' => 'imageinfo',
            'generator' => 'search',
            'gsrsearch' => $query,
            'gsrnamespace' => 6,
            'gsrlimit' => $limit,
            'iiprop' => 'url|size|mime|extmetadata',
            'iiurlwidth' => 1200,
        ]);

        $pages = Arr::get($data, 'query.pages', []);

        return collect($pages)
            ->map(function (array $page) {
                return $this->mapPageToImage($page);
            })
            ->filter(function (array $image) {
                if ($image['source_url'] === null) {
                    return false;
                }

                // Namespace 6 (File:) includes PDFs, DjVu and other documents.
                // Keep only actual raster/vector images.
                if (! str_starts_with((string) ($image['mime'] ?? ''), 'image/')) {
                    return false;
                }

                return $this->isCarImage($image);
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

    protected function cacheKey(string $query, int $limit): string
    {
        return 'wikimedia_cars_'.md5($query.'|'.$limit);
    }

    public function clearSearchCache(
        string $make,
        ?string $model,
        int $year,
        ?string $color,
        ?string $transmission,
        bool $transparent,
        int $limit = 10
    ): void {
        // searchCars() may have cached either the year-specific query or the
        // year-relaxed fallback query — forget both.
        foreach ([$year, null] as $queryYear) {
            $query = $this->buildQuery($make, $model, $queryYear, $color, $transmission, $transparent);
            Cache::forget($this->cacheKey($query, $limit));
        }
    }

    protected function isCarImage(array $image): bool
    {
        $title = strtolower((string) ($image['title'] ?? ''));
        $description = strtolower(strip_tags((string) ($image['description'] ?? '')));

        $metadata = $image['metadata'] ?? [];
        $extmetadata = $metadata['imageinfo'][0]['extmetadata'] ?? [];
        $categories = strtolower((string) ($extmetadata['Categories']['value'] ?? ''));

        // Quick negative filter: exclude obvious non-car subjects like flowers and plants.
        foreach ([
            'flower',
            'flowers',
            'blossom',
            'plant',
            'tree',
            'garden',
            'psychology',
            'neuroscience',
            'cognitive',
            'royal society',
            'open science',
            'journal',
            'article',
        ] as $negative) {
            if (str_contains($title, $negative) || str_contains($description, $negative) || str_contains($categories, $negative)) {
                return false;
            }
        }

        // Positive hints that this is a car / vehicle.
        foreach (['car', 'cars', 'automobile', 'automobiles', 'vehicle', 'vehicles', 'sedan', 'hatchback', 'suv', 'pickup', 'coupe', 'wagon', 'convertible'] as $positive) {
            if (str_contains($title, $positive) || str_contains($description, $positive) || str_contains($categories, $positive)) {
                return true;
            }
        }

        // If we can't tell, keep the image to avoid losing valid results.
        return true;
    }
}
