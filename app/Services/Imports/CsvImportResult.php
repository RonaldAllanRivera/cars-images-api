<?php

namespace App\Services\Imports;

use App\Models\CsvImport;

class CsvImportResult
{
    public function __construct(
        public readonly CsvImport $csvImport,
        public readonly int $skippedInvalidRows,
    ) {
    }
}
