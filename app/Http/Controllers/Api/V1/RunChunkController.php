<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RunChunkRequest;
use App\Models\CarSearch;
use App\Services\Search\RunChunkAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class RunChunkController extends Controller
{
    /**
     * Run one bounded slice of an import's outstanding searches.
     *
     * Stateless by design: the client holds no queue, and the action
     * re-selects from the database each call. That is what makes a resumed
     * run the same request as the first one, and why an empty queue is a
     * successful empty response rather than a 404 - it is how the client's
     * loop terminates.
     */
    public function __invoke(RunChunkRequest $request, RunChunkAction $action): JsonResponse
    {
        Gate::authorize('run', CarSearch::class);

        $result = $action->execute((int) $request->validated()['csv_import_id']);

        return response()->json([
            'outcomes' => $result->outcomes,
            'ran_seconds' => $result->ranSeconds,
            'remaining' => $result->remaining,
            'blocked' => $result->blocked,
        ]);
    }
}
