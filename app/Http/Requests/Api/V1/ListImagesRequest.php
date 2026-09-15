<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\PaginatesWithCursor;
use App\Models\CarImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListImagesRequest extends FormRequest
{
    use PaginatesWithCursor;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Remap the two verdict filters' "true"/"false" spelling to 1/0 before
     * the `boolean` rule sees them.
     *
     * A JS client encodes booleans as the strings "true"/"false" in a query
     * string, but Laravel's `boolean` rule only accepts 0/1. Everything else
     * - blank, "yes", "maybe" - is left untouched and still 422s.
     */
    protected function prepareForValidation(): void
    {
        foreach (['make_confirmed', 'year_confirmed'] as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);

                if ($value === 'true') {
                    $this->merge([$field => '1']);
                } elseif ($value === 'false') {
                    $this->merge([$field => '0']);
                }
            }
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'make' => ['sometimes', 'string', 'max:255'],
            'model' => ['sometimes', 'string', 'max:255'],
            'year' => ['sometimes', 'integer', 'min:1900', 'max:'.((int) date('Y') + 1)],
            'make_confirmed' => ['sometimes', 'boolean'],
            'year_confirmed' => ['sometimes', 'boolean'],
            'review_status' => ['sometimes', Rule::in(CarImage::reviewStatuses())],
            'download_status' => ['sometimes', Rule::in(['not_downloaded', 'downloading', 'downloaded', 'failed'])],
            'csv_import_id' => ['sometimes', 'integer', 'exists:csv_imports,id'],
        ] + $this->paginationRules();
    }

    /**
     * Narrow a CarImage query by every filter present in the request.
     *
     * Lives on the request rather than the controller so the same filters
     * serve both /images and /searches/{search}/images.
     */
    public function apply(Builder $query): Builder
    {
        $filters = $this->validated();

        foreach (['make', 'model', 'year', 'review_status', 'download_status'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where($column, $filters[$column]);
            }
        }

        // A boolean filter matches its value only. The nullable "unknown"
        // verdict is neither true nor false and never matches - the same
        // rule the Filament filters apply.
        foreach (['make_confirmed', 'year_confirmed'] as $column) {
            if (array_key_exists($column, $filters)) {
                $query->where($column, $this->boolean($column));
            }
        }

        // Through the search, not a column on the image: an image belongs to a
        // search, and the search to an import.
        if (array_key_exists('csv_import_id', $filters)) {
            $query->whereHas('search', fn (Builder $q) => $q->where('csv_import_id', $filters['csv_import_id']));
        }

        return $query;
    }
}
