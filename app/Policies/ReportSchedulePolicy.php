<?php

namespace App\Policies;

use App\Models\ReportSchedule;
use App\Models\User;

/**
 * Scheduling is its own permission because it is its own risk: a schedule
 * mails a document to a list on a cadence, and the person who may design a
 * report is not automatically the person who may decide who receives it.
 */
class ReportSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('schedule reports');
    }

    public function view(User $user, ReportSchedule $schedule): bool
    {
        return $user->can('schedule reports');
    }

    public function create(User $user): bool
    {
        return $user->can('schedule reports');
    }

    public function update(User $user, ReportSchedule $schedule): bool
    {
        return $user->can('schedule reports');
    }

    public function delete(User $user, ReportSchedule $schedule): bool
    {
        return $user->can('schedule reports');
    }

    /** Running by hand still generates and distributes, so it needs both. */
    public function run(User $user, ReportSchedule $schedule): bool
    {
        return $user->can('schedule reports') && $user->can('export reports');
    }
}
