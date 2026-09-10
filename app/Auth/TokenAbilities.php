<?php

namespace App\Auth;

/**
 * What a personal access token may do. Routes name these in their
 * `ability:` middleware and the login endpoint mints a scoped subset;
 * keeping the strings here means a typo is a fatal error, not a silent 403.
 */
final class TokenAbilities
{
    public const SEARCH_READ = 'search:read';

    public const SEARCH_WRITE = 'search:write';

    public const REVIEW_WRITE = 'review:write';

    public const ERRORS_READ = 'errors:read';

    /*
     * Privileged. Not issued unless a client asks for them by name, because
     * these are the ones that must never sit in a web build's localStorage:
     * SEARCH_RUN drives an unbounded paced run against Wikimedia (the thing
     * wikimedia_block_events exists to record), EXPORTS_READ hands over the
     * library's metadata in one request, and IMPORTS_WRITE can seed hundreds
     * of queries. No route consumes them yet - the endpoints land in P3/P4.
     *
     * SEARCH_RUN is deliberately separate from SEARCH_WRITE. Both "run a
     * search", but SEARCH_WRITE runs one query inline, hard-capped by
     * StoreSearchRequest at four years and five images per year. SEARCH_RUN
     * drives a queue of pre-existing rows with no such ceiling. Same exposure
     * per request, wildly different exposure per token.
     */
    public const IMPORTS_READ = 'imports:read';

    public const IMPORTS_WRITE = 'imports:write';

    public const SEARCH_RUN = 'search:run';

    public const EXPORTS_READ = 'exports:read';

    /**
     * Every ability that exists: the set a login request is validated and
     * intersected against.
     *
     * NOT what a token is issued by default - see defaultScope(). These two
     * are deliberately separate methods. When one method did both jobs,
     * adding an ability here would have widened what every already-deployed
     * client receives, and the mobile client parses `abilities` through a
     * Zod enum that accepts exactly the four in defaultScope().
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SEARCH_READ,
            self::SEARCH_WRITE,
            self::REVIEW_WRITE,
            self::ERRORS_READ,
            self::IMPORTS_READ,
            self::IMPORTS_WRITE,
            self::SEARCH_RUN,
            self::EXPORTS_READ,
        ];
    }

    /**
     * What a client that asks for nothing gets.
     *
     * Exactly the four abilities that existed before scoping, so every
     * already-deployed client keeps precisely what it has today. That
     * compatibility is a side effect of a rule that is independently right:
     * privileged verbs are opt-in.
     *
     * @return list<string>
     */
    public static function defaultScope(): array
    {
        return [
            self::SEARCH_READ,
            self::SEARCH_WRITE,
            self::REVIEW_WRITE,
            self::ERRORS_READ,
        ];
    }
}
