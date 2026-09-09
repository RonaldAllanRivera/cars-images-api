<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListErrorsRequest;
use App\Http\Resources\Api\V1\ErrorResource;
use App\Models\ErrorEvent;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ErrorController extends Controller
{
    public function index(ListErrorsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $errors = ErrorEvent::query()
            ->when(isset($filters['context']), fn ($query) => $query->where('context', $filters['context']))
            ->when(isset($filters['severity']), fn ($query) => $query->where('severity', $filters['severity']))
            // occurred_at alone is not unique; id breaks ties so the cursor
            // never skips two events logged in the same second.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ErrorResource::collection($errors);
    }
}
