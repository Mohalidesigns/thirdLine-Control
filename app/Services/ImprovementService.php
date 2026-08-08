<?php

namespace App\Services;

use App\Models\ImprovementAction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The improvement database (9.9): a register of improvement actions from
 * any source, with approval, ownership, implementation and independent
 * verification. The proposer never verifies their own action.
 */
class ImprovementService
{
    public const TRANSITIONS = [
        'Proposed' => ['Approved', 'Rejected'],
        'Approved' => ['In Progress'],
        'In Progress' => ['Implemented'],
        'Implemented' => ['Verified', 'In Progress'],
        'Verified' => [],
        'Rejected' => [],
    ];

    public function propose(array $data, User $user): ImprovementAction
    {
        return ImprovementAction::create([
            ...$data,
            'reference' => ImprovementAction::nextReference('IMP'),
            'status' => 'Proposed',
        ]);
    }

    public function approve(ImprovementAction $action, User $approver): ImprovementAction
    {
        $this->transition($action, 'Approved', ['approved_by' => $approver->id]);
        $action->auditAction('approved', null, ['by' => $approver->id]);

        return $action;
    }

    public function reject(ImprovementAction $action, User $approver, ?string $reason = null): ImprovementAction
    {
        $this->transition($action, 'Rejected', ['approved_by' => $approver->id]);
        $action->auditAction('rejected', null, ['by' => $approver->id, 'reason' => $reason]);

        return $action;
    }

    public function start(ImprovementAction $action): ImprovementAction
    {
        $this->transition($action, 'In Progress');

        return $action;
    }

    public function markImplemented(ImprovementAction $action): ImprovementAction
    {
        $this->transition($action, 'Implemented');

        return $action;
    }

    /** Verification is independent: never the owner who implemented it. */
    public function verify(ImprovementAction $action, User $verifier): ImprovementAction
    {
        if ($action->owner_id === $verifier->id) {
            throw ValidationException::withMessages([
                'verification' => 'An improvement cannot be verified by the owner who implemented it.',
            ]);
        }

        $this->transition($action, 'Verified', [
            'verified_by' => $verifier->id,
            'verified_at' => now(),
        ]);

        $action->auditAction('verified', null, ['by' => $verifier->id]);

        return $action;
    }

    private function transition(ImprovementAction $action, string $to, array $extra = []): void
    {
        $allowed = self::TRANSITIONS[$action->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "An improvement cannot move from {$action->status} to {$to}.",
            ]);
        }

        $action->update(['status' => $to, ...$extra]);
    }
}
