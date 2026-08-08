<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view incidents');
    }

    public function view(User $user, Incident $incident): bool
    {
        return $user->can('view incidents')
            || $incident->owner_id === $user->id
            || $incident->reported_by === $user->id
            || $incident->investigator_id === $user->id;
    }

    /** Anyone who can see the register can report — under-reporting is the risk. */
    public function create(User $user): bool
    {
        return $user->can('create incidents');
    }

    public function update(User $user, Incident $incident): bool
    {
        return $incident->status !== 'Closed'
            && ($user->can('investigate incidents')
                || $incident->owner_id === $user->id
                || $incident->investigator_id === $user->id);
    }

    public function triage(User $user, Incident $incident): bool
    {
        return $user->can('investigate incidents')
            && in_array($incident->status, ['Reported', 'Triaged'], true);
    }

    public function investigate(User $user, Incident $incident): bool
    {
        return $this->update($user, $incident) && $user->can('investigate incidents');
    }

    /**
     * Closure is a second-line decision and never the reporter's: the person
     * who raised an incident cannot be the one who declares it finished.
     */
    public function close(User $user, Incident $incident): bool
    {
        return $user->can('close incidents')
            && in_array($incident->status, Incident::OPEN_STATUSES, true)
            && $incident->reported_by !== $user->id;
    }

    public function reopen(User $user, Incident $incident): bool
    {
        return $user->can('close incidents') && $incident->status === 'Closed';
    }

    public function notify(User $user, Incident $incident): bool
    {
        return $user->can('notify regulators');
    }
}
