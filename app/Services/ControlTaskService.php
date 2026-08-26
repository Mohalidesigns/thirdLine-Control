<?php

namespace App\Services;

use App\Models\CheckItem;
use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlFrequency;
use App\Models\ControlUnit;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\TestScript;
use App\Models\User;
use App\Notifications\ControlTaskUnassignedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * CR-03 §E.1: the engine that turns "Frequency of Activity" from a note
 * in a spreadsheet column into work somebody owes by a date.
 *
 * Three generation modes, because the client's vocabulary has three
 * (§C.5):
 *  - scheduled  — the nightly run manufactures the instance;
 *  - event      — nothing is generated until a trigger fires, and the
 *                 instance records WHAT fired it;
 *  - continuous — one rolling instance per entity with no deadline, so
 *                 BRANCH AMBIENCE never sits in the overdue queue.
 *
 * And one control can carry more than one rhythm. Seven of the client's
 * functions do: NOSTRO is eleven daily lines and five monthly ones, so
 * it produces a daily instance holding its daily lines and a monthly
 * instance holding its monthly ones — one control, one checklist, two
 * rhythms, exactly as the bank wrote it (§C.2).
 */
class ControlTaskService
{
    public function __construct(
        private FrequencyResolver $frequencies,
        private NotificationDispatcher $dispatcher,
    ) {}

    // ── Scheduled generation ─────────────────────────────────────────

    /**
     * Generate every due control task across every tenant.
     *
     * @return array{created: int, skipped: int, unassigned: int}
     */
    public function generateAll(?CarbonImmutable $asOf = null, bool $dryRun = false): array
    {
        $totals = ['created' => 0, 'skipped' => 0, 'unassigned' => 0];

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $result = $this->generateForTenant((int) $tenantId, $asOf, $dryRun);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }
        }

        return $totals;
    }

    /**
     * @return array{created: int, skipped: int, unassigned: int}
     */
    public function generateForTenant(int $tenantId, ?CarbonImmutable $asOf = null, bool $dryRun = false): array
    {
        $asOf ??= CarbonImmutable::now();
        $totals = ['created' => 0, 'skipped' => 0, 'unassigned' => 0];

        Control::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'Active')
            ->where('is_template', false)
            ->where('is_control_function', true)
            ->with(['controlUnit', 'homeEntity', 'controlEntities'])
            // Chunked: at the client's branch count this walks 167
            // functions against a few hundred entities, and holding the
            // whole set in memory is not something a nightly job should do.
            ->chunkById(50, function (Collection $controls) use ($asOf, $dryRun, &$totals) {
                foreach ($controls as $control) {
                    $result = $this->generateForControl($control, $asOf, $dryRun);

                    foreach ($totals as $key => $value) {
                        $totals[$key] = $value + $result[$key];
                    }
                }
            });

        return $totals;
    }

    /**
     * @return array{created: int, skipped: int, unassigned: int}
     */
    public function generateForControl(Control $control, CarbonImmutable $asOf, bool $dryRun = false): array
    {
        $totals = ['created' => 0, 'skipped' => 0, 'unassigned' => 0];

        $script = $this->activeScript($control);

        if (! $script) {
            return $totals;
        }

        foreach ($this->rhythmsFor($control, $script) as $frequency) {
            // Event-driven waits for a trigger; continuous is rolled by
            // its own monthly job, not manufactured nightly.
            if (! $frequency->isScheduled()) {
                continue;
            }

            $period = $this->frequencies->period($frequency, $asOf);

            foreach ($this->targetsFor($control) as $entity) {
                $outcome = $this->ensureInstance($control, $script, $frequency, $entity, $period, $dryRun);

                $totals['created'] += $outcome['created'] ? 1 : 0;
                $totals['skipped'] += $outcome['created'] ? 0 : 1;
                // Unassigned counts a SUBSET of created, not an
                // alternative to it: the task exists either way, and
                // reporting it as "0 created, 316 unassigned" would tell
                // an operator nothing was written when 316 rows were.
                $totals['unassigned'] += $outcome['created'] && ! $outcome['assigned'] ? 1 : 0;
            }
        }

        return $totals;
    }

    /**
     * The distinct rhythms a control's checklist actually works to: its
     * own, plus every line-level override (§C.2).
     *
     * @return Collection<int, ControlFrequency>
     */
    public function rhythmsFor(Control $control, ?TestScript $script = null): Collection
    {
        $script ??= $this->activeScript($control);
        $base = $control->resolvedFrequency();

        $ids = $script
            ? $script->checkItems()->whereNotNull('frequency_id')->distinct()->pluck('frequency_id')
            : collect();

        $rhythms = collect();

        if ($base) {
            $rhythms->push($base);
        }

        foreach ($ids as $id) {
            $frequency = ControlFrequency::find($id);

            if ($frequency && ! $rhythms->contains('id', $frequency->id)) {
                $rhythms->push($frequency);
            }
        }

        return $rhythms;
    }

    /**
     * Which desks or branches this function is executed against. A
     * function attached to control entities produces one instance per
     * entity; one attached to none stays global, which is how every
     * pre-CR-03 control has always behaved.
     *
     * @return Collection<int, ?ControlEntity>
     */
    public function targetsFor(Control $control): Collection
    {
        $entities = $control->controlEntities
            ->where('is_active', true)
            ->values();

        if ($entities->isEmpty() && $control->homeEntity) {
            $entities = collect([$control->homeEntity]);
        }

        return $entities->isEmpty() ? collect([null]) : $entities;
    }

    /**
     * Idempotency lives here AND in the unique index. The index is the
     * one that matters: two workers running the nightly job at once will
     * both pass the exists() check, and only the database can arbitrate.
     *
     * @param  array{label: string, start: CarbonImmutable, end: CarbonImmutable, due: ?CarbonImmutable}  $period
     * @return array{created: bool, assigned: bool}
     */
    private function ensureInstance(
        Control $control, TestScript $script, ControlFrequency $frequency,
        ?ControlEntity $entity, array $period, bool $dryRun,
    ): array {
        $exists = TestInstance::withoutGlobalScopes()
            ->where('control_id', $control->id)
            ->where('scope_key', $entity ? 'e'.$entity->id : 'global')
            ->where('period_label', $period['label'])
            ->where('frequency_id', $frequency->id)
            ->exists();

        if ($exists) {
            return ['created' => false, 'assigned' => true];
        }

        [$tester, $reviewer] = $this->resolveAssignment($control, $entity);

        if ($dryRun) {
            return ['created' => true, 'assigned' => $tester !== null];
        }

        try {
            TestInstance::withoutGlobalScopes()->create([
                'tenant_id' => $control->tenant_id,
                'control_id' => $control->id,
                'control_entity_id' => $entity?->id,
                'test_script_id' => $script->id,
                'frequency_id' => $frequency->id,
                'reference' => TestInstance::nextReference('TSK'),
                'period_label' => $period['label'],
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'due_date' => $period['due'],
                'assigned_tester_id' => $tester?->id,
                'reviewer_id' => $reviewer?->id,
                'status' => 'Scheduled',
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another worker won the race. That is the index doing its job.
            return ['created' => false, 'assigned' => true];
        }

        if (! $tester) {
            $this->notifyUnassigned($control, $entity);
        }

        return ['created' => true, 'assigned' => $tester !== null];
    }

    // ── Assignment (§C.4) ────────────────────────────────────────────

    /**
     * Ownership resolves first-hit-wins: the desk's or branch's control
     * officer, then the entity's relationship officer, then the unit
     * head, then the control owner. Nothing resolved means unassigned
     * plus a notification — never a silently ownerless task.
     *
     * The reviewer is the unit head, EXCEPT where that would be the
     * tester: TestingService::review() refuses a self-review outright,
     * and pre-seating the same person as reviewer would only produce a
     * task that cannot be signed off. Leaving it null lets the existing
     * escalation ladder find the next level up.
     *
     * @return array{0: ?User, 1: ?User}
     */
    public function resolveAssignment(Control $control, ?ControlEntity $entity): array
    {
        $unit = $control->controlUnit ?? $entity?->controlUnit;

        $tester = $this->userById($entity?->default_officer_id)
            ?? $this->userById($entity?->owner_id)
            ?? $this->userById($unit?->head_user_id)
            ?? $this->userById($control->owner_id);

        $head = $this->userById($unit?->head_user_id);
        $reviewer = $head && $tester && $head->id === $tester->id ? null : $head;

        return [$tester, $reviewer];
    }

    private function userById(?int $id): ?User
    {
        return $id ? User::withoutGlobalScopes()->find($id) : null;
    }

    private function notifyUnassigned(Control $control, ?ControlEntity $entity): void
    {
        $unit = $control->controlUnit ?? $entity?->controlUnit;
        $head = $this->userById($unit?->head_user_id);

        if (! $head) {
            Log::warning('Control task generated with no assignee and no unit head to tell', [
                'control_id' => $control->id,
                'control_entity_id' => $entity?->id,
            ]);

            return;
        }

        try {
            $this->dispatcher->send(
                collect([$head]),
                'control.task.unassigned',
                new ControlTaskUnassignedNotification($control, $entity),
            );
        } catch (\Throwable $e) {
            // A notification failure must never abort a generation run.
            Log::warning('Unassigned control task notification failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Event-driven (§C.5) ──────────────────────────────────────────

    /**
     * Raise an occurrence of an event-driven function: "On request",
     * "As per sales by CBN", "Anytime there a new circular". The
     * instance records what triggered it, because an examiner asking why
     * a control fired on a Tuesday deserves an answer.
     */
    public function raiseEventInstance(
        Control $control, ?ControlEntity $entity = null,
        array $context = [], ?User $actor = null, ?CarbonImmutable $asOf = null,
    ): TestInstance {
        $asOf ??= CarbonImmutable::now();
        $frequency = $control->resolvedFrequency();

        if (! $frequency || ! $frequency->isEventDriven()) {
            throw ValidationException::withMessages([
                'control' => sprintf('%s is not an event-driven control function — it runs to a %s calendar.', $control->control_ref, $frequency?->label ?? $control->frequency),
            ]);
        }

        $script = $this->activeScript($control);

        if (! $script) {
            throw ValidationException::withMessages([
                'control' => sprintf('%s has no active checklist to execute.', $control->control_ref),
            ]);
        }

        $period = $this->frequencies->period($frequency, $asOf);
        [$tester, $reviewer] = $this->resolveAssignment($control, $entity);

        $instance = TestInstance::withoutGlobalScopes()->create([
            'tenant_id' => $control->tenant_id,
            'control_id' => $control->id,
            'control_entity_id' => $entity?->id,
            'test_script_id' => $script->id,
            'frequency_id' => $frequency->id,
            'reference' => TestInstance::nextReference('TSK'),
            'period_label' => $period['label'],
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'due_date' => $period['due'],
            'assigned_tester_id' => $tester?->id,
            'reviewer_id' => $reviewer?->id,
            'status' => 'Scheduled',
            'is_ad_hoc' => true,
            'trigger_event' => $frequency->trigger_event,
            'trigger_context' => $context ?: null,
        ]);

        $instance->auditAction('control-task-triggered', null, [
            'trigger' => $frequency->trigger_event,
            'by' => $actor?->name,
            'context' => $context,
        ]);

        return $instance;
    }

    /**
     * Fan a trigger out across every event-driven function that listens
     * for it — the hook the CBN circular feed calls when
     * atheris:poll-regulatory-feeds finds something new.
     *
     * @return Collection<int, TestInstance>
     */
    public function fireTrigger(int $tenantId, string $triggerEvent, array $context = [], ?CarbonImmutable $asOf = null): Collection
    {
        $frequencyIds = ControlFrequency::query()
            ->where('generation_mode', 'event')
            ->where('trigger_event', $triggerEvent)
            ->pluck('id');

        if ($frequencyIds->isEmpty()) {
            return collect();
        }

        $controls = Control::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'Active')
            ->where('is_control_function', true)
            ->whereIn('frequency_id', $frequencyIds)
            ->with(['controlUnit', 'homeEntity', 'controlEntities'])
            ->get();

        $raised = collect();

        foreach ($controls as $control) {
            foreach ($this->targetsFor($control) as $entity) {
                $raised->push($this->raiseEventInstance($control, $entity, $context, null, $asOf));
            }
        }

        return $raised;
    }

    // ── Continuous (§C.5) ────────────────────────────────────────────

    /**
     * Roll observation tasks into a fresh month. A continuous task never
     * closes on a period boundary — the officer records against it as
     * they walk the branch — so rolling means opening this month's
     * instance and closing last month's, not chasing an overdue one.
     *
     * @return array{opened: int, closed: int}
     */
    public function rollContinuous(?CarbonImmutable $asOf = null, bool $dryRun = false): array
    {
        $asOf ??= CarbonImmutable::now();
        $totals = ['opened' => 0, 'closed' => 0];

        $continuous = ControlFrequency::query()->where('generation_mode', 'continuous')->pluck('id');

        if ($continuous->isEmpty()) {
            return $totals;
        }

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $controls = Control::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('status', 'Active')
                ->where('is_control_function', true)
                ->where(fn ($q) => $q->whereIn('frequency_id', $continuous)
                    ->orWhereHas('testScripts.checkItems', fn ($i) => $i->whereIn('frequency_id', $continuous)))
                ->with(['controlUnit', 'homeEntity', 'controlEntities'])
                ->get();

            foreach ($controls as $control) {
                $script = $this->activeScript($control);

                if (! $script) {
                    continue;
                }

                foreach ($this->rhythmsFor($control, $script)->where('generation_mode', 'continuous') as $frequency) {
                    $period = $this->frequencies->period($frequency, $asOf);

                    foreach ($this->targetsFor($control) as $entity) {
                        $totals['closed'] += $this->closeSupersededObservations($control, $entity, $frequency, $period['label'], $dryRun);

                        if ($this->ensureInstance($control, $script, $frequency, $entity, $period, $dryRun)['created']) {
                            $totals['opened']++;
                        }
                    }
                }
            }
        }

        return $totals;
    }

    /**
     * Last month's observation is complete by definition — it recorded
     * what the officer saw while it was open. It closes rather than
     * going overdue.
     */
    private function closeSupersededObservations(Control $control, ?ControlEntity $entity, ControlFrequency $frequency, string $currentLabel, bool $dryRun): int
    {
        $query = TestInstance::withoutGlobalScopes()
            ->where('control_id', $control->id)
            ->where('scope_key', $entity ? 'e'.$entity->id : 'global')
            ->where('frequency_id', $frequency->id)
            ->where('period_label', '!=', $currentLabel)
            ->whereIn('status', ['Scheduled', 'In Progress']);

        if ($dryRun) {
            return $query->count();
        }

        $closed = 0;

        foreach ($query->get() as $instance) {
            $instance->forceFill(['status' => 'Closed'])->save();
            $instance->auditAction('observation-rolled', null, ['period' => $instance->period_label]);
            $closed++;
        }

        return $closed;
    }

    // ── Execution support ────────────────────────────────────────────

    /**
     * The checklist lines this instance is actually asking about: the
     * ones whose effective frequency is the instance's rhythm. This is
     * what makes NOSTRO's daily task show eleven lines and its monthly
     * task the other five.
     *
     * @return Collection<int, CheckItem>
     */
    public function checkItemsFor(TestInstance $instance): Collection
    {
        $script = $instance->testScript;

        if (! $script) {
            return collect();
        }

        $items = $script->checkItems()->orderBy('sequence')->get();

        // A pre-CR-03 instance has no rhythm of its own; it owns the
        // whole checklist, exactly as it did before this change.
        if (! $instance->frequency_id) {
            return $items;
        }

        $controlFrequencyId = $instance->control?->frequency_id;

        return $items
            ->filter(fn (CheckItem $item) => $item->effectiveFrequencyId($controlFrequencyId) === $instance->frequency_id)
            ->values();
    }

    // ── Reporting support ────────────────────────────────────────────

    /**
     * Completion by control unit for a window: what the Head of Internal
     * Control is mailed at 08:00 (§E.4 report 1). One grouped query, not
     * one per unit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function completionByUnit(int $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('test_instances')
            ->join('controls', 'controls.id', '=', 'test_instances.control_id')
            ->leftJoin('control_units', 'control_units.id', '=', 'controls.control_unit_id')
            ->where('test_instances.tenant_id', $tenantId)
            ->where('controls.is_control_function', true)
            ->whereNull('test_instances.deleted_at')
            // OVERLAP, not containment. A monthly period that began before
            // the window still belongs in it — filtering on period_start
            // alone drops every boundary month, which is exactly the
            // question an examiner asks about.
            //
            // whereDate, not a raw string compare: these columns are typed
            // `date` but hold full datetimes ('2026-05-14 00:00:00'), so
            // '2026-05-14 00:00:00' <= '2026-05-14' is false and a daily
            // period would silently drop out of its own window.
            ->whereDate('test_instances.period_start', '<=', $to->toDateString())
            ->whereDate('test_instances.period_end', '>=', $from->toDateString())
            ->groupBy('control_units.id', 'control_units.name')
            ->select([
                'control_units.id as unit_id',
                'control_units.name as unit_name',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when test_instances.status in ('Reviewed', 'Closed') then 1 else 0 end) as completed"),
                DB::raw("sum(case when test_instances.status not in ('Reviewed', 'Closed') and test_instances.due_date is not null and test_instances.due_date < '".now()->toDateString()."' then 1 else 0 end) as overdue"),
            ])
            ->get();

        return $rows->map(fn ($row) => [
            'unit_id' => $row->unit_id,
            'unit_name' => $row->unit_name ?? 'Unassigned',
            'total' => (int) $row->total,
            'completed' => (int) $row->completed,
            'overdue' => (int) $row->overdue,
            'completion_rate' => $row->total > 0 ? round(($row->completed / $row->total) * 100, 1) : 0.0,
        ])->all();
    }

    /**
     * §E.4 report 2 — expected vs actual instances per function over a
     * window. This is the report that proves a Daily control was
     * actually performed daily, and it is what an examiner asks for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function frequencyCompliance(int $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $controls = Control::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_control_function', true)
            ->where('status', 'Active')
            ->with(['controlFrequency', 'controlUnit', 'controlEntities'])
            ->get();

        $actual = DB::table('test_instances')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            // Overlap, and whereDate, for the same reasons as
            // completionByUnit() above.
            ->whereDate('period_start', '<=', $to->toDateString())
            ->whereDate('period_end', '>=', $from->toDateString())
            ->groupBy('control_id')
            ->select('control_id', DB::raw('count(*) as total'),
                DB::raw("sum(case when status in ('Reviewed', 'Closed') then 1 else 0 end) as completed"))
            ->get()
            ->keyBy('control_id');

        return $controls->map(function (Control $control) use ($from, $to, $actual) {
            $frequency = $control->resolvedFrequency();
            $targets = max(1, $this->targetsFor($control)->count());
            $expected = $frequency ? $this->expectedPeriods($frequency, $from, $to) * $targets : 0;
            $row = $actual->get($control->id);

            return [
                'control_id' => $control->id,
                'control_ref' => $control->control_ref,
                'title' => $control->title,
                'unit' => $control->controlUnit?->name,
                'frequency' => $frequency?->label,
                'frequency_raw' => $control->frequency_raw,
                'expected' => $expected,
                'actual' => (int) ($row->total ?? 0),
                'completed' => (int) ($row->completed ?? 0),
                'gap' => max(0, $expected - (int) ($row->total ?? 0)),
            ];
        })->values()->all();
    }

    /** How many periods of this rhythm fall inside a window. */
    public function expectedPeriods(ControlFrequency $frequency, CarbonImmutable $from, CarbonImmutable $to): int
    {
        // An event or observation rhythm has no expected count — nobody
        // can say a circular should have been published four times.
        if (! $frequency->isScheduled()) {
            return 0;
        }

        $days = max(1, $from->diffInDays($to) + 1);

        return (int) max(1, match ($frequency->cycle) {
            'daily' => $days,
            'weekly' => ceil($days / 7),
            'monthly' => ceil($days / 30.44),
            'quarterly' => ceil($days / 91.31),
            'semiannual' => ceil($days / 182.62),
            'annual' => ceil($days / 365.25),
            default => ceil($days / 30.44),
        });
    }

    // ── Structure hooks ──────────────────────────────────────────────

    /**
     * §D.2: a branch that opens today inherits all 73 branch functions
     * the same day, with no data duplication — the pivot gains a row per
     * function, and the checklist stays in one place.
     */
    public function attachBranchFunctions(ControlEntity $branch): int
    {
        if (! $branch->isBranch()) {
            return 0;
        }

        $functions = Control::withoutGlobalScopes()
            ->where('tenant_id', $branch->tenant_id)
            ->where('control_unit_id', $branch->control_unit_id)
            ->where('is_control_function', true)
            ->where('status', 'Active')
            ->whereNull('control_entity_id')
            ->pluck('id');

        $attached = 0;

        foreach ($functions as $controlId) {
            $exists = DB::table('control_entity_control')
                ->where('control_entity_id', $branch->id)
                ->where('control_id', $controlId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('control_entity_control')->insert([
                'tenant_id' => $branch->tenant_id,
                'control_entity_id' => $branch->id,
                'control_id' => $controlId,
                'is_key' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $attached++;
        }

        return $attached;
    }

    private function activeScript(Control $control): ?TestScript
    {
        return $control->testScripts()
            ->where('status', 'Active')
            ->orderByDesc('version_no')
            ->first();
    }

    /** Convenience for the catalogue page: the sub-units in play. */
    public function unitsWithFunctions(int $tenantId): Collection
    {
        return ControlUnit::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', Control::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_control_function', true)
                ->whereNotNull('control_unit_id')
                ->distinct()
                ->pluck('control_unit_id'))
            ->orderBy('sequence')
            ->get();
    }
}
