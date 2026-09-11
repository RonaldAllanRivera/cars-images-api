<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RunChunkRequest;
use App\Models\CsvImport;
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
        // Resolved and authorized as an object, not a class: the client names
        // which import to run, so that object has to reach the policy. The
        // check is permissive today - there is no ownership model in this app -
        // but a class-level gate could never become one.
        $import = CsvImport::findOrFail((int) $request->validated()['csv_import_id']);

        Gate::authorize('run', $import);

        $result = $action->execute($import->id);

        return response()->json([
            'outcomes' => $result->outcomes,
            'ran_seconds' => $result->ranSeconds,
            'remaining' => $result->remaining,
            'blocked' => $result->blocked,
        ]);
    }
}
