<?php

namespace App\Http\Requests\Api\V1\Concerns;

/**
 * Cursor pagination for every list endpoint.
 *
 * Cursors, not page numbers: the mobile grid is an infinite scroll, and a
 * cursor neither skips nor repeats rows when a harvest inserts while the
 * user is mid-list. Offset pagination does both.
 */
trait PaginatesWithCursor
{
    /**
     * @return array<string, list<string>>
     */
    protected function paginationRules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    public function perPage(int $default = 24): int
    {
        return (int) ($this->validated()['per_page'] ?? $default);
    }
}
