<?php

namespace App\Filament\Resources\CarSearchResource\Pages;

use App\Filament\Resources\CarSearchResource;
use App\Jobs\RunCarSearchJob;
use App\Services\Images\CarImageSearchService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCarSearch extends CreateRecord
{
    protected static string $resource = CarSearchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $service = app(CarImageSearchService::class);
        $user = auth()->user();

        $existing = $service->findExistingCompletedSearch(
            $data['make'],
            $data['model'] ?? null,
            (int) $data['from_year'],
            (int) $data['to_year'],
            $data['color'] ?? null,
            (bool) ($data['transparent_background'] ?? false),
            (int) ($data['images_per_year'] ?? 10),
        );

        if ($existing) {
            return $existing;
        }

        $search = $service->createSearch(
            $user,
            $data['make'],
            $data['model'] ?? null,
            (int) $data['from_year'],
            (int) $data['to_year'],
            $data['color'] ?? null,
            (bool) ($data['transparent_background'] ?? false),
            (int) ($data['images_per_year'] ?? 10),
        );

        RunCarSearchJob::dispatch($search);

        return $search;
    }
}
