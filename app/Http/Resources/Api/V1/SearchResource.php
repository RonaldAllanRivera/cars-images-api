<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'make' => $this->make,
            'model' => $this->model,
            'commons_category' => $this->commons_category,
            'from_year' => $this->from_year,
            'to_year' => $this->to_year,
            'color' => $this->color,
            'transmission' => $this->transmission,
            'transparent_background' => $this->transparent_background,
            'images_per_year' => $this->images_per_year,
            'status' => $this->status,
            'csv_import_id' => $this->csv_import_id,
            'requested_by' => $this->requested_by,
            'images_count' => $this->whenCounted('images'),
            'images' => ImageResource::collection($this->whenLoaded('images')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
