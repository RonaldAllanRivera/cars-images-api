<?php

namespace App\Auth;

/**
 * What a personal access token may do. Routes name these in their
 * `ability:` middleware and the login endpoint mints tokens with all four;
 * keeping the strings here means a typo is a fatal error, not a silent 403.
 */
final class TokenAbilities
{
    public const SEARCH_READ = 'search:read';

    public const SEARCH_WRITE = 'search:write';

    public const REVIEW_WRITE = 'review:write';

    public const ERRORS_READ = 'errors:read';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::SEARCH_READ, self::SEARCH_WRITE, self::REVIEW_WRITE, self::ERRORS_READ];
    }
}
