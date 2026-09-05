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
     * Normalize the two verdict filters before the `boolean` rule sees them.
     *
     * Laravel's `boolean` rule only accepts true, false, 0, 1, '0', '1' -
     * not the query-string literals "true"/"false" a client actually sends.
     * filter_var() maps both spellings onto real booleans and turns anything
     * else into null, which the rule then rejects as invalid input.
     */
    protected function prepareForValidation(): void
    {
        foreach (['make_confirmed', 'year_confirmed'] as $field) {
            if ($this->has($field)) {
                $this->merge([
                    $field => filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                ]);
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

        return $query;
    }
}
