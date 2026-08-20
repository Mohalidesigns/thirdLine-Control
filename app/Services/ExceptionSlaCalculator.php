<?php

namespace App\Services;

use App\Models\ExceptionSlaPolicy;
use App\Models\Tenant;
use App\Support\BusinessCalendar;
use Illuminate\Support\Carbon;

/**
 * CR-01: the business-day clock behind every Exception Manager deadline.
 * Clocks run in the TENANT's timezone, count business days only (weekends
 * and seeded public_holidays are skipped — never calendar days), and every
 * duration is read from exception_sla_policies (R1/R-E), never a constant.
 */
class ExceptionSlaCalculator
{
    /**
     * When the addressed department must have opened the notice.
     * Hours roll forward across weekends and holidays: an escalation issued
     * on Friday evening is not "late-acknowledged" on Saturday morning.
     */
    public function acknowledgeDueAt(Carbon $issuedAt, ExceptionSlaPolicy $policy, Tenant $tenant): Carbon
    {
        $calendar = $this->calendarFor($tenant);
        $timezone = $this->timezone($tenant);

        $due = $issuedAt->copy()->setTimezone($timezone)->addHours($policy->acknowledge_within_hours);

        while (! $calendar->isBusinessDay($due)) {
            $due->addDay();
        }

        return $due->setTimezone(config('app.timezone'));
    }

    /**
     * When the full response is due: N business days after issue, expiring
     * at end of that business day in the tenant's timezone — a response
     * filed at 23:59 tenant time on the due date is ON time.
     */
    public function responseDueAt(Carbon $issuedAt, ExceptionSlaPolicy $policy, Tenant $tenant): Carbon
    {
        $timezone = $this->timezone($tenant);
        $local = $issuedAt->copy()->setTimezone($timezone)->startOfDay();

        return $this->addBusinessDays($local, $policy->respond_within_days, $tenant)
            ->endOfDay()
            ->setTimezone(config('app.timezone'));
    }

    /**
     * Business-day arithmetic in the tenant's calendar. Negative counts walk
     * backwards (reminder offsets before the due date).
     */
    public function addBusinessDays(Carbon $date, int $days, Tenant $tenant): Carbon
    {
        $calendar = $this->calendarFor($tenant);
        $cursor = $date->copy();
        $step = $days >= 0 ? 1 : -1;
        $remaining = abs($days);

        while ($remaining > 0) {
            $cursor->addDays($step);

            if ($calendar->isBusinessDay($cursor)) {
                $remaining--;
            }
        }

        return $cursor;
    }

    public function isLate(Carbon $submittedAt, ?Carbon $dueAt): bool
    {
        return $dueAt !== null && $submittedAt->greaterThan($dueAt);
    }

    /** Today at midnight in the tenant's timezone. */
    public function todayFor(Tenant $tenant): Carbon
    {
        return Carbon::now($this->timezone($tenant))->startOfDay();
    }

    public function timezone(Tenant $tenant): string
    {
        return $tenant->localisation()['timezone'];
    }

    private function calendarFor(Tenant $tenant): BusinessCalendar
    {
        return app(BusinessCalendar::class)->forTenant($tenant->id);
    }
}
