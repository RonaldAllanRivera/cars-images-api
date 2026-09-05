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
}
