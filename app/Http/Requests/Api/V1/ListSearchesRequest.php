<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\PaginatesWithCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSearchesRequest extends FormRequest
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
            'status' => ['sometimes', Rule::in(['pending', 'running', 'completed', 'failed'])],
            // The panel keeps CSV-derived searches and ad-hoc ones in separate
            // resources, split on csv_import_id. This is that split.
            'source' => ['sometimes', Rule::in(['csv', 'adhoc'])],
            'csv_import_id' => ['sometimes', 'integer', 'exists:csv_imports,id'],
            'coverage' => ['sometimes', Rule::in(['with_images', 'no_images', 'not_run'])],
        ] + $this->paginationRules();
    }

    /**
     * Narrow a CarSearch query by every filter present in the request.
     *
     * Lives on the request rather than the controller, matching
     * ListImagesRequest.
     *
     * `coverage` answers what `status` cannot: a search that ran and found
     * nothing is `completed`, exactly like one that found five images. The
     * three cases are copied from the panel's own coverage filter so both
     * clients classify a row identically.
     */
    public function apply(Builder $query): Builder
    {
        $filters = $this->validated();

        if (array_key_exists('status', $filters)) {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('source', $filters)) {
            $filters['source'] === 'csv'
                ? $query->whereNotNull('csv_import_id')
                : $query->whereNull('csv_import_id');
        }

        if (array_key_exists('csv_import_id', $filters)) {
            $query->where('csv_import_id', $filters['csv_import_id']);
        }

        return match ($filters['coverage'] ?? null) {
            'with_images' => $query->whereHas('images'),
            // `running` belongs with `pending`: there is no worker on this
            // host, so a row left running is a request that died mid-search.
            'not_run' => $query->whereIn('status', ['pending', 'running']),
            'no_images' => $query->where('status', 'completed')->whereDoesntHave('images'),
            default => $query,
        };
    }
}
