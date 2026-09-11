<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RunChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A chunk is scoped to one import. There is no ad-hoc bulk run: searches
     * without a csv_import_id are the Search tab's single-run path, which
     * runs one query inline under much tighter caps.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'csv_import_id' => ['required', 'integer', 'exists:csv_imports,id'],
        ];
    }
}
