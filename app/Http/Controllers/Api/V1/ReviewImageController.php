<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewImageRequest;
use App\Http\Resources\Api\V1\ImageResource;
use App\Models\CarImage;
use Illuminate\Support\Facades\Gate;

class ReviewImageController extends Controller
{
    /**
     * Record the human verdict. Only the three review columns change;
     * make_confirmed / year_confirmed belong to MakeRelevanceChecker.
     */
    public function __invoke(ReviewImageRequest $request, CarImage $image): ImageResource
    {
        Gate::authorize('update', $image);

        $status = $request->validated()['review_status'];

        // "Pending" is the absence of a verdict, so it carries no reviewer.
        $image->forceFill($status === CarImage::REVIEW_PENDING
            ? ['review_status' => $status, 'reviewed_by' => null, 'reviewed_at' => null]
            : ['review_status' => $status, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]
        )->save();

        return ImageResource::make($image);
    }
}
