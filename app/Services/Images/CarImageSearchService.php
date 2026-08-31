<?php

namespace App\Services\Images;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Throwable;

class CarImageSearchService
{
    public function __construct(
        protected WikimediaClient $wikimedia,
        protected DatabaseManager $db,
        protected CommonsCategoryLocator $locator,
        protected ModelYearMatcher $yearMatcher = new ModelYearMatcher,
        protected MakeRelevanceChecker $makeChecker = new MakeRelevanceChecker,
    ) {}

    public function createSearch(
        User $user,
        string $make,
        ?string $model,
        int $fromYear,
        int $toYear,
        ?string $color,
        ?string $transmission,
        bool $transparent,
        int $imagesPerYear = 10
    ): CarSearch {
        if ($fromYear > $toYear) {
            [$fromYear, $toYear] = [$toYear, $fromYear];
        }

        return CarSearch::create([
            'make' => $make,
            'model' => $model,
            'from_year' => $fromYear,
            'to_year' => $toYear,
            'color' => $color,
            'transmission' => $transmission,
            'transparent_background' => $transparent,
            'images_per_year' => $imagesPerYear,
            'status' => 'pending',
            'requested_by' => $user->id,
        ]);
    }

    public function findExistingCompletedSearch(
        string $make,
        ?string $model,
        int $fromYear,
        int $toYear,
        ?string $color,
        ?string $transmission,
        bool $transparent,
        int $imagesPerYear = 10
    ): ?CarSearch {
        return CarSearch::query()
            ->where('make', $make)
            ->where('model', $model)
            ->where('from_year', $fromYear)
            ->where('to_year', $toYear)
            ->where('color', $color)
            ->where('transmission', $transmission)
            ->where('transparent_background', $transparent)
            ->where('images_per_year', $imagesPerYear)
            ->where('status', 'completed')
            ->latest('created_at')
            ->first();
    }

    public function runSearch(CarSearch $search): Collection
    {
        // Resolve before opening the transaction. The lookup row is cache
        // state, not search state: if the search fails later in this run the
        // resolution must survive rather than roll back with it, or the next
        // attempt pays for the same probes again.
        $this->locator->locate($search->make, $search->model);

        return $this->db->transaction(fn () => $this->executeSearch($search));
    }

    /**
     * Delete the search's images and fetch them again.
     *
     * The delete and the refetch share ONE transaction. They used to be
     * separate: the delete committed immediately, so when Wikimedia answered
     * 429 the refetch rolled back and restored nothing — every reviewed image
     * was gone for good, and the row still read `completed` because the
     * `status = running` update rolled back with it.
     *
     * On failure the search is forced to `failed` (outside the rolled-back
     * transaction) and the exception is re-thrown, so the caller can report
     * it rather than showing a search that quietly lost its contents.
     */
    public function refreshSearch(CarSearch $search): Collection
    {
        try {
            // Resolution and cache eviction sit outside the transaction below
            // but inside this try. Outside the transaction because a lookup row
            // is cache state that must survive a failed refresh; inside the try
            // because resolving talks to Wikimedia, so a 429 here has to mark
            // the search failed like any other block rather than escape and
            // leave it reading `completed`.
            //
            // One category serves every year of the range, so there is a single
            // entry to forget rather than one per year.
            $category = $this->locator->locate($search->make, $search->model);

            if ($category !== null) {
                $this->wikimedia->forgetCategory($category);
            }

            return $this->db->transaction(function () use ($search) {
                CarImage::where('car_search_id', $search->id)->delete();

                return $this->executeSearch($search->fresh());
            });
        } catch (Throwable $e) {
            $this->markFailed($search);

            throw $e;
        }
    }

    /**
     * The search itself, assuming a transaction is already open.
     */
    private function executeSearch(CarSearch $search): Collection
    {
        $results = collect();

        $search->update([
            'status' => 'running',
            // Recorded so an empty result can be told apart: null means no
            // category could be resolved from the model string, while a set
            // value with no images means the category holds no photograph
            // naming that year.
            'commons_category' => $this->locator->locate($search->make, $search->model),
        ]);

        foreach (range($search->from_year, $search->to_year) as $year) {
            $results = $results->merge(
                $this->fetchAndStoreForYear($search, $year, $search->images_per_year)
            );
        }

        $search->update(['status' => 'completed']);

        return $results;
    }

    /**
     * Force a terminal `failed` status.
     *
     * `forceFill()->save()` rather than `update()` because the surrounding
     * transaction has already rolled back the in-flight status change.
     */
    private function markFailed(CarSearch $search): void
    {
        $search->forceFill(['status' => 'failed'])->save();
    }

    public function fetchAndStoreForYear(CarSearch $search, int $year, int $limit): Collection
    {
        $category = $this->locator->locate($search->make, $search->model);

        if ($category === null) {
            return collect();
        }

        // The whole category, then the year filter, then the limit — in that
        // order. Applying the limit to the fetch would hand the year filter an
        // arbitrary slice: Category:Cadillac STS holds 56 files of which 6
        // name 2005, so a ten-file fetch finds none of them.
        return $this->wikimedia->filesInCategory($category)
            ->filter(fn (array $image) => $this->yearMatcher->modelYear(
                (string) ($image['title'] ?? ''),
                $search->make,
            ) === $year)
            ->take($limit)
            ->values()
            ->map(function (array $image) use ($search, $year) {
                $categories = $this->categoriesOf($image);

                // The match key includes the owning search AND the year.
                // Keyed on (provider, provider_image_id) alone it was global:
                // a file returned by two searches was MOVED to whichever ran
                // last — the earlier search silently lost it and had its year
                // relabelled. One Wikimedia file can legitimately answer many
                // searches, so ownership belongs in the key, not the payload.
                return CarImage::updateOrCreate(
                    [
                        'car_search_id' => $search->id,
                        'year' => $year,
                        'provider' => $image['provider'],
                        'provider_image_id' => $image['provider_image_id'],
                    ],
                    [
                        'make' => $search->make,
                        'model' => $search->model,
                        'color' => $search->color,
                        'transparent_background' => $search->transparent_background,
                        'title' => $image['title'],
                        'description' => $image['description'],
                        'source_url' => $image['source_url'],
                        'thumbnail_url' => $image['thumbnail_url'],
                        'width' => $image['width'],
                        'height' => $image['height'],
                        'license' => $image['license'],
                        'attribution' => $image['attribution'],
                        'make_confirmed' => $this->makeChecker->isConfirmed(
                            $search->make,
                            $image['title'],
                            $image['description'],
                            $categories,
                        ),
                        // Exact-year by construction: the file's own title
                        // names this year, or it was not selected.
                        'year_confirmed' => true,
                        'download_status' => 'not_downloaded',
                        'download_path' => null,
                        'metadata' => $image['metadata'],
                    ]
                );
            });
    }

    /**
     * Categories string Wikimedia attaches to an image page, if any.
     */
    private function categoriesOf(array $image): ?string
    {
        return $image['metadata']['imageinfo'][0]['extmetadata']['Categories']['value'] ?? null;
    }
}
