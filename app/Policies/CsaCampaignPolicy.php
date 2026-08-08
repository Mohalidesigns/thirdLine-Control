<?php

namespace App\Policies;

use App\Models\CsaCampaign;
use App\Models\User;

class CsaCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view campaigns') || $user->can('respond campaigns');
    }

    public function view(User $user, CsaCampaign $campaign): bool
    {
        if ($user->can('view campaigns') || $user->can('manage campaigns')) {
            return true;
        }

        // Respondents see campaigns they are assigned to (or any open
        // anonymous survey).
        if ($campaign->is_anonymous && $campaign->isOpen()) {
            return $user->can('respond campaigns');
        }

        return $user->can('respond campaigns')
            && $campaign->responses()->where('respondent_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('manage campaigns');
    }

    public function update(User $user, CsaCampaign $campaign): bool
    {
        return $user->can('manage campaigns') && $campaign->status === 'Draft';
    }

    /**
     * Maker-checker: campaign approval needs 'review campaigns' and a
     * different person than the creator. No admin bypass (R2).
     */
    public function approve(User $user, CsaCampaign $campaign): bool
    {
        return $user->can('review campaigns')
            && $campaign->status === 'Draft'
            && $campaign->created_by !== $user->id;
    }

    public function open(User $user, CsaCampaign $campaign): bool
    {
        return $user->can('manage campaigns')
            && $campaign->approved_at !== null
            && in_array($campaign->status, ['Draft', 'Scheduled'], true);
    }

    public function close(User $user, CsaCampaign $campaign): bool
    {
        return $user->can('manage campaigns') && $campaign->isOpen();
    }

    /** Aggregate results; anonymous surveys never expose row-level data. */
    public function viewResults(User $user, CsaCampaign $campaign): bool
    {
        return $user->can('view campaigns') || $user->can('review campaigns');
    }
}
