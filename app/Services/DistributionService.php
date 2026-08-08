<?php

namespace App\Services;

use App\Models\Control;
use App\Models\ControlDistribution;
use App\Models\DistributionTask;
use App\Models\OrganisationUnit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Group→entity control distribution (Phase 9.1/9.2). A group control is the
 * master definition; distribution creates one child control per entity that
 * inherits the definition but carries its own owner, frequency, testing and
 * rating. Changing the group opens a propagation workflow that never
 * silently overwrites an entity's local adaptation.
 */
class DistributionService
{
    /** Fields an entity child inherits from the group master. */
    private const INHERITED_FIELDS = [
        'title', 'description', 'objective', 'type', 'nature', 'category_id',
        'coso_component', 'frequency', 'test_frequency', 'is_key_control',
        'function_grouping', 'control_level', 'control_documentation',
    ];

    /**
     * Distribute a group control to entities. Idempotent: an entity that
     * already holds a distribution of this control is skipped, never
     * duplicated.
     *
     * @param  array<int, int>  $entityIds
     * @return Collection<int, ControlDistribution>
     */
    public function distribute(Control $control, array $entityIds, User $user, array $options = []): Collection
    {
        if (! $control->isGroupControl() || ! $control->is_distributable) {
            throw ValidationException::withMessages([
                'control' => 'Only a distributable group-level control can be distributed to entities.',
            ]);
        }

        if ($control->status !== 'Active') {
            throw ValidationException::withMessages([
                'control' => 'A control must be Active and approved before it is distributed.',
            ]);
        }

        return DB::transaction(function () use ($control, $entityIds, $user, $options) {
            $entities = OrganisationUnit::whereIn('id', $entityIds)->get();
            $created = collect();

            foreach ($entities as $entity) {
                $existing = ControlDistribution::where('control_id', $control->id)
                    ->where('entity_id', $entity->id)
                    ->first();

                if ($existing) {
                    $created->push($existing);

                    continue;
                }

                $ownerId = $options['owner_ids'][$entity->id] ?? $entity->head_user_id;

                $child = $this->createEntityInstance($control, $entity, $ownerId, $user);

                $distribution = ControlDistribution::create([
                    'tenant_id' => $control->tenant_id,
                    'control_id' => $control->id,
                    'entity_id' => $entity->id,
                    'child_control_id' => $child->id,
                    'assigned_owner_id' => $ownerId,
                    'distributed_by' => $user->id,
                    'distributed_at' => now(),
                    'due_at' => $options['due_at'] ?? null,
                    'status' => 'Pending',
                ]);

                foreach ($options['tasks'] ?? [] as $index => $task) {
                    $distribution->tasks()->create([
                        'title' => $task['title'],
                        'description' => $task['description'] ?? null,
                        'sequence' => $index,
                        'owner_id' => $ownerId,
                        'due_at' => $task['due_at'] ?? $options['due_at'] ?? null,
                        'evidence_required' => (bool) ($task['evidence_required'] ?? false),
                    ]);
                }

                $control->auditAction('distributed', null, [
                    'entity_id' => $entity->id,
                    'child_control_id' => $child->id,
                ]);

                $created->push($distribution);
            }

            return $created;
        });
    }

    private function createEntityInstance(Control $group, OrganisationUnit $entity, ?int $ownerId, User $user): Control
    {
        $child = Control::create([
            ...$group->only(self::INHERITED_FIELDS),
            'is_key_control' => (bool) $group->is_key_control,
            'tenant_id' => $group->tenant_id,
            'control_ref' => Control::nextReference('CTL', false, 'control_ref'),
            'library_level' => 'entity',
            'parent_control_id' => $group->id,
            'unit_id' => $entity->id,
            'owner_id' => $ownerId,
            'implementation_status' => 'Not Started',
            'implementation_progress' => 0,
            // A distributed instance inherits an approved definition, so it
            // starts life Active — its testing and rating are its own.
            'status' => 'Active',
            'current_version' => 1,
            'created_by' => $user->id,
            'approved_by' => $group->approved_by,
            'approved_at' => $group->approved_at,
        ]);

        $child->versions()->create([
            'version_no' => 1,
            'payload' => $child->only([
                'title', 'description', 'objective', 'type', 'nature', 'category_id',
                'coso_component', 'process_id', 'unit_id', 'department', 'owner_id',
                'frequency', 'test_frequency', 'is_key_control',
                'framework_refs', 'control_documentation', 'notes', 'status',
            ]),
            'effective_from' => now()->toDateString(),
            'changed_by' => $user->id,
            'change_reason' => "Distributed from group control {$group->control_ref}",
        ]);

        return $child;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────

    public function acknowledge(ControlDistribution $distribution, User $user): ControlDistribution
    {
        $this->assertStatus($distribution, ['Pending'], 'acknowledged');

        $distribution->update(['status' => 'Acknowledged', 'acknowledged_at' => now()]);
        $distribution->auditAction('acknowledged');

        return $distribution;
    }

    public function updateTask(DistributionTask $task, array $data, User $user): DistributionTask
    {
        $distribution = $task->distribution;

        if (in_array($distribution->status, ['Declined', 'Completed'], true)) {
            throw ValidationException::withMessages([
                'task' => 'Tasks on a completed or declined distribution can no longer change.',
            ]);
        }

        return DB::transaction(function () use ($task, $data, $user, $distribution) {
            $status = $data['status'] ?? $task->status;

            $task->update([
                'status' => $status,
                'blocker_reason' => $status === 'Blocked' ? ($data['blocker_reason'] ?? null) : null,
                'completed_at' => $status === 'Complete' ? now() : null,
                'completed_by' => $status === 'Complete' ? $user->id : null,
            ]);

            $this->refreshProgress($distribution->fresh());

            return $task;
        });
    }

    /**
     * Progress = share of complete tasks; the distribution and its entity
     * control move state with it so the Corporater-style overview reads
     * straight off the rows.
     */
    public function refreshProgress(ControlDistribution $distribution): void
    {
        $tasks = $distribution->tasks()->get();

        $progress = $tasks->isEmpty()
            ? $distribution->progress
            : (int) round($tasks->where('status', 'Complete')->count() / $tasks->count() * 100);

        $allComplete = $tasks->isNotEmpty() && $tasks->every(fn ($t) => $t->status === 'Complete');
        $anyStarted = $tasks->contains(fn ($t) => $t->status !== 'Open');

        $status = match (true) {
            $allComplete => 'Completed',
            $anyStarted && in_array($distribution->status, ['Acknowledged', 'In Progress'], true) => 'In Progress',
            default => $distribution->status,
        };

        $distribution->update([
            'progress' => $progress,
            'status' => $status,
            'completed_at' => $allComplete ? ($distribution->completed_at ?? now()) : null,
        ]);

        $distribution->childControl?->update([
            'implementation_progress' => $progress,
            'implementation_status' => match (true) {
                $allComplete => 'Implemented',
                $progress > 0 => 'In Progress',
                default => 'Not Started',
            },
        ]);
    }

    /**
     * An entity may decline only with a reason; the decline takes effect
     * only once a Control Function Head approves it.
     */
    public function requestDecline(ControlDistribution $distribution, string $reason, User $user): ControlDistribution
    {
        $this->assertStatus($distribution, ['Pending', 'Acknowledged', 'In Progress'], 'declined');

        $distribution->update(['status' => 'Decline Requested', 'decline_reason' => $reason]);
        $distribution->auditAction('decline_requested', null, ['reason' => $reason, 'by' => $user->id]);

        return $distribution;
    }

    public function approveDecline(ControlDistribution $distribution, User $approver): ControlDistribution
    {
        $this->assertStatus($distribution, ['Decline Requested'], 'decline-approved');

        // SoD: the entity owner who asked to decline cannot approve it.
        if ($distribution->assigned_owner_id === $approver->id) {
            throw ValidationException::withMessages([
                'decline' => 'A decline cannot be approved by the owner who requested it.',
            ]);
        }

        return DB::transaction(function () use ($distribution, $approver) {
            $distribution->update([
                'status' => 'Declined',
                'decline_approved_by' => $approver->id,
                'decline_approved_at' => now(),
            ]);

            // The entity instance is retired but the distribution row stays:
            // a declined control still shows in coverage reporting as a gap.
            $distribution->childControl?->update([
                'status' => 'Retired',
                'implementation_status' => 'Not Applicable',
            ]);

            $distribution->auditAction('decline_approved', null, ['by' => $approver->id]);

            return $distribution;
        });
    }

    public function rejectDecline(ControlDistribution $distribution, User $approver, ?string $note = null): ControlDistribution
    {
        $this->assertStatus($distribution, ['Decline Requested'], 'decline-rejected');

        $distribution->update([
            'status' => $distribution->acknowledged_at ? 'Acknowledged' : 'Pending',
            'decline_reason' => null,
        ]);
        $distribution->auditAction('decline_rejected', null, ['by' => $approver->id, 'note' => $note]);

        return $distribution;
    }

    // ── Change propagation (9.1) ─────────────────────────────────────────

    /**
     * Preview which entity instances a group-control change would touch,
     * field by field. A field where the child already differs from the
     * group's current value is a local adaptation and is never overwritten.
     *
     * @param  array<string, mixed>  $changes  new values keyed by field
     */
    public function previewPropagation(Control $group, array $changes): array
    {
        $changes = array_intersect_key($changes, array_flip(self::INHERITED_FIELDS));

        return $group->entityInstances()
            ->with('unit:id,name')
            ->get()
            ->map(function (Control $child) use ($group, $changes) {
                $inherited = [];
                $adapted = [];

                foreach ($changes as $field => $newValue) {
                    if ($child->{$field} == $group->{$field}) {
                        $inherited[$field] = $newValue;
                    } else {
                        $adapted[$field] = $child->{$field};
                    }
                }

                return [
                    'control_id' => $child->id,
                    'control_ref' => $child->control_ref,
                    'entity' => $child->unit?->name,
                    'will_update' => array_keys($inherited),
                    'local_adaptations' => $adapted,
                ];
            })->values()->all();
    }

    /**
     * Apply a change to a group control and roll it out to entity instances.
     * The service owns the group update so children can be compared against
     * the group's PRE-change values: a child still matching the old group
     * value is inherited and follows; a child that differs is a local
     * adaptation and is never overwritten.
     *
     * Mode 'propagate' updates inherited fields; 'notify_only' updates only
     * the group and asks entity owners to re-acknowledge.
     */
    public function propagate(Control $group, array $changes, string $mode, User $user): array
    {
        $changes = array_intersect_key($changes, array_flip(self::INHERITED_FIELDS));
        $summary = ['updated' => 0, 'preserved' => 0, 'notified' => 0];

        DB::transaction(function () use ($group, $changes, $mode, $user, &$summary) {
            $oldValues = $group->only(array_keys($changes));

            foreach ($group->entityInstances()->get() as $child) {
                $applicable = [];

                foreach ($changes as $field => $newValue) {
                    // Inherited ⇔ still equal to the group's pre-change value.
                    if ($child->{$field} == $oldValues[$field]) {
                        $applicable[$field] = $newValue;
                    } else {
                        $summary['preserved']++;
                    }
                }

                if ($mode === 'propagate' && $applicable !== []) {
                    $child->update($applicable);
                    $child->auditAction('group_change_propagated', null, [
                        'fields' => array_keys($applicable),
                        'group_control_id' => $group->id,
                        'by' => $user->id,
                    ]);
                    $summary['updated']++;
                }

                // Entity owners acknowledge through their distribution row.
                ControlDistribution::where('control_id', $group->id)
                    ->where('child_control_id', $child->id)
                    ->whereNotIn('status', ['Declined'])
                    ->update(['acknowledged_at' => null, 'status' => 'Pending']);

                $summary['notified']++;
            }

            $group->update($changes);
            $group->auditAction('group_change_rolled_out', $oldValues, [...$changes, 'mode' => $mode]);
        });

        return $summary;
    }

    // ── Overview (9.2) ───────────────────────────────────────────────────

    /** Stat tiles + per-entity rows for the distribution overview screen. */
    public function overview(?int $controlId = null): array
    {
        $distributions = ControlDistribution::query()
            ->when($controlId, fn ($q, $id) => $q->where('control_id', $id))
            ->with([
                'control:id,control_ref,title,category_id,function_grouping,control_level,frequency',
                'control.category:id,name',
                'entity:id,name,parent_id,type',
                'childControl:id,control_ref,implementation_status,implementation_progress,overall_rating,owner_id',
                'childControl.owner:id,name',
                'assignedOwner:id,name',
            ])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'Complete'),
            ])
            ->get();

        $taskTotals = [
            'total' => $distributions->sum('tasks_count'),
            'completed' => $distributions->sum('completed_tasks_count'),
        ];

        $active = $distributions->where('status', '!=', 'Declined');

        return [
            'stats' => [
                'entities_distributed_to' => $distributions->pluck('entity_id')->unique()->count(),
                'tasks_completed' => $taskTotals['completed'],
                'tasks_outstanding' => $taskTotals['total'] - $taskTotals['completed'],
                'implementation_progress' => $active->isEmpty()
                    ? 0
                    : (int) round($active->avg('progress')),
                'declined_gaps' => $distributions->where('status', 'Declined')->count(),
            ],
            'distributions' => $distributions,
        ];
    }

    private function assertStatus(ControlDistribution $distribution, array $allowed, string $verb): void
    {
        if (! in_array($distribution->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A distribution in status {$distribution->status} cannot be {$verb}.",
            ]);
        }
    }
}
