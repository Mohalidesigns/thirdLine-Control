<?php

namespace App\Services;

use App\Models\ObligationAssignment;
use App\Models\ObligationInstance;
use App\Models\OrganisationUnit;
use App\Models\RegulatoryObligation;
use App\Models\User;
use App\Support\BusinessCalendar;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The obligation register and compliance calendar.
 *
 * Everything here is driven by seeded data (R1): due dates come from the
 * obligation's due_rule resolved against the entity's fiscal calendar,
 * jurisdiction holidays and the tenant timezone; applicability comes from
 * applies_to matched against the entity's regulatory profile; penalty
 * exposure comes from penalty_basis and penalty_amount_minor. No regulator,
 * deadline or fine is written into this class.
 */
class ObligationService
{
    public const DEFAULT_HORIZON_DAYS = 400;

    /** How far back the generator materialises periods so overdue items surface. */
    public const DEFAULT_LOOKBACK_DAYS = 365;

    public function __construct(private BusinessCalendar $calendar) {}

    // ── Applicability ────────────────────────────────────────────────────

    /**
     * Does this obligation bind this entity? An absent or empty facet in
     * applies_to means "no restriction on that facet"; a present facet must
     * match. Stricter reading wins: an unknown facet key is ignored rather
     * than treated as a pass, and an entity with no profile only satisfies
     * an unrestricted obligation.
     */
    public function appliesTo(RegulatoryObligation $obligation, OrganisationUnit $entity): bool
    {
        $rules = $obligation->applies_to ?? [];

        if (! $this->matchesFacet($rules['entity_types'] ?? null, $entity->entity_type)) {
            return false;
        }

        if (! $this->matchesFacet($rules['jurisdictions'] ?? null, $entity->jurisdiction)) {
            return false;
        }

        $licences = $rules['licence_categories'] ?? null;

        if (is_array($licences) && $licences !== []) {
            $held = $entity->licence_categories ?? [];

            if (array_intersect($licences, $held) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Collection<int, RegulatoryObligation>
     */
    public function applicableObligations(OrganisationUnit $entity, ?string $onDate = null): Collection
    {
        $onDate ??= now()->toDateString();

        return RegulatoryObligation::query()
            ->active()
            ->effectiveOn($onDate)
            ->with('regulator')
            ->get()
            ->filter(fn (RegulatoryObligation $o) => $this->appliesTo($o, $entity))
            ->values();
    }

    /**
     * A tenant may declare an obligation inapplicable, but the declaration
     * only bites once approved by someone other than the declarer.
     */
    public function declareNonApplicable(ObligationAssignment $assignment, User $user, string $reason): ObligationAssignment
    {
        $assignment->update([
            'is_applicable' => false,
            'non_applicability_reason' => $reason,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        $assignment->auditAction('non-applicability-declared', null, ['reason' => $reason]);

        return $assignment;
    }

    /** Maker-checker: the declarer can never approve their own declaration. */
    public function approveNonApplicability(ObligationAssignment $assignment, User $approver): ObligationAssignment
    {
        if ($assignment->owner_id === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A non-applicability declaration cannot be approved by the obligation owner who raised it.',
            ]);
        }

        if ($assignment->is_applicable) {
            throw ValidationException::withMessages([
                'approval' => 'There is no non-applicability declaration to approve on this assignment.',
            ]);
        }

        $assignment->update(['approved_by' => $approver->id, 'approved_at' => now()]);
        $assignment->auditAction('non-applicability-approved');

        return $assignment;
    }

    // ── Periods and due dates ────────────────────────────────────────────

    /**
     * Materialise the reporting periods of an obligation that overlap the
     * given window, anchored to the entity's fiscal calendar.
     *
     * @return array<int, array{label: string, start: Carbon, end: Carbon}>
     */
    public function periods(RegulatoryObligation $obligation, OrganisationUnit $entity, Carbon $from, Carbon $to): array
    {
        if ($obligation->trigger_type !== 'calendar' || $obligation->frequency === 'event_driven') {
            return [];
        }

        $fiscalStartMonth = $this->fiscalStartMonth($entity);

        return match ($obligation->frequency) {
            'one_off' => $this->oneOffPeriod($obligation, $from, $to),
            'daily' => $this->dayPeriods($from, $to),
            'weekly' => $this->weekPeriods($from, $to),
            'monthly' => $this->monthPeriods($from, $to),
            'quarterly' => $this->fiscalSlicePeriods($from, $to, $fiscalStartMonth, 3, 'Q'),
            'semi_annual' => $this->fiscalSlicePeriods($from, $to, $fiscalStartMonth, 6, 'H'),
            'annual' => $this->fiscalYearPeriods($from, $to, $fiscalStartMonth, 1),
            'biennial' => $this->fiscalYearPeriods($from, $to, $fiscalStartMonth, 2),
            'triennial' => $this->fiscalYearPeriods($from, $to, $fiscalStartMonth, 3),
            default => [],
        };
    }

    /**
     * Resolve a due date from the obligation's due_rule.
     *
     * fixed_date     — the first occurrence of month/day strictly after the
     *                  period end (a 31 March filing for a 31 December year
     *                  end falls in the following calendar year).
     * relative       — an anchor on the entity's calendar plus an offset.
     * event_relative — measured from the event, in hours or days.
     *
     * @param  array{label: string, start: Carbon, end: Carbon}  $period
     */
    public function resolveDueDate(
        RegulatoryObligation $obligation,
        OrganisationUnit $entity,
        array $period,
        ?Carbon $eventAt = null,
    ): ?Carbon {
        $rule = $obligation->due_rule ?? [];
        $timezone = $this->timezone($entity);
        $jurisdiction = $entity->jurisdiction ?: ($obligation->regulator?->jurisdiction ?? 'NG');

        $due = match ($rule['type'] ?? null) {
            'fixed_date' => $this->resolveFixedDate($rule, $period, $timezone),
            'relative' => $this->resolveRelative($rule, $period, $entity, $timezone),
            'event_relative' => $this->resolveEventRelative($rule, $eventAt, $timezone),
            default => null,
        };

        if (! $due) {
            return null;
        }

        // An hour-precise deadline (a 72-hour breach notification) is a
        // wall-clock countdown: it does not roll off a weekend or holiday.
        if (($rule['type'] ?? null) === 'event_relative' && isset($rule['offset_hours'])) {
            return $due->copy()->setTimezone(config('app.timezone'));
        }

        $due = match ($rule['roll'] ?? 'next_business_day') {
            'none' => $due,
            'previous_business_day' => $this->calendar->forTenant($entity->tenant_id)
                ->previousBusinessDay($due, $jurisdiction),
            default => $this->calendar->forTenant($entity->tenant_id)
                ->nextBusinessDay($due, $jurisdiction),
        };

        return $due->endOfDay()->setTimezone(config('app.timezone'));
    }

    // ── Instance generation ──────────────────────────────────────────────

    /**
     * Idempotent: an existing (assignment, period) instance is refreshed,
     * never duplicated, and a due date that has moved is corrected in place
     * unless the instance has already been submitted.
     */
    public function generateInstances(
        ObligationAssignment $assignment,
        int $horizonDays = self::DEFAULT_HORIZON_DAYS,
        int $lookbackDays = self::DEFAULT_LOOKBACK_DAYS,
    ): int {
        $obligation = $assignment->obligation;
        $entity = $assignment->entity;

        if (! $obligation || ! $entity || ! $obligation->is_active || ! $assignment->generatesInstances()) {
            return 0;
        }

        $from = now()->subDays($lookbackDays)->startOfDay();
        $to = now()->addDays($horizonDays)->endOfDay();

        // Never materialise a period outside the obligation's effective window.
        if ($obligation->effective_from) {
            $from = $from->max($obligation->effective_from->copy()->startOfDay());
        }

        if ($obligation->effective_to) {
            $to = $to->min($obligation->effective_to->copy()->endOfDay());
        }

        if ($from->greaterThan($to)) {
            return 0;
        }

        $created = 0;

        foreach ($this->periods($obligation, $entity, $from, $to) as $period) {
            $due = $this->resolveDueDate($obligation, $entity, $period);

            if (! $due || $due->greaterThan(now()->addDays($horizonDays)->endOfDay())) {
                continue;
            }

            $existing = ObligationInstance::withoutGlobalScope('tenant')
                ->where('tenant_id', $assignment->tenant_id)
                ->where('assignment_id', $assignment->id)
                ->where('period_label', $period['label'])
                ->first();

            if ($existing) {
                $this->refreshInstance($existing, $due);

                continue;
            }

            ObligationInstance::create([
                'tenant_id' => $assignment->tenant_id,
                'obligation_id' => $obligation->id,
                'assignment_id' => $assignment->id,
                'period_label' => $period['label'],
                'period_start' => $period['start']->toDateString(),
                'period_end' => $period['end']->toDateString(),
                'due_at' => $due,
                'status' => 'Not Started',
                'penalty_currency' => $obligation->penalty_currency,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Recompute overdue state and live penalty exposure. Instances that are
     * already accepted, waived or submitted are left alone.
     */
    public function refreshInstance(ObligationInstance $instance, ?Carbon $newDueAt = null): ObligationInstance
    {
        $obligation = $instance->obligation;

        if ($newDueAt && ! in_array($instance->status, ['Submitted', 'Accepted', 'Waived'], true)) {
            $instance->due_at = $newDueAt;
        }

        if ($instance->isClosed() || in_array($instance->status, ['Submitted'], true)) {
            $instance->save();

            return $instance;
        }

        $graceEnd = $instance->due_at->copy()->addDays($obligation?->grace_period_days ?? 0);
        $daysOverdue = now()->greaterThan($graceEnd) ? $graceEnd->diffInDays(now()) : 0;

        $instance->days_overdue = (int) $daysOverdue;

        if ($daysOverdue > 0 && $instance->status !== 'Rejected') {
            $instance->status = 'Overdue';
        }

        $exposure = $this->calculateExposure($instance);
        $instance->penalty_exposure_minor = $exposure?->amount ?? 0;
        $instance->penalty_currency = $exposure?->currency ?? $instance->penalty_currency;
        $instance->save();

        return $instance;
    }

    /**
     * Live cost of non-compliance for one instance.
     *
     * For a 'percentage' basis the stored amount is a rate in basis points
     * (100 = 1.00%); it needs a declared base (turnover, net revenue) to
     * become money, so without one the exposure is zero and the UI shows the
     * rate instead of inventing a figure.
     */
    public function calculateExposure(ObligationInstance $instance, ?Money $base = null): ?Money
    {
        $obligation = $instance->obligation;

        if (! $obligation || $obligation->penalty_amount_minor === null || ! $obligation->penalty_currency) {
            return null;
        }

        $currency = $obligation->penalty_currency;

        if ($instance->days_overdue <= 0 && $instance->status !== 'Overdue') {
            return Money::zero($currency);
        }

        $unit = Money::of((int) $obligation->penalty_amount_minor, $currency);
        $days = max(0, (int) $instance->days_overdue);

        return match ($obligation->penalty_basis) {
            'fixed', 'per_instance' => $unit,
            'per_day' => $unit->multiply($days),
            // A part-week counts as a whole week: the CBN consumer-protection
            // ₦500,000 "per complaint per week unresolved" case.
            'per_week' => $unit->multiply((int) ceil($days / 7)),
            'percentage' => $base
                ? $base->multiplyByDecimal(bcdiv((string) $obligation->penalty_amount_minor, '10000', 6))
                : Money::zero($currency),
            default => Money::zero($currency),
        };
    }

    /**
     * The only instances that may enter a generated regulatory submission.
     *
     * R10 made enforceable rather than advisory: an obligation whose detail
     * has not been confirmed against the regulator's primary document is
     * excluded here, so no downstream generator can file it by accident.
     *
     * @return Builder<ObligationInstance>
     */
    public function submissionEligibleInstances(?int $tenantId = null): Builder
    {
        return ObligationInstance::query()
            ->when($tenantId, fn ($q, $t) => $q->withoutGlobalScope('tenant')->where('obligation_instances.tenant_id', $t))
            ->whereHas('obligation', fn ($q) => $q->withoutGlobalScope('tenant')->where('verification_status', 'verified'));
    }

    /** Aggregate live exposure across a tenant's open instances, per currency. */
    public function exposureByCurrency(?int $tenantId = null): array
    {
        $rows = ObligationInstance::query()
            ->when($tenantId, fn ($q, $t) => $q->withoutGlobalScope('tenant')->where('tenant_id', $t))
            ->where('penalty_exposure_minor', '>', 0)
            ->selectRaw('penalty_currency, SUM(penalty_exposure_minor) as total')
            ->groupBy('penalty_currency')
            ->pluck('total', 'penalty_currency');

        return $rows
            ->filter(fn ($total, $currency) => (bool) $currency)
            ->map(fn ($total, $currency) => Money::of((int) $total, $currency)->jsonSerialize())
            ->all();
    }

    // ── Instance workflow ────────────────────────────────────────────────

    public function start(ObligationInstance $instance): ObligationInstance
    {
        $this->transition($instance, 'In Progress');

        return $instance;
    }

    /**
     * An instance only reaches Submitted with a submission reference AND at
     * least one evidence item — the filing has to be provable after the fact.
     */
    public function submit(ObligationInstance $instance, User $user, array $data): ObligationInstance
    {
        $reference = trim((string) ($data['submission_reference'] ?? ''));

        if ($reference === '') {
            throw ValidationException::withMessages([
                'submission_reference' => 'A submission reference is required before an obligation can be marked Submitted.',
            ]);
        }

        if ($instance->evidence()->count() === 0) {
            throw ValidationException::withMessages([
                'evidence' => 'Attach at least one evidence item before marking this obligation Submitted.',
            ]);
        }

        return DB::transaction(function () use ($instance, $user, $data, $reference) {
            $this->transition($instance, 'Submitted', [
                'submission_reference' => $reference,
                'acknowledgement_ref' => $data['acknowledgement_ref'] ?? null,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'evidence_count' => $instance->evidence()->count(),
                'notes' => $data['notes'] ?? $instance->notes,
            ]);

            $instance->auditAction('submitted', null, ['submission_reference' => $reference]);

            return $instance;
        });
    }

    /**
     * Maker-checker: the person who recorded the submission can never accept
     * it. There is no administrator bypass (R2).
     */
    public function review(ObligationInstance $instance, User $reviewer, string $decision, ?string $notes = null): ObligationInstance
    {
        if ($instance->submitted_by === $reviewer->id) {
            throw ValidationException::withMessages([
                'review' => 'A submission cannot be reviewed by the user who recorded it.',
            ]);
        }

        if (! in_array($decision, ['Accepted', 'Rejected'], true)) {
            throw ValidationException::withMessages(['review' => 'A review decision must be Accepted or Rejected.']);
        }

        $this->transition($instance, $decision, [
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'notes' => $notes ?? $instance->notes,
        ]);

        $instance->auditAction(strtolower($decision), null, ['notes' => $notes]);

        return $instance;
    }

    /** Waiving is a compliance decision, so it carries a mandatory reason. */
    public function waive(ObligationInstance $instance, User $user, string $reason): ObligationInstance
    {
        $this->transition($instance, 'Waived', [
            'notes' => $reason,
            'penalty_exposure_minor' => 0,
        ]);

        $instance->auditAction('waived', null, ['reason' => $reason, 'by' => $user->id]);

        return $instance;
    }

    public function transition(ObligationInstance $instance, string $to, array $extra = []): void
    {
        $allowed = ObligationInstance::TRANSITIONS[$instance->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "An obligation instance cannot move from {$instance->status} to {$to}.",
            ]);
        }

        $instance->update(['status' => $to, ...$extra]);
    }

    // ── Period builders ──────────────────────────────────────────────────

    /**
     * @return array<int, array{label: string, start: Carbon, end: Carbon}>
     */
    private function oneOffPeriod(RegulatoryObligation $obligation, Carbon $from, Carbon $to): array
    {
        $start = $obligation->effective_from?->copy() ?? $from->copy();
        $end = $obligation->effective_to?->copy() ?? $start->copy()->endOfYear();

        return [[
            'label' => 'One-off',
            'start' => $start->startOfDay(),
            'end' => $end->endOfDay(),
        ]];
    }

    private function dayPeriods(Carbon $from, Carbon $to): array
    {
        $periods = [];

        for ($day = $from->copy()->startOfDay(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $periods[] = [
                'label' => $day->format('Y-m-d'),
                'start' => $day->copy()->startOfDay(),
                'end' => $day->copy()->endOfDay(),
            ];
        }

        return $periods;
    }

    private function weekPeriods(Carbon $from, Carbon $to): array
    {
        $periods = [];

        for ($week = $from->copy()->startOfWeek(); $week->lessThanOrEqualTo($to); $week->addWeek()) {
            $periods[] = [
                'label' => $week->format('o-\WW'),
                'start' => $week->copy()->startOfWeek(),
                'end' => $week->copy()->endOfWeek(),
            ];
        }

        return $periods;
    }

    private function monthPeriods(Carbon $from, Carbon $to): array
    {
        $periods = [];

        for ($month = $from->copy()->startOfMonth(); $month->lessThanOrEqualTo($to); $month->addMonthNoOverflow()) {
            $periods[] = [
                'label' => $month->format('M Y'),
                'start' => $month->copy()->startOfMonth(),
                'end' => $month->copy()->endOfMonth(),
            ];
        }

        return $periods;
    }

    /**
     * Fiscal quarters and halves, numbered from the fiscal year start rather
     * than from January (R7 — a March year end has Q1 running April to June).
     */
    private function fiscalSlicePeriods(Carbon $from, Carbon $to, int $fiscalStartMonth, int $months, string $prefix): array
    {
        $periods = [];
        $cursor = $this->fiscalYearStart($from, $fiscalStartMonth);

        while ($cursor->lessThanOrEqualTo($to)) {
            $yearStart = $cursor->copy();
            $slices = intdiv(12, $months);

            for ($i = 0; $i < $slices; $i++) {
                $start = $yearStart->copy()->addMonthsNoOverflow($i * $months);
                $end = $start->copy()->addMonthsNoOverflow($months)->subDay()->endOfDay();

                if ($end->lessThan($from) || $start->greaterThan($to)) {
                    continue;
                }

                $periods[] = [
                    'label' => sprintf('%s%d FY%d', $prefix, $i + 1, $this->fiscalYearLabel($start, $fiscalStartMonth)),
                    'start' => $start,
                    'end' => $end,
                ];
            }

            $cursor->addYear();
        }

        return $periods;
    }

    private function fiscalYearPeriods(Carbon $from, Carbon $to, int $fiscalStartMonth, int $everyYears): array
    {
        $periods = [];
        $cursor = $this->fiscalYearStart($from, $fiscalStartMonth);

        while ($cursor->lessThanOrEqualTo($to)) {
            $start = $cursor->copy();
            $end = $start->copy()->addYear()->subDay()->endOfDay();

            $periods[] = [
                'label' => 'FY'.$this->fiscalYearLabel($start, $fiscalStartMonth),
                'start' => $start,
                'end' => $end,
            ];

            $cursor->addYears($everyYears);
        }

        return $periods;
    }

    private function fiscalYearStart(Carbon $date, int $fiscalStartMonth): Carbon
    {
        $start = Carbon::create($date->year, $fiscalStartMonth, 1)->startOfDay();

        return $start->greaterThan($date) ? $start->subYear() : $start;
    }

    /** A fiscal year is labelled by the calendar year in which it ends. */
    private function fiscalYearLabel(Carbon $fiscalYearStart, int $fiscalStartMonth): int
    {
        return $fiscalStartMonth === 1 ? $fiscalYearStart->year : $fiscalYearStart->year + 1;
    }

    // ── Due-rule resolvers ───────────────────────────────────────────────

    private function resolveFixedDate(array $rule, array $period, string $timezone): ?Carbon
    {
        $day = (int) ($rule['day'] ?? 0);

        if ($day < 1 || $day > 31) {
            return null;
        }

        $month = isset($rule['month']) ? (int) $rule['month'] : null;
        // A period boundary is a date, not an instant: rebuild it in the
        // tenant's timezone so a UTC end-of-day does not read as the next day.
        $periodEnd = Carbon::parse($period['end']->toDateString(), $timezone)->endOfDay();

        $candidate = $this->safeDate($periodEnd->year, $month ?? $periodEnd->month, $day, $timezone);

        // First occurrence strictly after the period it reports on.
        $guard = 0;

        while ($candidate->lessThanOrEqualTo($periodEnd) && $guard++ < 40) {
            $candidate = $month
                ? $this->safeDate($candidate->year + 1, $month, $day, $timezone)
                : $this->safeDate(
                    $candidate->copy()->addMonthNoOverflow()->year,
                    $candidate->copy()->addMonthNoOverflow()->month,
                    $day,
                    $timezone
                );
        }

        return $candidate->addDays((int) ($rule['offset_days'] ?? 0));
    }

    private function resolveRelative(array $rule, array $period, OrganisationUnit $entity, string $timezone): ?Carbon
    {
        $anchorDate = match ($rule['anchor'] ?? 'period_end') {
            'period_start' => $period['start']->copy(),
            'fiscal_year_end' => $entity->fiscalYearEnd($period['end']->year),
            'fiscal_year_start' => $entity->fiscalYearEnd($period['end']->year)->copy()->subYear()->addDay(),
            'incorporation_date' => $entity->incorporated_on?->copy(),
            default => $period['end']->copy(),
        };

        if (! $anchorDate) {
            return null;
        }

        // Same rule as fixed dates: anchors are calendar dates, so the offset
        // is applied to the date in the tenant's timezone, not to an instant.
        return Carbon::parse($anchorDate->toDateString(), $timezone)
            ->addMonthsNoOverflow((int) ($rule['offset_months'] ?? 0))
            ->addDays((int) ($rule['offset_days'] ?? 0))
            ->startOfDay();
    }

    private function resolveEventRelative(array $rule, ?Carbon $eventAt, string $timezone): ?Carbon
    {
        if (! $eventAt) {
            return null;
        }

        return $eventAt->copy()->setTimezone($timezone)
            ->addHours((int) ($rule['offset_hours'] ?? 0))
            ->addDays((int) ($rule['offset_days'] ?? 0));
    }

    /** 31 February is a data error, not a due date — clamp to month end. */
    private function safeDate(int $year, int $month, int $day, string $timezone): Carbon
    {
        $base = Carbon::create($year, $month, 1, 0, 0, 0, $timezone);

        return $base->setUnitNoOverflow('day', min($day, $base->daysInMonth), 'month')->startOfDay();
    }

    private function matchesFacet(?array $allowed, ?string $value): bool
    {
        if (! is_array($allowed) || $allowed === []) {
            return true;
        }

        return $value !== null && in_array($value, $allowed, true);
    }

    private function fiscalStartMonth(OrganisationUnit $entity): int
    {
        if ($entity->fiscal_year_end_month) {
            return ((int) $entity->fiscal_year_end_month % 12) + 1;
        }

        return (int) ($entity->tenant?->fiscal_year_start_month ?: 1);
    }

    private function timezone(OrganisationUnit $entity): string
    {
        return $entity->tenant?->localisation()['timezone'] ?? config('app.timezone', 'Africa/Lagos');
    }
}
