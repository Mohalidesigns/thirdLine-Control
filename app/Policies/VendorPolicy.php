<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view vendors');
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $user->can('view vendors') || $vendor->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('manage vendors');
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $user->can('manage vendors');
    }

    public function assess(User $user, Vendor $vendor): bool
    {
        return $user->can('assess vendors');
    }

    public function screen(User $user, Vendor $vendor): bool
    {
        return $user->can('screen vendors');
    }

    /**
     * Recording a material-outsourcing notification is a filing with a
     * regulator, so it carries its own permission rather than riding on
     * general vendor management.
     */
    public function notifyRegulator(User $user, Vendor $vendor): bool
    {
        return $user->can('notify vendor-regulators');
    }

    public function manageContracts(User $user, Vendor $vendor): bool
    {
        return $user->can('manage vendor-contracts');
    }
}
