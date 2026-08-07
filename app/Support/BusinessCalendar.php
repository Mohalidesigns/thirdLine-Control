<?php

namespace App\Support;

use App\Models\PublicHoliday;
use Illuminate\Support\Carbon;

/**
 * Weekend and public-holiday awareness for regulatory due dates. Holidays
 * are seeded data (R1), scoped by jurisdiction and overridable per tenant,
 * so a filing deadline never silently lands on a day the regulator is shut.
 *
 * Recurring rows (fixed-date holidays such as 1 January) match on month and
 * day in any year; lunar and declared holidays are dated rows.
 */
class BusinessCalendar
{
    /** @var array<string, array<string, true>> jurisdiction => ['MM-DD' or 'YYYY-MM-DD' => true] */
    private array $cache = [];

    public function __construct(private ?int $tenantId = null) {}

    /** A calendar that also sees the given tenant's own holiday overrides. */
    public function forTenant(?int $tenantId): self
    {
        return new self($tenantId);
    }

    public function isWeekend(Carbon $date): bool
    {
        return $date->isSaturday() || $date->isSunday();
    }

    public function isHoliday(Carbon $date, string $jurisdiction = 'NG'): bool
    {
        $holidays = $this->holidays($jurisdiction);

        return isset($holidays[$date->format('m-d')]) || isset($holidays[$date->format('Y-m-d')]);
    }

    public function isBusinessDay(Carbon $date, string $jurisdiction = 'NG'): bool
    {
        return ! $this->isWeekend($date) && ! $this->isHoliday($date, $jurisdiction);
    }

    public function nextBusinessDay(Carbon $date, string $jurisdiction = 'NG'): Carbon
    {
        $candidate = $date->copy();

        // Bounded: a run of more than a fortnight of closures is a data error,
        // not a calendar the product should silently follow.
        for ($i = 0; $i < 14 && ! $this->isBusinessDay($candidate, $jurisdiction); $i++) {
            $candidate->addDay();
        }

        return $candidate;
    }

    public function previousBusinessDay(Carbon $date, string $jurisdiction = 'NG'): Carbon
    {
        $candidate = $date->copy();

        for ($i = 0; $i < 14 && ! $this->isBusinessDay($candidate, $jurisdiction); $i++) {
            $candidate->subDay();
        }

        return $candidate;
    }

    /**
     * @return array<string, true>
     */
    private function holidays(string $jurisdiction): array
    {
        if (isset($this->cache[$jurisdiction])) {
            return $this->cache[$jurisdiction];
        }

        // The generator runs unauthenticated, so the tenant scope is applied
        // explicitly: global holidays plus this tenant's own additions.
        $rows = PublicHoliday::withoutGlobalScope('tenant')
            ->where('jurisdiction', $jurisdiction)
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($this->tenantId, fn ($w, $t) => $w->orWhere('tenant_id', $t)))
            ->get(['holiday_date', 'is_recurring']);

        $map = [];

        foreach ($rows as $row) {
            $key = $row->is_recurring
                ? $row->holiday_date->format('m-d')
                : $row->holiday_date->format('Y-m-d');

            $map[$key] = true;
        }

        return $this->cache[$jurisdiction] = $map;
    }
}
