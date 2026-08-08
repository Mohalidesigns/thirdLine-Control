<?php

namespace App\Policies;

use App\Models\DataSource;
use App\Models\User;

/**
 * A data source holds a live credential into a bank's core. Registering
 * one is an administrative act; approving one is a second person's act,
 * and there is no administrator bypass on that (R2).
 */
class DataSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view data-sources');
    }

    public function view(User $user, DataSource $source): bool
    {
        return $user->can('view data-sources') || $source->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('manage data-sources');
    }

    public function update(User $user, DataSource $source): bool
    {
        return $user->can('manage data-sources');
    }

    /**
     * Maker ≠ checker: whoever registered or owns the source cannot be
     * the person who approves it into production.
     */
    public function approve(User $user, DataSource $source): bool
    {
        return $user->can('approve data-sources')
            && $source->created_by !== $user->id
            && $source->owner_id !== $user->id;
    }

    /** A connection test reaches a third-party system; it needs the manage right. */
    public function test(User $user, DataSource $source): bool
    {
        return $user->can('manage data-sources');
    }

    public function resume(User $user, DataSource $source): bool
    {
        return $user->can('manage data-sources');
    }

    /**
     * Authorising retention of sensitive personal data in clear is a data
     * protection decision, not an engineering one (12.7, R5).
     */
    public function authoriseRetention(User $user, DataSource $source): bool
    {
        return $user->can('authorise pii-retention');
    }

    public function delete(User $user, DataSource $source): bool
    {
        return $user->can('manage data-sources') && $source->approved_at === null;
    }
}
