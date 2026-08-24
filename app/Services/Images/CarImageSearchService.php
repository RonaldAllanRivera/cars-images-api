<?php

namespace App\Services\Images;

use App\Models\CarImage;
use App\Models\CarMake;
use App\Models\CarSearch;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Throwable;

class CarImageSearchService
{
    /**
     * Used only when the `car_makes` catalogue is empty.
     *
     * @var array<int, string>
     */
    private const FALLBACK_MAKES = [
        'Acura', 'Audi', 'BMW', 'Chevrolet', 'Chrysler', 'Dodge', 'Ford', 'Honda',
        'Hyundai', 'Infiniti', 'Jaguar', 'Jeep', 'Kia', 'Lexus', 'Mazda',
        'Mercedes-Benz', 'Mitsubishi', 'Nissan', 'Peugeot', 'Porsche', 'Renault',
        'Subaru', 'Suzuki', 'Tesla', 'Toyota', 'Volkswagen', 'Volvo',
    ];

    public function __construct(
        protected WikimediaClient $wikimedia,
        protected DatabaseManager $db,
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
        // Cache eviction is not database state, so it stays outside the
        // transaction — re-fetching is harmless if the refresh then fails.
        foreach (range($search->from_year, $search->to_year) as $year) {
            $this->wikimedia->clearSearchCache(
                $search->make,
                $search->model,
                $year,
                $search->color,
                $this->transmissionForQuery($search),
                $search->transparent_background,
                $search->images_per_year,
            );
        }

        try {
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

        $search->update(['status' => 'running']);

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
        $images = $this->wikimedia->searchCars(
            $search->make,
            $search->model,
            $year,
            $search->color,
            $this->transmissionForQuery($search),
            $search->transparent_background,
            $limit
        );

        $knownMakes = $this->knownMakes();

        return $images
            // Drop photographs that plainly belong to a different manufacturer.
            // Wikimedia's full-text search is loose: an "Acura CL" query matches
            // the Honda Accord chassis code "CL3", so an Acura search came back
            // full of Hondas. Flagging them was not enough — see MakeRelevanceChecker.
            ->reject(fn (array $image) => $this->makeChecker->isOffMake(
                $search->make,
                $image['title'] ?? null,
                $image['description'] ?? null,
                $this->categoriesOf($image),
                $knownMakes,
            ))
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
                        'download_status' => 'not_downloaded',
                        'download_path' => null,
                        'metadata' => $image['metadata'],
                    ]
                );
            });
    }

    /**
     * Transmission value to feed into the Wikimedia query.
     *
     * CSV-imported searches keep `transmission` only as informational
     * metadata for the manifest export — including it in the image query
     * over-constrains it and returns zero results (image pages never
     * mention "Automatic 4-spd"). Ad-hoc searches keep their behaviour.
     */
    private function transmissionForQuery(CarSearch $search): ?string
    {
        return $search->csv_import_id !== null ? null : $search->transmission;
    }

    /**
     * Categories string Wikimedia attaches to an image page, if any.
     */
    private function categoriesOf(array $image): ?string
    {
        return $image['metadata']['imageinfo'][0]['extmetadata']['Categories']['value'] ?? null;
    }

    /**
     * Manufacturer names used to detect that an image is of another car.
     *
     * Sourced from the curated `car_makes` catalogue so the filter improves
     * as the catalogue grows. The fallback keeps the filter working on a
     * fresh install where the catalogue has not been seeded yet — without it
     * an unseeded database would silently disable off-make rejection.
     *
     * @return array<int, string>
     */
    private function knownMakes(): array
    {
        $catalogue = CarMake::query()->pluck('name')->all();

        if ($catalogue !== []) {
            return $catalogue;
        }

        return self::FALLBACK_MAKES;
    }
}
