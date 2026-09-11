<?php

namespace App\Policies;

use App\Models\CarSearch;
use App\Models\User;

/**
 * Deliberately permissive - see CarImagePolicy.
 */
class CarSearchPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CarSearch $search): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Advancing searches that already exist.
     *
     * Separate from `create`, which is the verb that makes new ones: a token
     * may reasonably be allowed to drain a queue without being allowed to
     * fill it, and that distinction is why search:run and search:write are
     * separate abilities.
     */
    public function run(User $user): bool
    {
        return true;
    }
}
