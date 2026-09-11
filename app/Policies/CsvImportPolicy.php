<?php

namespace App\Policies;

use App\Models\CsvImport;
use App\Models\User;

/**
 * Deliberately permissive - see CarImagePolicy. Every authenticated user is an
 * admin, exactly as the panel treats them, and the class exists so that
 * visibility is a decision written down here rather than an accident of having
 * no policy.
 *
 * The token ability and this policy answer different questions: the ability
 * says this DEVICE may attempt it, the policy says this HUMAN may. Both gate
 * every import route, so revoking a person does not mean hunting their tokens.
 *
 * No `delete`. Nothing in this programme lets a mobile token destroy anything,
 * and the method's absence is what enforces that.
 */
class CsvImportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CsvImport $import): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
