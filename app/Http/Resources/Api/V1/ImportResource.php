<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A CSV import as the client sees it.
 *
 * `importer_name` rather than a nested user object: the list needs one string,
 * and UserResource would drag `email` into a screen with no use for it.
 *
 * `coverage` appears only where the controller attached it. The list omits it
 * because six aggregate counts per row is a fan-out on a screen that only
 * needs to identify the import; the detail endpoint carries it.
 */
class ImportResource extends JsonResource
{
    /**
     * Null is meaningful here - it is what ImportCoverage returns for an
     * import with no searches - so a separate flag records whether coverage
     * was attached at all.
     *
     * @var array<string, int>|null
     */
    private ?array $coverage = null;

    private bool $withCoverage = false;

    /**
     * @param  array<string, int>|null  $coverage
     */
    public function withCoverage(?array $coverage): static
    {
        $this->coverage = $coverage;
        $this->withCoverage = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'total_rows' => $this->total_rows,
            'unique_combos' => $this->unique_combos,
            'duplicates_skipped' => $this->duplicates_skipped,
            'imported_by' => $this->imported_by,
            'importer_name' => $this->whenLoaded('importer', fn () => $this->importer?->name),
            'searches_count' => $this->whenCounted('searches'),
            'coverage' => $this->when($this->withCoverage, fn () => $this->coverage),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
