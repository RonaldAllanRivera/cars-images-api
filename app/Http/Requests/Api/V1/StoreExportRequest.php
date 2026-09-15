<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

/**
 * An export: a format, plus exactly the filters the grid accepts.
 *
 * Extends ListImagesRequest rather than copying its rules, so an export can
 * never be scoped to something the grid could not show - and so it inherits
 * prepareForValidation(), which remaps a JS client's "true"/"false" to 1/0
 * before the boolean rule sees them. A copied rules array would silently lack
 * that remap and 422 on filters the grid accepts.
 */
class StoreExportRequest extends ListImagesRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // Pagination means nothing to an export, which takes the whole set.
        $filters = array_diff_key(parent::rules(), array_flip(['per_page', 'cursor']));

        return ['format' => ['required', Rule::in(['csv', 'zip'])]] + $filters;
    }
}
