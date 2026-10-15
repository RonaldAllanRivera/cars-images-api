<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CarMake;
use Illuminate\Http\JsonResponse;

/**
 * The curated catalogue the search form suggests from.
 *
 * Only what is in the table. The panel's CarSearchResource also carries a
 * hard-coded copy for when the table is empty; that list is deliberately not
 * copied a third time here. An empty table is fixed by running CarMakeSeeder,
 * and the app's fields work as plain text meanwhile.
 */
class MakeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $makes = CarMake::query()
            ->with('models')
            ->orderBy('name')
            ->get()
            ->map(fn (CarMake $make): array => [
                'name' => $make->name,
                // As CarSearchResource::getModelOptionsForMake() sorts them,
                // so "Model 10" follows "Model 9" in both places.
                'models' => $make->models
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->pluck('name')
                    ->values()
                    ->all(),
            ]);

        return response()->json(['data' => $makes]);
    }
}
