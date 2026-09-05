<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListImagesRequest;
use App\Http\Resources\Api\V1\ImageResource;
use App\Models\CarImage;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ImageController extends Controller
{
    public function index(ListImagesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CarImage::class);

        $images = $request->apply(CarImage::query())
            // Newest first on a unique column, so the cursor stays stable
            // while a harvest is inserting.
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return ImageResource::collection($images);
    }

    public function show(CarImage $image): ImageResource
    {
        Gate::authorize('view', $image);

        return ImageResource::make($image);
    }
}
