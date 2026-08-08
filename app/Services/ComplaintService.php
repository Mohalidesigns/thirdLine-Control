<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Control;
use App\Models\Incident;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Consumer complaints under the CBN Consumer Protection Regulations (11.3).
 *
 * Every regulatory number in this module comes out of the seeded CBN-CPF
 * obligation records (R1):
 *
 *   • the acknowledgement window is the CBN-CPF-ACK-24H record's
 *     `due_rule.offset_hours`, resolved through NotificationObligationResolver
 *     in the tenant's timezone;
 *   • the ₦2,000,000 acknowledgement-failure figure is that record's
 *     `penalty_amount_minor` with basis `per_instance`;
 *   • the ₦500,000-per-week unresolved figure is the sibling record with
 *     basis `per_week`, discovered from the same framework — no obligation
 *     reference is written into this class.
 *
 * The one thing the pack does not carry is a per-complaint resolution
 * window: CBN-CPF-RESOLUTION is a weekly calendar obligation on the
 * institution, not a per-case clock. The window is therefore a tenant
 * setting (`settings.complaints.resolution_days`, default 14) and the
 * assumption is stated in the UI beside the figure it drives.
 */
class ComplaintService
{
    /** Event key the acknowledgement obligation is registered against. */
    public const ACKNOWLEDGEMENT_EVENT = 'complaint_received';

    /** Tenant-overridable resolution window in days (see class docblock). */
    public const DEFAULT_RESOLUTION_DAYS = 14;

    public function __construct(private NotificationObligationResolver $resolver) {}

    // ── Intake ───────────────────────────────────────────────────────────

    /**
     * Omni-channel intake. The tracking ID is unique per tenant and is the
     * only handle a customer ever needs; received_at may be backdated for a
     * letter, but the acknowledgement clock is always measured from it.
     */
    public function intake(array $data, ?User $user = null): Complaint
    {
        // Normalised to the app timezone before it is stored: Eloquent writes
        // a Carbon's wall-clock reading, so an inbound "+01:00" instant would
        // otherwise be persisted an hour out and start the clock at the wrong
        // moment. Display timezone is a rendering concern (R7).
        $receivedAt = isset($data['received_at'])
            ? Carbon::parse($data['received_at'])->setTimezone(config('app.timezone'))
            : now();

        if ($receivedAt->isFuture()) {
            throw ValidationException::withMessages([
                'received_at' => 'A complaint cannot be received in the future.',
            ]);
        }

        return DB::transaction(function () use ($data, $receivedAt) {
            $complaint = Complaint::create([
                ...collect($data)->except(['acknowledged_at', 'tracking_id'])->all(),
                'complaint_ref' => Complaint::nextReference('CMP', true, 'complaint_ref'),
                'tracking_id' => $this->nextTrackingId(),
                'received_at' => $receivedAt,
                'status' => 'Received',
                'acknowledged_at' => null,
            ]);

            $this->applyDeadlines($complaint);

            $complaint->logActivity('received', 'Complaint received via '.$complaint->channel.'.', $complaint->channel, true);
            $complaint->auditAction('received');

            return $complaint->fresh();
        });
    }

    /**
     * A customer-facing, collision-resistant handle. Deliberately not the
     * database id: a tracking ID gets read out over the phone.
     */
    public function nextTrackingId(): string
    {
        do {
            $candidate = 'CT'.now()->format('y').strtoupper(bin2hex(random_bytes(4)));
        } while (Complaint::where('tracking_id', $candidate)->exists());

        return $candidate;
    }

    // ── The 24-hour acknowledgement clock (11.3) ─────────────────────────

    /**
     * Resolve and store both SLA clocks from the obligation records. Called
     * at intake and whenever the entity changes, so a complaint that moves
     * between entities picks up that entity's calendar and timezone.
     */
    public function applyDeadlines(Complaint $complaint): Complaint
    {
        $deadline = $this->acknowledgementDeadline($complaint);

        $complaint->updateQuietly([
            'acknowledgement_due_at' => $deadline['due_at'] ?? null,
            'resolution_due_at' => $complaint->received_at
                ?->copy()->addDays($this->resolutionDays($complaint->tenant_id)),
            'penalty_currency' => $complaint->penalty_currency
                ?? $deadline['obligation']?->penalty_currency,
        ]);

        return $complaint;
    }

    /**
     * @return array{obligation: ?RegulatoryObligation, due_at: ?Carbon}
     */
    public function acknowledgementDeadline(Complaint $complaint): array
    {
        $resolved = $this->resolver->strictestDeadlineFor(
            self::ACKNOWLEDGEMENT_EVENT,
            $complaint->entity,
            $complaint->received_at ?? now(),
            $complaint->tenant_id,
        );

        return [
            'obligation' => $resolved['obligation'] ?? null,
            'due_at' => $resolved['due_at'] ?? null,
        ];
    }

    /**
     * Acknowledgement. The timestamp is server-side, full stop: a client
     * cannot supply the moment that stops a regulatory clock, and this
     * method takes no time argument at all so nothing downstream can pass
     * one by accident.
     */
    public function acknowledge(Complaint $complaint, User $user, ?string $note = null): Complaint
    {
        if ($complaint->acknowledged_at !== null) {
            return $complaint;
        }

        $now = now();

        $complaint->update([
            'acknowledged_at' => $now,
            'status' => $complaint->status === 'Received' ? 'Acknowledged' : $complaint->status,
        ]);

        $complaint->logActivity(
            'acknowledged',
            $note ?? 'Acknowledgement issued with tracking ID '.$complaint->tracking_id.'.',
            $complaint->channel,
            true,
        );

        $complaint->auditAction('acknowledged', null, [
            'acknowledged_at' => $now->toIso8601String(),
            'due_at' => $complaint->acknowledgement_due_at?->toIso8601String(),
            'within_sla' => ! $complaint->acknowledgementBreached(),
            'by' => $user->id,
        ]);

        return $this->refreshSla($complaint->fresh());
    }

    // ── Handling ─────────────────────────────────────────────────────────

    public function assign(Complaint $complaint, User $assignee, User $actor): Complaint
    {
        $complaint->update(['assigned_to' => $assignee->id]);
        $complaint->logActivity('assigned', 'Assigned to '.$assignee->name.'.');
        $complaint->auditAction('assigned', null, ['assignee' => $assignee->id, 'by' => $actor->id]);

        return $complaint;
    }

    public function investigate(Complaint $complaint, User $user, ?string $note = null): Complaint
    {
        $this->transition($complaint, 'Under Investigation');
        $complaint->logActivity('investigation', $note ?? 'Investigation started.');

        return $this->refreshSla($complaint->fresh());
    }

    public function awaitCustomer(Complaint $complaint, string $note): Complaint
    {
        $this->transition($complaint, 'Awaiting Customer');
        $complaint->logActivity('awaiting-customer', $note, $complaint->channel, true);

        return $this->refreshSla($complaint->fresh());
    }

    /**
     * Resolution. A root cause is mandatory: the whole point of the module
     * is that a resolved complaint tells the control function something,
     * and "resolved, cause unknown" tells it nothing.
     */
    public function resolve(Complaint $complaint, User $user, array $data): Complaint
    {
        if (empty($data['root_cause_id'])) {
            throw ValidationException::withMessages([
                'root_cause_id' => 'A complaint cannot be resolved without a root cause.',
            ]);
        }

        if (! $complaint->isAcknowledged()) {
            throw ValidationException::withMessages([
                'acknowledgement' => 'Acknowledge the complaint before resolving it.',
            ]);
        }

        $this->transition($complaint, 'Resolved', [
            'resolved_at' => now(),
            'resolution_summary' => $data['resolution_summary'],
            'resolution_type' => $data['resolution_type'],
            'root_cause_id' => $data['root_cause_id'],
            'redress_amount_minor' => $data['redress_amount_minor'] ?? null,
            'redress_currency' => $data['redress_currency'] ?? null,
            'customer_satisfied' => $data['customer_satisfied'] ?? null,
            'linked_control_id' => $data['linked_control_id'] ?? $complaint->linked_control_id,
        ]);

        $complaint->logActivity('resolved', $data['resolution_summary'], $complaint->channel, true);
        $complaint->auditAction('resolved', null, ['resolution_type' => $data['resolution_type'], 'by' => $user->id]);

        return $this->refreshSla($complaint->fresh());
    }

    public function close(Complaint $complaint, User $user): Complaint
    {
        $this->transition($complaint, 'Closed');
        $complaint->logActivity('closed', 'Complaint closed.');

        return $this->refreshSla($complaint->fresh());
    }

    public function reopen(Complaint $complaint, User $user, string $reason): Complaint
    {
        $this->transition($complaint, 'Reopened', ['resolved_at' => null]);
        $complaint->logActivity('reopened', $reason, $complaint->channel, true);
        $complaint->auditAction('reopened', null, ['reason' => $reason, 'by' => $user->id]);

        return $this->refreshSla($complaint->fresh());
    }

    public function escalateToRegulator(Complaint $complaint, User $user, string $reference, string $note): Complaint
    {
        $this->transition($complaint, 'Escalated to Regulator', [
            'escalated_to_regulator' => true,
            'regulator_reference' => $reference,
        ]);

        $complaint->logActivity('regulator-escalation', $note, 'regulator_referral', true);
        $complaint->auditAction('escalated-to-regulator', null, ['reference' => $reference, 'by' => $user->id]);

        return $this->refreshSla($complaint->fresh());
    }

    /** A systemic complaint becomes an incident; the two stay linked. */
    public function linkIncident(Complaint $complaint, Incident $incident): Complaint
    {
        $complaint->update(['linked_incident_id' => $incident->id]);
        app(LinkageService::class)->link('complaint', $complaint->id, 'incident', $incident->id, 'escalates_to');
        $complaint->logActivity('linked-incident', 'Linked to incident '.$incident->incident_ref.'.');

        return $complaint;
    }

    public function transition(Complaint $complaint, string $to, array $extra = []): void
    {
        if ($to === $complaint->status) {
            $complaint->update($extra);

            return;
        }

        $allowed = Complaint::TRANSITIONS[$complaint->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A complaint cannot move from {$complaint->status} to {$to}.",
            ]);
        }

        $complaint->update(['status' => $to, ...$extra]);
    }

    // ── SLA and live penalty exposure (11.3) ─────────────────────────────

    public function refreshSla(Complaint $complaint): Complaint
    {
        $end = $complaint->resolved_at ?? now();
        $exposure = $this->penaltyExposure($complaint);

        $complaint->updateQuietly([
            'days_open' => $complaint->received_at
                ? (int) $complaint->received_at->diffInDays($end, absolute: true)
                : 0,
            'sla_breached' => $complaint->acknowledgementBreached() || $this->resolutionBreached($complaint),
            'penalty_exposure_minor' => $exposure?->amount ?? 0,
            'penalty_currency' => $exposure?->currency ?? $complaint->penalty_currency,
        ]);

        return $complaint;
    }

    public function resolutionBreached(Complaint $complaint): bool
    {
        if ($complaint->resolution_due_at === null) {
            return false;
        }

        return $complaint->isResolved()
            ? $complaint->resolved_at?->greaterThan($complaint->resolution_due_at) ?? false
            : now()->greaterThan($complaint->resolution_due_at);
    }

    /**
     * Live exposure for one complaint: the acknowledgement penalty if that
     * window was missed, plus one unit of the per-week penalty for every
     * started week past the resolution deadline.
     *
     * A part week counts as a whole week — the same ceiling
     * ObligationService applies to a per_week basis, so the two engines
     * cannot disagree about what "per week" means.
     */
    public function penaltyExposure(Complaint $complaint): ?Money
    {
        $ackObligation = $this->acknowledgementObligation($complaint);
        $weeklyObligation = $this->resolutionObligation($complaint, $ackObligation);

        $currency = $ackObligation?->penalty_currency ?? $weeklyObligation?->penalty_currency;

        if (! $currency) {
            return null;
        }

        $total = Money::zero($currency);

        // The acknowledgement penalty is a per_instance figure: it lands once
        // when the window is missed and does not accrue.
        if ($ackObligation?->penalty_amount_minor !== null && $complaint->acknowledgementBreached()) {
            $total = $total->add(
                Money::of((int) $ackObligation->penalty_amount_minor, $ackObligation->penalty_currency)
            );
        }

        if ($weeklyObligation?->penalty_amount_minor !== null && $this->resolutionBreached($complaint)) {
            $weeks = $this->weeksOverdue($complaint);

            if ($weeks > 0) {
                $total = $total->add(
                    Money::of((int) $weeklyObligation->penalty_amount_minor, $weeklyObligation->penalty_currency)
                        ->multiply($weeks)
                );
            }
        }

        return $total;
    }

    /**
     * Started weeks past the resolution deadline; a part week counts as a
     * whole week.
     *
     * Counted in whole days from midnight to midnight, not in elapsed
     * seconds: a regulation that says "per week unresolved" means calendar
     * weeks, and a sub-second overshoot past the deadline must not round a
     * complaint straight into its second week of penalty.
     */
    public function weeksOverdue(Complaint $complaint, ?Carbon $asOf = null): int
    {
        if ($complaint->resolution_due_at === null) {
            return 0;
        }

        $end = $complaint->resolved_at ?? $asOf ?? now();

        if ($end->lessThanOrEqualTo($complaint->resolution_due_at)) {
            return 0;
        }

        $days = (int) $complaint->resolution_due_at->copy()->startOfDay()
            ->diffInDays($end->copy()->startOfDay(), absolute: true);

        return (int) ceil(max($days, 1) / 7);
    }

    /** Aggregate open exposure per currency — never summed across them (R7). */
    public function exposureByCurrency(?int $tenantId = null): array
    {
        return Complaint::query()
            ->when($tenantId, fn ($q, $t) => $q->withoutGlobalScope('tenant')->where('tenant_id', $t))
            ->where('penalty_exposure_minor', '>', 0)
            ->selectRaw('penalty_currency, SUM(penalty_exposure_minor) as total')
            ->groupBy('penalty_currency')
            ->pluck('total', 'penalty_currency')
            ->filter(fn ($total, $currency) => (bool) $currency)
            ->map(fn ($total, $currency) => Money::of((int) $total, $currency)->jsonSerialize())
            ->all();
    }

    /** Nightly sweep across every open complaint in a tenant. */
    public function refreshAll(?int $tenantId = null): int
    {
        $count = 0;

        Complaint::withoutGlobalScope('tenant')
            ->when($tenantId, fn ($q, $t) => $q->where('tenant_id', $t))
            ->whereIn('status', Complaint::OPEN_STATUSES)
            ->chunkById(200, function ($complaints) use (&$count) {
                foreach ($complaints as $complaint) {
                    $this->refreshSla($complaint);
                    $count++;
                }
            });

        return $count;
    }

    // ── Obligation discovery (nothing regulator-specific in PHP) ─────────

    public function acknowledgementObligation(Complaint $complaint): ?RegulatoryObligation
    {
        $candidates = $this->resolver->obligationsFor(
            [self::ACKNOWLEDGEMENT_EVENT],
            $complaint->entity,
            $complaint->tenant_id,
        );

        // Prefer one that carries a penalty — that is the record the
        // exposure figure has to come from — otherwise take the window only.
        return $candidates->first(fn (RegulatoryObligation $o) => $o->penalty_amount_minor !== null)
            ?? $candidates->first();
    }

    /**
     * The per-week unresolved-complaint penalty. Discovered from the same
     * framework as the acknowledgement obligation so no obligation
     * reference is hard-coded; a tenant may name one explicitly in
     * `settings.complaints.resolution_obligation_ref`.
     */
    public function resolutionObligation(Complaint $complaint, ?RegulatoryObligation $ackObligation = null): ?RegulatoryObligation
    {
        $tenant = Tenant::find($complaint->tenant_id);
        $ref = $tenant?->settings['complaints']['resolution_obligation_ref'] ?? null;

        $query = RegulatoryObligation::withoutGlobalScope('tenant')
            ->where(fn ($q) => $q->whereNull('tenant_id')
                ->when($complaint->tenant_id, fn ($w, $t) => $w->orWhere('tenant_id', $t)))
            ->active()
            ->effectiveOn(now()->toDateString());

        if ($ref) {
            return $query->where('obligation_ref', $ref)->first();
        }

        $ackObligation ??= $this->acknowledgementObligation($complaint);

        return $query
            ->where('penalty_basis', 'per_week')
            ->when($ackObligation?->framework_id, fn ($q, $id) => $q->where('framework_id', $id))
            ->when(
                $ackObligation && ! $ackObligation->framework_id,
                fn ($q) => $q->where('regulator_id', $ackObligation->regulator_id),
            )
            ->orderBy('id')
            ->first();
    }

    public function resolutionDays(?int $tenantId): int
    {
        $configured = Tenant::find($tenantId)?->settings['complaints']['resolution_days'] ?? null;

        return is_numeric($configured) ? (int) $configured : self::DEFAULT_RESOLUTION_DAYS;
    }

    // ── Analysis and returns (11.3) ──────────────────────────────────────

    /**
     * Complaints by root cause, with the failing control or process behind
     * each one — the view that turns a complaints desk into a control
     * feedback loop.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rootCauseAnalysis(array $filters = []): array
    {
        $rows = Complaint::query()
            ->whereNotNull('root_cause_id')
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('received_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('received_at', '<=', $to))
            ->when($filters['entity_id'] ?? null, fn ($q, $id) => $q->where('entity_id', $id))
            ->selectRaw('root_cause_id, linked_control_id, COUNT(*) as total, SUM(sla_breached) as breached')
            ->groupBy('root_cause_id', 'linked_control_id')
            ->get();

        $causes = ComplaintCategory::whereIn('id', $rows->pluck('root_cause_id')->unique())
            ->get(['id', 'name', 'code'])->keyBy('id');

        $controls = Control::whereIn('id', $rows->pluck('linked_control_id')->filter()->unique())
            ->get(['id', 'control_ref', 'title'])->keyBy('id');

        return $rows
            ->groupBy('root_cause_id')
            ->map(fn ($group, $causeId) => [
                'root_cause_id' => (int) $causeId,
                'root_cause' => $causes->get($causeId)?->name ?? 'Uncategorised',
                'total' => (int) $group->sum('total'),
                'sla_breached' => (int) $group->sum('breached'),
                'controls' => $group->pluck('linked_control_id')->filter()
                    ->map(fn ($id) => $controls->get($id)?->only(['id', 'control_ref', 'title']))
                    ->filter()->values()->all(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * CBN Consumer Protection Department return, as data. The renderer
     * (Excel/PDF) is a separate concern — this method owns the numbers.
     *
     * @return array<string, mixed>
     */
    public function cpdReturn(string $from, string $to, ?int $tenantId = null): array
    {
        $query = Complaint::query()
            ->when($tenantId, fn ($q, $t) => $q->withoutGlobalScope('tenant')->where('tenant_id', $t))
            ->whereBetween('received_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()]);

        $complaints = (clone $query)->with(['category:id,name,regulatory_code', 'rootCause:id,name'])->get();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'received' => $complaints->count(),
            'acknowledged_within_sla' => $complaints->filter(
                fn (Complaint $c) => $c->isAcknowledged() && ! $c->acknowledgementBreached()
            )->count(),
            'resolved' => $complaints->filter(fn (Complaint $c) => $c->isResolved())->count(),
            'outstanding' => $complaints->filter(fn (Complaint $c) => ! $c->isResolved())->count(),
            'escalated_to_regulator' => $complaints->where('escalated_to_regulator', true)->count(),
            'by_category' => $complaints->groupBy(fn (Complaint $c) => $c->category?->name ?? 'Uncategorised')
                ->map(fn ($group, $name) => [
                    'category' => $name,
                    'regulatory_code' => $group->first()->category?->regulatory_code,
                    'received' => $group->count(),
                    'resolved' => $group->filter(fn (Complaint $c) => $c->isResolved())->count(),
                    'upheld' => $group->whereIn('resolution_type', ['upheld', 'partially_upheld'])->count(),
                ])->values()->all(),
            'redress' => $complaints->whereNotNull('redress_currency')
                ->groupBy('redress_currency')
                ->map(fn ($group, $currency) => Money::of(
                    (int) $group->sum('redress_amount_minor'), $currency
                )->jsonSerialize())->all(),
            'penalty_exposure' => $this->exposureByCurrency($tenantId),
        ];
    }
}
