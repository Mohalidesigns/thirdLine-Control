<?php

namespace App\Policies;

use App\Models\TestInstance;
use App\Models\User;

class TestInstancePolicy
{
    public function before(User $user): ?bool
    {
        return $user->hasRole('System Administrator') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TestInstance $instance): bool
    {
        // CR-03 §E.1: an entity-scoped task belongs to a desk or a branch.
        // A control officer reads their OWN unit's tasks, not the whole
        // network's — at the client's branch count the alternative is one
        // officer seeing several thousand other branches' checklists.
        if ($instance->control_entity_id) {
            return $this->reachesEntity($user, $instance);
        }

        if ($user->hasAnyRole(['Control Function Head', 'Control Officer', 'Executive Viewer', 'Line Manager'])) {
            return true;
        }

        return $instance->assigned_tester_id === $user->id
            || $instance->control?->owner_id === $user->id;
    }

    public function execute(User $user, TestInstance $instance): bool
    {
        if ($instance->isLocked()) {
            return false;
        }

        return $user->hasAnyRole(['Control Officer', 'Control Function Head'])
            && ($instance->assigned_tester_id === null || $instance->assigned_tester_id === $user->id);
    }

    /**
     * Reach on an entity-scoped task: the assigned tester, the named
     * reviewer, the desk's or branch's own officers, and the head of the
     * sub-unit it belongs to. Everything else — including a control
     * officer on a different desk — needs oversight breadth, which the
     * head of control and the board tier have and a peer does not.
     */
    private function reachesEntity(User $user, TestInstance $instance): bool
    {
        if ($user->hasAnyRole(['Control Function Head', 'Executive Viewer'])) {
            return true;
        }

        if ($instance->assigned_tester_id === $user->id || $instance->reviewer_id === $user->id) {
            return true;
        }

        $entity = $instance->controlEntity;

        if (! $entity) {
            return false;
        }

        return $entity->default_officer_id === $user->id
            || $entity->owner_id === $user->id
            || $entity->controlUnit?->head_user_id === $user->id;
    }

    /**
     * Segregation of duties (§4): the tester who executed a test can never
     * review it. Review is a control function sign-off.
     */
    public function review(User $user, TestInstance $instance): bool
    {
        if ($instance->assigned_tester_id === $user->id || $instance->status !== 'Submitted') {
            return false;
        }

        // CR-03 §C.4: the head of the sub-unit reviews their own unit's
        // control tasks. They still cannot review what they performed —
        // the guard above and TestingService::review() both say so.
        if ($instance->control_entity_id
            && $user->can('review control-tasks')
            && $instance->controlEntity?->controlUnit?->head_user_id === $user->id) {
            return true;
        }

        return $user->hasRole('Control Function Head');
    }

    public function reopen(User $user, TestInstance $instance): bool
    {
        return $user->hasRole('Control Function Head') && $instance->isLocked();
    }

    public function rate(User $user, TestInstance $instance): bool
    {
        return $user->hasAnyRole(['Control Officer', 'Control Function Head']);
    }

    /**
     * Rating approval mirrors maker-checker: the proposer cannot publish
     * their own rating (FR-7.6).
     */
    public function approveRating(User $user, TestInstance $instance): bool
    {
        return $user->hasRole('Control Function Head')
            && $instance->effectivenessRating?->rated_by !== $user->id;
    }
}
