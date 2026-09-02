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
     * Hard ceiling on requests for one category.
     *
     * All searching runs synchronously inside a Filament request, so an API
     * that keeps handing back a continuation token must not be able to spin
     * the page forever.
     */
    private const MAX_CATEGORY_REQUESTS = 10;

    /**
     * How many {{category redirect}} hops to follow before giving up, so a
     * redirect cycle on Commons cannot stall a synchronous request.
     */
    private const MAX_CATEGORY_REDIRECTS = 2;

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
     * The canonical Commons category for a candidate name, or null.
     *
     * Returns a NAME rather than a boolean because the name a CSV model string
     * produces is not always the name that holds the photographs: Commons
     * points "Ford F150" at "Ford F-150" with a {{category redirect}}, and no
     * candidate generated from the CSV could ever spell the hyphenated form.
     *
     * `CommonsCategoryLocator` walks candidates with this, most specific
     * first, so null must mean "unusable" and never "request failed". A
     * network error or a block propagates out of `request()` rather than being
     * reported as a miss and cached as one.
     */
    public function resolveCategory(string $category, int $depth = 0): ?string
    {
        $response = $this->request([
            'titles' => 'Category:'.$category,
            'prop' => 'categoryinfo|revisions',
            'rvprop' => 'content',
            'rvslots' => 'main',
            // Follows a MediaWiki hard redirect; the page reported back is
            // then already the target.
            'redirects' => 1,
        ]);

        $page = $response['query']['pages'][0] ?? null;

        // `invalid` is checked as well as `missing`, and they are not
        // interchangeable: a title MediaWiki cannot represent comes back as
        // {"invalid": true, "invalidreason": "..."} with no "missing" key at
        // all. Testing `missing` alone reports such a title as an existing
        // category, and CommonsCategoryLocator then caches it forever.
        if ($page === null || ($page['missing'] ?? false) || ($page['invalid'] ?? false)) {
            return null;
        }

        $name = preg_replace('/^Category:/i', '', (string) ($page['title'] ?? $category));

        // Commons redirects categories with {{category redirect}}, a template
        // rather than a MediaWiki redirect, so the API reports the stub as an
        // ordinary page: not missing, not invalid, and holding nothing. It has
        // to be read out of the wikitext and followed. Category:Ford F150 is
        // one of these, and accepting it as-is left all 654 Ford F150 rows in
        // the CSV permanently empty while Category:Ford F-150 held 2,739 files.
        $content = (string) ($page['revisions'][0]['slots']['main']['content'] ?? '');

        if ($depth < self::MAX_CATEGORY_REDIRECTS
            && preg_match('/\{\{\s*category redirect\s*\|\s*([^}|]+?)\s*\}\}/i', $content, $matches) === 1) {
            return $this->resolveCategory(
                preg_replace('/^Category:/i', '', trim($matches[1])),
                $depth + 1,
            );
        }

        // Existing is not enough — it has to hold something, or the walk stops
        // on a dead name and caches that answer forever. Subcategories count,
        // because deepcategory: reads through them: Category:Ford F-150 itself
        // has no direct files, only 17 subcats, and the same is true of
        // Dodge Ram, GMC Sierra and Nissan Pathfinder.
        $info = $page['categoryinfo'] ?? [];

        return ((int) ($info['files'] ?? 0) + (int) ($info['subcats'] ?? 0)) > 0 ? $name : null;
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

    /**
     * The cap is part of the key: a cached entry built under a smaller
     * `category_max_files` is a different, shorter answer to the same
     * question, and would otherwise keep being served after the cap is raised.
     */
    protected function categoryCacheKey(string $category): string
    {
        $max = (int) config('images.wikimedia.category_max_files', 500);

        return 'wikimedia_category_'.md5($category.'|'.$max);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function fetchCategoryFiles(string $category): Collection
    {
        $max = (int) config('images.wikimedia.category_max_files', 500);
        $pageSize = (int) config('images.wikimedia.category_page_size', 200);

        $files = collect();
        $continue = [];
        $requests = 0;

        do {
            // MediaWiki's continuation protocol is to echo the ENTIRE
            // `continue` object back as query parameters. Cherry-picking one
            // key does not work here: a generator=search query carrying
            // prop=imageinfo continues in two dimensions, and for this request
            // shape the live API returns {"iicontinue": ..., "continue": "||"}
            // with no gsroffset at all. Reading gsroffset alone ended the loop
            // after the first page and silently truncated every category.
            $response = $this->request(array_merge([
                'prop' => 'imageinfo',
                'generator' => 'search',
                'gsrsearch' => 'deepcategory:"'.$category.'"',
                'gsrnamespace' => 6,
                'gsrlimit' => $pageSize,
                'iiprop' => 'url|size|mime|extmetadata',
                'iiurlwidth' => 1200,
            ], $continue));

            $files = $files
                ->merge(
                    collect(Arr::get($response, 'query.pages', []))
                        ->map(fn (array $page) => $this->mapPageToImage($page))
                        // Namespace 6 (File:) includes PDFs, DjVu and other
                        // documents. Keep only actual raster/vector images.
                        ->filter(fn (array $image) => $image['source_url'] !== null
                            && str_starts_with((string) ($image['mime'] ?? ''), 'image/'))
                )
                // Continuing on iicontinue re-lists pages whose imageinfo was
                // incomplete, so the same file legitimately arrives twice.
                // Deduplicating inside the loop also keeps the count guard
                // below honest.
                ->unique(fn (array $image) => $image['provider_image_id'])
                ->values();

            $continue = Arr::get($response, 'continue', []);
            $requests++;
        } while ($continue !== [] && $files->count() < $max && $requests < self::MAX_CATEGORY_REQUESTS);

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
