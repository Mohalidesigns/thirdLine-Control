<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\IncidentNotification;
use App\Models\OrganisationUnit;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns "something happened" into "who must be told, and by when" (11.2).
 *
 * Nothing regulatory lives in this class (R1). The windows come from the
 * seeded obligation records' due_rule — a 72-hour NDPC breach notification
 * is `{"type":"event_relative","event":"breach_detected","offset_hours":72}`
 * on the NDPA-GAID pack, not a constant here. Edit that record and every
 * open countdown moves, which is exactly what the phase brief asks for and
 * what NotificationObligationTest asserts.
 *
 * The only thing configured in PHP is the map from *our* incident taxonomy
 * to the event keys the obligation records use, and even that is a default
 * that `tenants.settings['incidents']['event_map']` overrides — the same
 * pattern MetricService uses for KRI breach behaviour.
 */
class NotificationObligationResolver
{
    /**
     * Default incident_type → obligation event keys.
     *
     * A type may map to several events: a cyber incident that exposes
     * personal data owes both the sector regulator and the data-protection
     * authority. Over-notifying is the stricter compliance reading, so an
     * ambiguous type maps to every plausible event rather than none.
     *
     * @var array<string, array<int, string>>
     */
    public const DEFAULT_EVENT_MAP = [
        'data_breach' => ['breach_detected'],
        'cyber' => ['cyber_incident_detected', 'breach_detected'],
        'security' => ['cyber_incident_detected', 'breach_detected'],
        'fraud' => ['suspicion_formed'],
        'operational' => [],
        'compliance' => [],
        'health_safety' => [],
        'conduct' => ['suspicion_formed'],
        'third_party' => ['cyber_incident_detected'],
        'continuity' => [],
        'other' => [],
    ];

    public function __construct(private ObligationService $obligations) {}

    // ── Incidents ────────────────────────────────────────────────────────

    /** @return array<int, string> */
    public function eventsFor(Incident $incident): array
    {
        $map = $this->eventMap($incident->tenant_id);

        return array_values(array_unique($map[$incident->incident_type] ?? []));
    }

    /**
     * Materialise this incident's notification duties, idempotently.
     *
     * Pending rows are refreshed in place (a changed obligation moves the
     * countdown); rows that no longer apply are dropped only while still
     * Pending — a notification already made is a historical fact and stays.
     */
    public function resolve(Incident $incident): Collection
    {
        $eventAt = $incident->notificationEventAt();
        $entity = $this->entityFor($incident->entity, $incident->tenant_id);
        $obligations = $this->obligationsFor($this->eventsFor($incident), $entity, $incident->tenant_id);

        $keep = [];

        foreach ($obligations as $obligation) {
            $dueAt = $eventAt
                ? $this->obligations->resolveDueDate($obligation, $entity, $this->nullPeriod($eventAt), $eventAt)
                : null;

            $notification = IncidentNotification::withoutGlobalScope('tenant')
                ->firstOrNew([
                    'incident_id' => $incident->id,
                    'obligation_id' => $obligation->id,
                ]);

            // Never rewrite a notification that has already been made.
            if ($notification->exists && $notification->status !== 'Pending') {
                $keep[] = $notification->id;

                continue;
            }

            $notification->fill([
                'tenant_id' => $incident->tenant_id,
                'regulator_id' => $obligation->regulator_id,
                'entity_id' => $incident->entity_id,
                'due_at' => $dueAt,
                'status' => 'Pending',
                'is_required' => true,
                'verification_status' => $obligation->verification_status ?? 'unverified',
            ])->save();

            $keep[] = $notification->id;
        }

        IncidentNotification::withoutGlobalScope('tenant')
            ->where('incident_id', $incident->id)
            ->where('status', 'Pending')
            ->whereNotIn('id', $keep ?: [0])
            ->delete();

        $notifications = IncidentNotification::withoutGlobalScope('tenant')
            ->where('incident_id', $incident->id)
            ->with(['obligation:id,obligation_ref,title,verification_status,legal_reference,due_rule', 'regulator:id,code,name'])
            ->orderBy('due_at')
            ->get();

        // The incident's own summary columns mirror the strictest open duty
        // so list pages and the API can sort on one indexed column.
        $earliest = $notifications->where('status', 'Pending')->whereNotNull('due_at')->min('due_at');

        $incident->updateQuietly([
            'is_reportable' => $notifications->where('is_required', true)->isNotEmpty(),
            'notification_due_at' => $earliest,
            'regulator_ids' => $notifications->pluck('regulator_id')->filter()->unique()->values()->all(),
        ]);

        return $notifications;
    }

    // ── Any event-triggered obligation (complaints reuse this) ───────────

    /**
     * The strictest deadline any obligation imposes for a named event —
     * used by the complaint acknowledgement clock (11.3), which reads the
     * 24-hour window from the CBN-CPF record rather than owning it.
     *
     * @return array{obligation: RegulatoryObligation, due_at: Carbon}|null
     */
    public function strictestDeadlineFor(
        string $event,
        ?OrganisationUnit $entity,
        Carbon $eventAt,
        ?int $tenantId = null,
    ): ?array {
        $resolved = $this->entityFor($entity, $tenantId);
        $best = null;

        foreach ($this->obligationsFor([$event], $resolved, $tenantId) as $obligation) {
            $dueAt = $this->obligations->resolveDueDate($obligation, $resolved, $this->nullPeriod($eventAt), $eventAt);

            if ($dueAt && ($best === null || $dueAt->lessThan($best['due_at']))) {
                $best = ['obligation' => $obligation, 'due_at' => $dueAt];
            }
        }

        return $best;
    }

    /**
     * Event-triggered notification obligations matching any of the given
     * event keys, filtered to what actually binds the entity.
     *
     * @param  array<int, string>  $events
     * @return Collection<int, RegulatoryObligation>
     */
    public function obligationsFor(array $events, ?OrganisationUnit $entity, ?int $tenantId = null): Collection
    {
        if ($events === []) {
            return collect();
        }

        $candidates = RegulatoryObligation::withoutGlobalScope('tenant')
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($w, $t) => $w->orWhere('tenant_id', $t)))
            ->active()
            ->effectiveOn(now()->toDateString())
            ->where('trigger_type', 'event')
            ->with('regulator')
            ->get()
            ->filter(fn (RegulatoryObligation $o) => in_array(($o->due_rule['event'] ?? null), $events, true));

        // Applicability can only be judged against a real regulatory profile.
        // Without one we keep every match: over-notifying is the stricter
        // reading, and the UI says applicability was not confirmed.
        if ($entity === null || ! $entity->exists) {
            return $candidates->values();
        }

        return $candidates
            ->filter(fn (RegulatoryObligation $o) => $this->obligations->appliesTo($o, $entity))
            ->values();
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** @return array<string, array<int, string>> */
    private function eventMap(?int $tenantId): array
    {
        $configured = Tenant::find($tenantId)?->settings['incidents']['event_map'] ?? null;

        return is_array($configured) && $configured !== []
            ? array_map(fn ($events) => (array) $events, $configured)
            : self::DEFAULT_EVENT_MAP;
    }

    /**
     * Event-relative rules ignore the reporting period, but resolveDueDate
     * takes one; this supplies a well-formed placeholder rather than a
     * partially built array the resolver would have to guess about.
     *
     * @return array{label: string, start: Carbon, end: Carbon}
     */
    private function nullPeriod(Carbon $eventAt): array
    {
        return [
            'label' => 'Event',
            'start' => $eventAt->copy()->startOfDay(),
            'end' => $eventAt->copy()->endOfDay(),
        ];
    }

    /**
     * The entity whose calendar, timezone and regulatory profile drive the
     * deadline. Falls back to the tenant's first legal entity, then to a
     * transient stand-in that still carries the tenant's timezone.
     */
    private function entityFor(?OrganisationUnit $entity, ?int $tenantId): ?OrganisationUnit
    {
        if ($entity) {
            return $entity;
        }

        $tenantId ??= auth()->user()?->tenant_id;

        $legalEntity = OrganisationUnit::withoutGlobalScope('tenant')
            ->when($tenantId, fn ($q, $t) => $q->where('tenant_id', $t))
            ->where('is_legal_entity', true)
            ->orderBy('id')
            ->first();

        if ($legalEntity) {
            return $legalEntity;
        }

        if (! $tenantId) {
            return null;
        }

        $stand = new OrganisationUnit(['tenant_id' => $tenantId]);
        $stand->tenant_id = $tenantId;
        $stand->setRelation('tenant', Tenant::find($tenantId));

        return $stand;
    }
}
