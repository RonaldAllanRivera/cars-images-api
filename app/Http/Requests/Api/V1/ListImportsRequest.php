<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\PaginatesWithCursor;
use Illuminate\Foundation\Http\FormRequest;

class ListImportsRequest extends FormRequest
{
    use PaginatesWithCursor;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->paginationRules();
    }
}
