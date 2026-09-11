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

    /**
     * Run this import's outstanding searches.
     *
     * Takes the import, not just the user, even though the check is currently
     * permissive like its neighbours. The client names which import to run, so
     * that object belongs in the authorization seam - a class-level check
     * cannot express "not that one" no matter what is written inside it.
     *
     * There is no ownership model to enforce today: `imported_by` records who
     * uploaded, not who owns, and every read endpoint already returns every
     * import to every authenticated user. If ownership ever arrives, this
     * method is where it goes, and the three read paths need it too.
     */
    public function run(User $user, CsvImport $import): bool
    {
        return true;
    }
}
