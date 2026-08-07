<?php

namespace App\Policies;

use App\Models\ContentPack;
use App\Models\User;

class ContentPackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('install content-packs') || $user->can('view frameworks');
    }

    public function view(User $user, ContentPack $pack): bool
    {
        return $this->viewAny($user);
    }

    public function install(User $user): bool
    {
        return $user->can('install content-packs');
    }
}
