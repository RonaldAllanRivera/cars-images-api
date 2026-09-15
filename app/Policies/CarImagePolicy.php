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

    /**
     * Export a filtered set of images.
     *
     * Class-level, not instance-level, and that is correct here. The P3b chunk
     * endpoint was flagged by an automated review because it authorized a class
     * while the client named a specific object, leaving the policy nothing to
     * examine. An export names no single object - it acts on a filtered set, so
     * there is no instance to pass. If per-image visibility ever arrives, it
     * belongs in the filtered query itself, not in a policy argument.
     */
    public function export(User $user): bool
    {
        return true;
    }
}
