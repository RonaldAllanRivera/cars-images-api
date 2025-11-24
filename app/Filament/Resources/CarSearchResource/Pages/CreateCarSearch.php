<?php

namespace App\Filament\Resources\CarSearchResource\Pages;

use App\Filament\Resources\CarSearchResource;
use App\Services\Images\CarImageSearchService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCarSearch extends CreateRecord
{
    protected static string $resource = CarSearchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $normalize = static function ($value): ?string {
            if ($value === '' || $value === '__all__') {
                return null;
            }

            return $value ?? null;
        };

        $service = app(CarImageSearchService::class);
        $user = auth()->user();

        $existing = $service->findExistingCompletedSearch(
            $data['make'],
            $normalize($data['model'] ?? null),
            (int) $data['from_year'],
            (int) $data['to_year'],
            $normalize($data['color'] ?? null),
            $normalize($data['transmission'] ?? null),
            (bool) ($data['transparent_background'] ?? false),
            (int) ($data['images_per_year'] ?? 10),
        );

        if ($existing) {
            return $existing;
        }

        $search = $service->createSearch(
            $user,
            $data['make'],
            $normalize($data['model'] ?? null),
            (int) $data['from_year'],
            (int) $data['to_year'],
            $normalize($data['color'] ?? null),
            $normalize($data['transmission'] ?? null),
            (bool) ($data['transparent_background'] ?? false),
            (int) ($data['images_per_year'] ?? 10),
        );

        $service->runSearch($search);

        return $search;
    }
}
