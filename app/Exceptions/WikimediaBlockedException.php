<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class WikimediaBlockedException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?int $retryAfterSeconds,
        public readonly string $responseExcerpt,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Wikimedia returned HTTP %d (Retry-After: %s)', $statusCode, $retryAfterSeconds ?? 'n/a'),
            0,
            $previous,
        );
    }
}
