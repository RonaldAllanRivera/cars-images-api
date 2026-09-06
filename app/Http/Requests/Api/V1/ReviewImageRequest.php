<?php

namespace App\Http\Requests\Api\V1;

use App\Models\CarImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'review_status' => ['required', Rule::in(CarImage::reviewStatuses())],
        ];
    }
}
