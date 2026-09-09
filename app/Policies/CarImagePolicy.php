<?php

namespace App\Policies;

use App\Models\CarImage;
use App\Models\User;

/**
 * Deliberately permissive. The admin panel shows every record to every
 * signed-in user and the API matches it. The class exists so that
 * visibility is a decision written down here rather than an accident of
 * having no policy - tightening it later is a one-file change.
 */
class CarImagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CarImage $image): bool
    {
        return true;
    }

    public function update(User $user, CarImage $image): bool
    {
        return true;
    }
}
