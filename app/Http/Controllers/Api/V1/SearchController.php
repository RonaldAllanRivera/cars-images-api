<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListImagesRequest;
use App\Http\Requests\Api\V1\ListSearchesRequest;
use App\Http\Resources\Api\V1\ImageResource;
use App\Http\Resources\Api\V1\SearchResource;
use App\Models\CarSearch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SearchController extends Controller
{
    public function index(ListSearchesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CarSearch::class);

        $status = $request->validated()['status'] ?? null;

        $searches = CarSearch::query()
            ->withCount('images')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return SearchResource::collection($searches);
    }

    public function show(CarSearch $search): SearchResource
    {
        Gate::authorize('view', $search);

        return SearchResource::make($search->loadCount('images'));
    }

    public function images(ListImagesRequest $request, CarSearch $search): AnonymousResourceCollection
    {
        Gate::authorize('view', $search);

        $images = $request->apply($search->images()->getQuery())
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ImageResource::collection($images);
    }
}
