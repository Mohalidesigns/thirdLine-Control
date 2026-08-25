<?php

namespace App\Services;

use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlException;
use App\Models\ControlStakeholder;
use App\Models\ControlUnit;
use App\Models\OrganisationUnit;
use App\Models\TestInstance;
use App\Models\User;
use App\Notifications\ControlStakeholderAddedNotification;
use App\Notifications\SharedControlExceptionRaisedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * CR2-A: the Internal Control structure — sub-units, the control-entity
 * register under each, branch auto-provisioning from the org tree,
 * control attachment and cross-functional stakeholders.
 *
 * Two invariants live here and nowhere else:
 *  R-A  a branch control entity always bridges an organisation_unit —
 *       Branch Control DERIVES branches from the operational tree;
 *  R-D  one owner per control, and the owner stakeholder row always
 *       agrees with controls.unit_id.
 */
class ControlStructureService
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
        private ControlTaskService $controlTasks,
    ) {}

    // ── Units ────────────────────────────────────────────────────────

    public function createUnit(array $data, User $actor): ControlUnit
    {
        $unit = ControlUnit::create($data);

        $unit->auditAction('control-unit-created', null, ['code' => $unit->code, 'name' => $unit->name]);

        return $unit;
    }

    public function updateUnit(ControlUnit $unit, array $data): ControlUnit
    {
        $before = $unit->only(array_keys($data));
        $unit->update($data);
        $unit->auditAction('control-unit-updated', $before, $unit->only(array_keys($data)));

        return $unit;
    }

    // ── Entities ─────────────────────────────────────────────────────

    public function createEntity(array $data, User $actor): ControlEntity
    {
        $this->assertBranchBridged($data);

        $entity = ControlEntity::create([
            ...$data,
            'reference' => $this->nextEntityReference($actor->tenant_id),
        ]);

        $entity->auditAction('control-entity-created', null, ['name' => $entity->name, 'entity_kind' => $entity->entity_kind]);

        return $entity;
    }

    public function updateEntity(ControlEntity $entity, array $data): ControlEntity
    {
        $this->assertBranchBridged([...$entity->only(['entity_kind', 'organisation_unit_id']), ...$data]);

        $before = $entity->only(array_keys($data));
        $entity->update($data);
        $entity->auditAction('control-entity-updated', $before, $entity->only(array_keys($data)));

        return $entity;
    }

    public function deactivateEntity(ControlEntity $entity, User $actor): ControlEntity
    {
        $entity->update(['is_active' => false]);
        $entity->auditAction('control-entity-deactivated', null, ['by' => $actor->name]);

        return $entity;
    }

    /** R-A: a branch row with no operational-tree bridge is a data error. */
    private function assertBranchBridged(array $data): void
    {
        if (($data['entity_kind'] ?? null) === 'branch' && empty($data['organisation_unit_id'])) {
            throw ValidationException::withMessages([
                'organisation_unit_id' => 'A branch control entity derives from the organisation tree — pick the branch unit it bridges.',
            ]);
        }
    }

    // ── Attaching controls (CR2A.3) ──────────────────────────────────

    /**
     * Attach controls to an entity, singly or in bulk. Existing
     * attachments keep their is_key flag unless re-specified.
     *
     * @param  array<int, array{control_id: int, is_key?: bool}>  $attachments
     * @return int newly attached count
     */
    public function attachControls(ControlEntity $entity, array $attachments, User $actor): int
    {
        $ids = array_column($attachments, 'control_id');

        $valid = Control::query()
            ->whereIn('id', $ids)
            ->where('tenant_id', $entity->tenant_id)
            ->pluck('id')
            ->all();

        $attached = 0;

        foreach ($attachments as $attachment) {
            if (! in_array($attachment['control_id'], $valid, true)) {
                continue;
            }

            $fresh = ! $entity->controls()->where('controls.id', $attachment['control_id'])->exists();

            $entity->controls()->syncWithoutDetaching([
                $attachment['control_id'] => [
                    'tenant_id' => $entity->tenant_id,
                    'is_key' => (bool) ($attachment['is_key'] ?? false),
                ],
            ]);

            $attached += $fresh ? 1 : 0;
        }

        if ($attached > 0) {
            $entity->auditAction('controls-attached', null, [
                'count' => $attached, 'by' => $actor->name,
            ]);
        }

        return $attached;
    }

    /**
     * Detach is allowed only while the entity has no open exception or
     * in-flight test against that control through this pivot — otherwise
     * deactivate the entity instead of rewriting history.
     */
    public function detachControl(ControlEntity $entity, Control $control, User $actor): void
    {
        $openExceptions = ControlException::query()
            ->where('control_id', $control->id)
            ->open()
            ->count();

        $inFlightTests = TestInstance::query()
            ->where('control_id', $control->id)
            ->whereNotIn('status', ['Reviewed', 'Closed'])
            ->count();

        if ($openExceptions > 0 || $inFlightTests > 0) {
            throw ValidationException::withMessages([
                'control' => sprintf(
                    '%s cannot be detached from %s while it has %d open exception(s) and %d test(s) in flight — resolve them, or deactivate the entity.',
                    $control->control_ref, $entity->name, $openExceptions, $inFlightTests,
                ),
            ]);
        }

        $entity->controls()->detach($control->id);

        $entity->auditAction('control-detached', ['control_ref' => $control->control_ref], ['by' => $actor->name]);
    }

    // ── Cross-functional stakeholders (CR2A.4) ───────────────────────

    public function addStakeholder(Control $control, array $data, User $actor): ControlStakeholder
    {
        $this->assertOwnerRules($control, $data, null);

        $stakeholder = ControlStakeholder::create([
            ...$data,
            'control_id' => $control->id,
            'tenant_id' => $control->tenant_id,
        ]);

        $control->auditAction('stakeholder-added', null, [
            'unit' => $stakeholder->organisationUnit?->name,
            'role' => $stakeholder->role,
            'by' => $actor->name,
        ]);

        $this->notifyStakeholderAdded($stakeholder);

        return $stakeholder;
    }

    public function removeStakeholder(ControlStakeholder $stakeholder, User $actor): void
    {
        if ($stakeholder->role === 'owner') {
            throw ValidationException::withMessages([
                'role' => 'The owner row mirrors the control\'s owning unit — reassign the control instead of removing it.',
            ]);
        }

        $control = $stakeholder->control;
        $unitName = $stakeholder->organisationUnit?->name;

        $stakeholder->delete();

        $control?->auditAction('stakeholder-removed', ['unit' => $unitName], ['by' => $actor->name]);
    }

    /**
     * R-D: at most one role=owner row per control, and it must agree with
     * controls.unit_id — the canonical single owner stays on the control.
     */
    private function assertOwnerRules(Control $control, array $data, ?ControlStakeholder $ignore): void
    {
        if (($data['role'] ?? null) !== 'owner') {
            return;
        }

        $existingOwner = $control->stakeholders()
            ->where('role', 'owner')
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore->id))
            ->exists();

        if ($existingOwner) {
            throw ValidationException::withMessages([
                'role' => 'This control already has an owner stakeholder — a control has exactly one owning unit.',
            ]);
        }

        if ((int) ($data['organisation_unit_id'] ?? 0) !== (int) $control->unit_id) {
            throw ValidationException::withMessages([
                'role' => 'The owner stakeholder must be the control\'s own unit — change the control\'s unit first if ownership is moving.',
            ]);
        }
    }

    private function notifyStakeholderAdded(ControlStakeholder $stakeholder): void
    {
        $recipients = collect([$stakeholder->contact, $stakeholder->organisationUnit?->head])
            ->filter()
            ->unique('id');

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            $this->dispatcher->send(
                $recipients,
                'control.stakeholder.added',
                new ControlStakeholderAddedNotification($stakeholder),
            );
        } catch (\Throwable $e) {
            Log::warning('Stakeholder-added notification failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * CR2A.4: an exception on a shared control tells the co-owner units —
     * their heads, and the named contact where one is set. Called by
     * ExceptionService at raise time; failure never blocks the raise.
     */
    public function notifySharedExceptionRaised(ControlException $exception): void
    {
        if (! $exception->control_id) {
            return;
        }

        try {
            $coOwners = ControlStakeholder::withoutGlobalScope('tenant')
                ->where('tenant_id', $exception->tenant_id)
                ->where('control_id', $exception->control_id)
                ->where('role', 'co_owner')
                ->with(['organisationUnit.head', 'contact'])
                ->get();

            $recipients = $coOwners
                ->flatMap(fn (ControlStakeholder $row) => [$row->organisationUnit?->head, $row->contact])
                ->filter()
                ->unique('id');

            if ($recipients->isEmpty()) {
                return;
            }

            $this->dispatcher->send(
                $recipients,
                'control.shared.exception_raised',
                new SharedControlExceptionRaisedNotification($exception),
            );
        } catch (\Throwable $e) {
            Log::warning('Shared-control exception notification failed', [
                'exception_id' => $exception->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── Branch auto-provisioning (CR2A.2) ────────────────────────────

    /**
     * Idempotent, add-only sync: every organisation_unit of type Branch
     * gets a control entity under the branch-domain unit, and every
     * ACTIVE template activity is instantiated (copy-on-write) under each
     * branch. A second run creates nothing; deleting or editing a
     * template never touches instantiated rows (R-C).
     *
     * CR-03 §D.2: a newly provisioned branch also inherits the branch
     * function set — 73 checklists on the day it opens, attached through
     * the pivot rather than copied, so a checklist edit still has exactly
     * one place to happen.
     *
     * @return array{branches: int, activities: int, functions: int}
     */
    public function syncBranches(int $tenantId): array
    {
        $branchUnit = ControlUnit::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('domain', 'branch')
            ->where('is_active', true)
            ->orderBy('sequence')
            ->first();

        if (! $branchUnit) {
            return ['branches' => 0, 'activities' => 0, 'functions' => 0];
        }

        $branchOrgUnits = OrganisationUnit::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('type', 'Branch')
            ->orderBy('name')
            ->get();

        $templates = ControlEntity::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('control_unit_id', $branchUnit->id)
            ->where('is_template', true)
            ->where('is_active', true)
            ->where('entity_kind', 'activity')
            ->orderBy('sequence')
            ->get();

        $created = ['branches' => 0, 'activities' => 0, 'functions' => 0];

        foreach ($branchOrgUnits as $orgUnit) {
            $branchEntity = $this->ensureBranchEntity($branchUnit, $orgUnit, $created);
            $this->ensureBranchActivities($branchEntity, $templates, $created);
            $created['functions'] += $this->controlTasks->attachBranchFunctions($branchEntity);
        }

        return $created;
    }

    /** Observer path: a newly created Branch appears the same day (CR2A.2). */
    public function provisionBranch(OrganisationUnit $orgUnit): ?ControlEntity
    {
        if ($orgUnit->type !== 'Branch') {
            return null;
        }

        $branchUnit = ControlUnit::withoutGlobalScope('tenant')
            ->where('tenant_id', $orgUnit->tenant_id)
            ->where('domain', 'branch')
            ->where('is_active', true)
            ->orderBy('sequence')
            ->first();

        if (! $branchUnit) {
            return null;
        }

        $created = ['branches' => 0, 'activities' => 0, 'functions' => 0];
        $branchEntity = $this->ensureBranchEntity($branchUnit, $orgUnit, $created);

        $templates = ControlEntity::withoutGlobalScope('tenant')
            ->where('tenant_id', $orgUnit->tenant_id)
            ->where('control_unit_id', $branchUnit->id)
            ->where('is_template', true)
            ->where('is_active', true)
            ->where('entity_kind', 'activity')
            ->orderBy('sequence')
            ->get();

        $this->ensureBranchActivities($branchEntity, $templates, $created);
        $this->controlTasks->attachBranchFunctions($branchEntity);

        return $branchEntity;
    }

    private function ensureBranchEntity(ControlUnit $branchUnit, OrganisationUnit $orgUnit, array &$created): ControlEntity
    {
        $existing = ControlEntity::withoutGlobalScope('tenant')
            ->where('tenant_id', $branchUnit->tenant_id)
            ->where('control_unit_id', $branchUnit->id)
            ->where('entity_kind', 'branch')
            ->where('organisation_unit_id', $orgUnit->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $created['branches']++;

        return ControlEntity::withoutGlobalScope('tenant')->create([
            'tenant_id' => $branchUnit->tenant_id,
            'control_unit_id' => $branchUnit->id,
            'reference' => $this->nextEntityReference($branchUnit->tenant_id),
            'name' => $orgUnit->name,
            'entity_kind' => 'branch',
            'organisation_unit_id' => $orgUnit->id,
            'is_active' => true,
        ]);
    }

    /**
     * @param  Collection<int, ControlEntity>  $templates
     */
    private function ensureBranchActivities(ControlEntity $branchEntity, Collection $templates, array &$created): void
    {
        $existingNames = ControlEntity::withoutGlobalScope('tenant')
            ->where('tenant_id', $branchEntity->tenant_id)
            ->where('parent_id', $branchEntity->id)
            ->pluck('name')
            ->all();

        foreach ($templates as $template) {
            if (in_array($template->name, $existingNames, true)) {
                continue;
            }

            // Copy-on-write: the instantiated row is independent of the
            // template — later template edits never reach it (R-C).
            ControlEntity::withoutGlobalScope('tenant')->create([
                'tenant_id' => $branchEntity->tenant_id,
                'control_unit_id' => $branchEntity->control_unit_id,
                'parent_id' => $branchEntity->id,
                'reference' => $this->nextEntityReference($branchEntity->tenant_id),
                'name' => $template->name,
                'description' => $template->description,
                'entity_kind' => 'activity',
                'organisation_unit_id' => $branchEntity->organisation_unit_id,
                'risk_rating' => $template->risk_rating,
                'review_frequency' => $template->review_frequency,
                'sequence' => $template->sequence,
                'is_template' => false,
                'is_active' => true,
            ]);

            $created['activities']++;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Tenant-explicit reference generation: the sync command and the
     * seeder run without an authenticated user, so GeneratesReference's
     * auth()-scoped counter cannot be relied on here.
     */
    public function nextEntityReference(int $tenantId): string
    {
        $last = ControlEntity::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('reference', 'like', 'CE-%')
            ->orderByDesc('id')
            ->value('reference');

        $next = $last ? ((int) substr($last, 3)) + 1 : 1;

        return 'CE-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Counts for the three sub-unit cards (CR2A.5): entities, attached
     * controls, open exceptions and overdue reviews per unit, computed as
     * grouped queries — never one query per unit.
     *
     * @return array<int, array<string, int>> keyed by control_unit_id
     */
    public function unitCounts(): array
    {
        $entities = ControlEntity::query()->active()
            ->select('control_unit_id', DB::raw('count(*) as total'))
            ->groupBy('control_unit_id')
            ->pluck('total', 'control_unit_id');

        $controls = DB::table('control_entity_control')
            ->join('control_entities', 'control_entities.id', '=', 'control_entity_control.control_entity_id')
            ->where('control_entities.tenant_id', auth()->user()?->tenant_id)
            ->where('control_entities.is_active', true)
            ->select('control_entities.control_unit_id', DB::raw('count(distinct control_entity_control.control_id) as total'))
            ->groupBy('control_entities.control_unit_id')
            ->pluck('total', 'control_unit_id');

        $exceptions = ControlException::query()->open()
            ->join('control_entity_control', 'control_entity_control.control_id', '=', 'control_exceptions.control_id')
            ->join('control_entities', 'control_entities.id', '=', 'control_entity_control.control_entity_id')
            ->where('control_entities.is_active', true)
            ->select('control_entities.control_unit_id', DB::raw('count(distinct control_exceptions.id) as total'))
            ->groupBy('control_entities.control_unit_id')
            ->pluck('total', 'control_unit_id');

        $overdue = ControlEntity::query()->active()
            ->whereNotNull('next_review_due_at')
            ->whereDate('next_review_due_at', '<', now())
            ->select('control_unit_id', DB::raw('count(*) as total'))
            ->groupBy('control_unit_id')
            ->pluck('total', 'control_unit_id');

        $counts = [];

        foreach (ControlUnit::query()->active()->pluck('id') as $unitId) {
            $counts[$unitId] = [
                'entities' => (int) ($entities[$unitId] ?? 0),
                'controls' => (int) ($controls[$unitId] ?? 0),
                'open_exceptions' => (int) ($exceptions[$unitId] ?? 0),
                'overdue_reviews' => (int) ($overdue[$unitId] ?? 0),
            ];
        }

        return $counts;
    }
}
