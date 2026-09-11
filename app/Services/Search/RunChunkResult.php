<?php

namespace App\Services\Search;

class RunChunkResult
{
    /**
     * @param  list<array{id: int, make: string, model: ?string, from_year: int, outcome: string}>  $outcomes
     * @param  array{status: int, retry_after_seconds: ?int}|null  $blocked
     */
    public function __construct(
        public readonly array $outcomes,
        public readonly float $ranSeconds,
        public readonly int $remaining,
        public readonly ?array $blocked = null,
    ) {}
}
