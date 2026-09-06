<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mirrors the admin form's rules, plus the API caps.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $maxYear = (int) date('Y') + 1;

        return [
            'make' => ['required', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'from_year' => ['required', 'integer', 'min:1900', "max:{$maxYear}"],
            'to_year' => ['required', 'integer', 'min:1900', "max:{$maxYear}"],
            'color' => ['nullable', 'string', 'max:64'],
            'transmission' => ['nullable', 'string', 'max:64'],
            'transparent_background' => ['sometimes', 'boolean'],
            'images_per_year' => [
                'sometimes', 'integer', 'min:1',
                'max:'.(int) config('cars-images.api_search_max_images_per_year'),
            ],
        ];
    }

    /**
     * The span rule needs both years, so it runs after the field rules.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // The field rules have already run; a year that failed them is
            // not a number to subtract from.
            if ($validator->errors()->hasAny(['from_year', 'to_year'])) {
                return;
            }

            $span = (int) config('cars-images.api_search_max_year_span');
            $width = abs((int) $this->input('to_year') - (int) $this->input('from_year'));

            if ($width > $span) {
                $validator->errors()->add(
                    'to_year',
                    "The year range may span at most {$span} years, because the search runs inside this request.",
                );
            }
        });
    }

    /**
     * The eight positional arguments CarImageSearchService takes, years in
     * ascending order so dedupe and creation see the same key.
     *
     * @return array{string, ?string, int, int, ?string, ?string, bool, int}
     */
    public function searchArguments(): array
    {
        $data = $this->validated();
        $from = (int) $data['from_year'];
        $to = (int) $data['to_year'];

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            $data['make'],
            $data['model'] ?? null,
            $from,
            $to,
            $data['color'] ?? null,
            $data['transmission'] ?? null,
            (bool) ($data['transparent_background'] ?? false),
            (int) ($data['images_per_year'] ?? config('cars-images.api_search_max_images_per_year')),
        ];
    }
}
