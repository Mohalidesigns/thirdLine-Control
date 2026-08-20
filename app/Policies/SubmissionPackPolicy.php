<?php

namespace App\Policies;

use App\Models\SubmissionPack;
use App\Models\User;

/**
 * Maker-checker on a regulatory filing (R2).
 *
 * The separation is stated twice on purpose: here, so the UI never offers a
 * button that would be refused, and again in SubmissionPackService, which is
 * what actually cannot be routed around. There is no administrator bypass in
 * either — a System Administrator who generated a pack still cannot review or
 * approve it.
 */
class SubmissionPackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view submissions');
    }

    public function view(User $user, SubmissionPack $pack): bool
    {
        return $user->can('view submissions') || $pack->generated_by === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('generate submissions');
    }

    /** Only an unlocked, still-draft pack is editable, and only by a generator. */
    public function update(User $user, SubmissionPack $pack): bool
    {
        return $user->can('generate submissions')
            && ! $pack->isLocked()
            && in_array($pack->status, ['Draft', 'Rejected'], true);
    }

    public function sendForReview(User $user, SubmissionPack $pack): bool
    {
        return $user->can('generate submissions') && $pack->status === 'Draft';
    }

    /** Gate one: never the generator. */
    public function review(User $user, SubmissionPack $pack): bool
    {
        return $user->can('review submissions')
            && $pack->status === 'Under Review'
            && $pack->generated_by !== $user->id;
    }

    /** Gate two: never the generator, never the reviewer. */
    public function approve(User $user, SubmissionPack $pack): bool
    {
        return $user->can('approve submissions')
            && $pack->status === 'Under Review'
            && $pack->reviewed_at !== null
            && $pack->generated_by !== $user->id
            && $pack->reviewed_by !== $user->id;
    }

    public function file(User $user, SubmissionPack $pack): bool
    {
        return $user->can('file submissions') && $pack->status === 'Approved';
    }

    public function acknowledge(User $user, SubmissionPack $pack): bool
    {
        return $user->can('file submissions') && in_array($pack->status, ['Submitted', 'Resubmitted'], true);
    }

    public function reject(User $user, SubmissionPack $pack): bool
    {
        return $user->can('file submissions') && in_array($pack->status, ['Submitted', 'Resubmitted'], true);
    }

    public function download(User $user, SubmissionPack $pack): bool
    {
        return $this->view($user, $pack);
    }
}
