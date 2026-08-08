<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view documents');
    }

    /**
     * Confidential documents are visible only to the roles listed on the
     * record — deliberately including no System Administrator bypass.
     */
    public function view(User $user, Document $document): bool
    {
        if (! $user->can('view documents')) {
            return false;
        }

        if ($document->owner_id === $user->id) {
            return true;
        }

        if (! $document->accessibleBy($user)) {
            return false;
        }

        // Unpublished drafts stay with the owner and the approval chain.
        if (in_array($document->status, ['Draft', 'Under Review', 'Approved'], true)) {
            return $user->can('approve documents') || $user->can('create documents');
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('create documents');
    }

    public function update(User $user, Document $document): bool
    {
        if (! in_array($document->status, ['Draft', 'Under Review'], true)) {
            return false;
        }

        return $document->owner_id === $user->id
            || ($user->can('create documents') && $user->can('approve documents'));
    }

    public function submit(User $user, Document $document): bool
    {
        return $document->status === 'Draft'
            && ($document->owner_id === $user->id || $user->can('create documents'));
    }

    /** Maker-checker: approve needs the permission AND approver ≠ owner (R2). */
    public function approve(User $user, Document $document): bool
    {
        return $user->can('approve documents')
            && $document->status === 'Under Review'
            && $document->owner_id !== $user->id;
    }

    public function publish(User $user, Document $document): bool
    {
        return $user->can('approve documents') && $document->status === 'Approved';
    }

    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function manageFolders(User $user): bool
    {
        return $user->can('manage document-folders');
    }
}
