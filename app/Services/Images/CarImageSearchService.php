<?php

namespace App\Services\Images;

use App\Models\CarImage;
use App\Models\CarSearch;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

class CarImageSearchService
{
    public function __construct(
        protected WikimediaClient $wikimedia,
        protected DatabaseManager $db
    ) {
    }

    public function createSearch(
        User $user,
        string $make,
        ?string $model,
        int $fromYear,
        int $toYear,
        ?string $color,
        bool $transparent,
        int $imagesPerYear = 10
    ): CarSearch {
        return CarSearch::create([
            'make' => $make,
            'model' => $model,
            'from_year' => $fromYear,
            'to_year' => $toYear,
            'color' => $color,
            'transparent_background' => $transparent,
            'images_per_year' => $imagesPerYear,
            'status' => 'pending',
            'requested_by' => $user->id,
        ]);
    }

    public function runSearch(CarSearch $search): Collection
    {
        $results = collect();

        $this->db->transaction(function () use ($search, &$results) {
            $search->update(['status' => 'running']);

            $years = range($search->from_year, $search->to_year);

            foreach ($years as $year) {
                $yearResults = $this->fetchAndStoreForYear($search, $year, $search->images_per_year);

                $results = $results->merge($yearResults);
            }

            $search->update(['status' => 'completed']);
        });

        return $results;
    }

    public function fetchAndStoreForYear(CarSearch $search, int $year, int $limit): Collection
    {
        $images = $this->wikimedia->searchCars(
            $search->make,
            $search->model,
            $year,
            $search->color,
            $search->transparent_background,
            $limit
        );

        return $images->map(function (array $image) use ($search, $year) {
            return CarImage::updateOrCreate(
                [
                    'provider' => $image['provider'],
                    'provider_image_id' => $image['provider_image_id'],
                ],
                [
                    'car_search_id' => $search->id,
                    'make' => $search->make,
                    'model' => $search->model,
                    'year' => $year,
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
                    'download_status' => 'not_downloaded',
                    'download_path' => null,
                    'metadata' => $image['metadata'],
                ]
            );
        });
    }
}
