<?php

namespace App\Services;

use App\Models\Control;
use App\Models\ControlVersion;
use App\Models\User;
use App\Notifications\ControlApprovalNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ControlService
{
    /**
     * Guarded status transitions (FR-1.9). Key = from, values = allowed to.
     */
    public const TRANSITIONS = [
        'Draft' => ['Pending Approval'],
        'Pending Approval' => ['Active', 'Draft'],
        'Active' => ['Under Review', 'Retired'],
        'Under Review' => ['Pending Approval', 'Active', 'Retired'],
        'Retired' => [],
    ];

    public function create(array $data, User $user): Control
    {
        return DB::transaction(function () use ($data, $user) {
            $control = Control::create([
                ...$data,
                'control_ref' => Control::nextReference('CTL', false, 'control_ref'),
                'status' => 'Draft',
                'current_version' => 1,
                'created_by' => $user->id,
            ]);

            $this->writeVersion($control, $user, 'Initial definition');

            return $control;
        });
    }

    /**
     * Amend a control: bumps the version, retains history (FR-1.7), and
     * sends an Active control back through maker-checker (FR-1.8).
     */
    public function amend(Control $control, array $data, User $user, string $changeReason): Control
    {
        $wasActive = $control->status === 'Active';

        $control = DB::transaction(function () use ($control, $data, $user, $changeReason, $wasActive) {
            $control->versions()
                ->where('version_no', $control->current_version)
                ->update(['effective_to' => now()->toDateString()]);

            $control->update([
                ...$data,
                'current_version' => $control->current_version + 1,
                'status' => $wasActive ? 'Pending Approval' : $control->status,
                'approved_by' => $wasActive ? null : $control->approved_by,
                'approved_at' => $wasActive ? null : $control->approved_at,
            ]);

            $this->writeVersion($control, $user, $changeReason);

            return $control;
        });

        // An amended active control is back in front of the checker.
        if ($wasActive) {
            $this->notifyApprovers($control, $user);
        }

        return $control;
    }

    public function submitForApproval(Control $control, ?User $by = null): Control
    {
        $this->transition($control, 'Pending Approval', [
            'return_reason' => null,
            'returned_by' => null,
            'returned_at' => null,
        ]);

        if ($by) {
            $this->notifyApprovers($control, $by);
        }

        return $control;
    }

    /**
     * Maker-checker: the creator can never approve their own control (FR-1.8).
     * Authorisation is also enforced in ControlPolicy — this is defence in depth.
     */
    public function approve(Control $control, User $approver): Control
    {
        if ($control->created_by === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A control cannot be approved by the user who created it.',
            ]);
        }

        $this->transition($control, 'Active', [
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        $control->auditAction('approved');

        // Publish to ThirdLine on approval (FR-11.1); failures log, never block.
        app(IntegrationService::class)->publishControl($control);

        $this->notify(
            $this->makerAndOwner($control, $approver),
            'control.approved',
            new ControlApprovalNotification($control, ControlApprovalNotification::APPROVED, $approver->name),
        );

        return $control;
    }

    public function reject(Control $control, string $reason, ?User $by = null): Control
    {
        $this->transition($control, 'Draft', [
            'return_reason' => $reason,
            'returned_by' => $by?->id,
            'returned_at' => now(),
        ]);
        $control->auditAction('rejected', null, ['reason' => $reason]);

        if ($by) {
            $this->notify(
                $this->makerAndOwner($control, $by),
                'control.returned',
                new ControlApprovalNotification($control, ControlApprovalNotification::RETURNED, $by->name, $reason),
            );
        }

        return $control;
    }

    /** Every control function head who may check it: never the maker (FR-1.8) nor the submitter. */
    private function notifyApprovers(Control $control, User $submitter): void
    {
        $approvers = User::withoutGlobalScopes()
            ->where('tenant_id', $control->tenant_id)
            ->role('Control Function Head')
            ->whereNotIn('id', array_filter([$control->created_by, $submitter->id]))
            ->where('is_active', true)
            ->get();

        $this->notify(
            $approvers,
            'control.approval.requested',
            new ControlApprovalNotification($control, ControlApprovalNotification::SUBMITTED, $submitter->name),
        );
    }

    private function makerAndOwner(Control $control, User $actor)
    {
        return User::withoutGlobalScopes()
            ->whereIn('id', array_filter([$control->created_by, $control->owner_id]))
            ->where('id', '!=', $actor->id)
            ->where('is_active', true)
            ->get();
    }

    /** Fire-and-log — a notification hiccup never blocks the approval itself. */
    private function notify(iterable $recipients, string $eventKey, ControlApprovalNotification $notification): void
    {
        try {
            app(NotificationDispatcher::class)->send($recipients, $eventKey, $notification);
        } catch (\Throwable $e) {
            Log::warning('Control approval notification failed', [
                'control_id' => $notification->control->id,
                'event' => $eventKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function retire(Control $control): Control
    {
        $this->transition($control, 'Retired');
        $control->auditAction('retired');

        app(IntegrationService::class)->publishControl($control, 'retire');

        return $control;
    }

    public function transition(Control $control, string $to, array $extra = []): void
    {
        $allowed = self::TRANSITIONS[$control->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A control cannot move from {$control->status} to {$to}.",
            ]);
        }

        $control->update(['status' => $to, ...$extra]);
    }

    /** Copy a template from the starter library into the tenant's own library (FR-1.3). */
    public function adoptTemplate(Control $template, User $user): Control
    {
        $data = $template->only([
            'title', 'description', 'objective', 'type', 'nature',
            'category_id', 'coso_component', 'frequency', 'test_frequency',
            'is_key_control', 'framework_refs', 'control_documentation',
        ]);

        return $this->create($data, $user);
    }

    /**
     * Record a design or operating effectiveness assessment and refresh the
     * control's current ratings + overall score.
     */
    public function assess(Control $control, User $assessor, array $data): Control
    {
        return DB::transaction(function () use ($control, $assessor, $data) {
            $control->assessments()->create([
                'period_label' => $data['period_label'] ?? ('Q'.now()->quarter.' '.now()->year),
                'design_result' => $data['design_result'] ?? null,
                'operating_result' => $data['operating_result'] ?? null,
                'notes' => $data['notes'] ?? null,
                'assessed_by' => $assessor->id,
            ]);

            if (! empty($data['design_result'])) {
                $control->design_effectiveness = $data['design_result'];
            }

            if (! empty($data['operating_result'])) {
                $control->operating_effectiveness = $data['operating_result'];
            }

            $control->last_assessed_at = now();
            $control->overall_rating = $control->deriveOverallRating();
            $control->save();

            $control->auditAction('assessed', null, [
                'design' => $data['design_result'] ?? null,
                'operating' => $data['operating_result'] ?? null,
            ]);

            return $control;
        });
    }

    private function writeVersion(Control $control, User $user, string $reason): ControlVersion
    {
        return $control->versions()->create([
            'version_no' => $control->current_version,
            'payload' => $control->only([
                'title', 'description', 'objective', 'type', 'nature', 'category_id',
                'coso_component', 'process_id', 'unit_id', 'department', 'owner_id',
                'frequency', 'test_frequency', 'is_key_control',
                'framework_refs', 'control_documentation', 'notes', 'status',
            ]),
            'effective_from' => now()->toDateString(),
            'changed_by' => $user->id,
            'change_reason' => $reason,
        ]);
    }
}
