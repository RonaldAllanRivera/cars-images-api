<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ErrorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'context' => $this->context,
            'severity' => $this->severity,
            'message' => $this->message,
            'exception_class' => $this->exception_class,
            'exception_message' => $this->exception_message,
            'trace_excerpt' => $this->trace_excerpt,
            'details' => $this->details,
            'car_search_id' => $this->car_search_id,
            'csv_import_id' => $this->csv_import_id,
            'car_image_id' => $this->car_image_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
