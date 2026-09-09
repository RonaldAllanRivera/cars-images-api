<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The image as the mobile client sees it. Field names are the column names;
 * the Zod schema on the client mirrors this list exactly.
 */
class ImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'car_search_id' => $this->car_search_id,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'color' => $this->color,
            'title' => $this->title,
            'description' => $this->description,
            'source_url' => $this->source_url,
            'thumbnail_url' => $this->thumbnail_url,
            'width' => $this->width,
            'height' => $this->height,
            'license' => $this->license,
            'attribution' => $this->attribution,
            'make_confirmed' => $this->make_confirmed,
            'year_confirmed' => $this->year_confirmed,
            'review_status' => $this->review_status,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'download_status' => $this->download_status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
