<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The same caps the panel's upload form applies, read from config so the
     * two cannot drift. The row and projected-image ceilings live inside
     * CsvQueryImporter, which every caller passes through - this only bounds
     * what reaches it.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'csv_file' => [
                'required',
                'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'max:'.(int) config('cars-images.csv_import_max_upload_kb'),
            ],
        ];
    }
}
