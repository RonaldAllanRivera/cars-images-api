<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\PaginatesWithCursor;
use App\Models\ErrorEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListErrorsRequest extends FormRequest
{
    use PaginatesWithCursor;

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
            'context' => ['sometimes', Rule::in(array_keys(ErrorEvent::contexts()))],
            'severity' => ['sometimes', Rule::in(['error', 'warning'])],
        ] + $this->paginationRules();
    }
}
