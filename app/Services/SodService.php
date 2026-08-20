<?php

namespace App\Services;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\DataSnapshot;
use App\Models\OrganisationUnit;
use App\Models\SodConflictRule;
use App\Models\SodViolation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Segregation-of-duties analysis over an entitlement extract (12.3).
 *
 * The toxic-combination matrix is seeded data in sod_conflict_rules, one
 * row per pair, named in the source system's own vocabulary — a Finacle
 * menu id, an Entra role, an SAP transaction code. Nothing here knows
 * what "payment approval" means; it knows that function A and function B
 * must not sit on the same account (R1).
 *
 * Note the distinction from R2: R2 is SecondLine's own segregation of
 * duties, enforced in policies. This service tests the *client's*
 * segregation of duties in the systems they run.
 */
class SodService
{
    /**
     * Match a subject → functions map against the active matrix.
     *
     * @param  array<string, array{functions: array<int, string>, name?: ?string, entity?: ?string}>  $subjects
     * @return array<int, array<string, mixed>> findings in the rule-engine shape
     */
    public function evaluate(array $subjects, ?string $systemKey = null): array
    {
        $rules = SodConflictRule::withoutGlobalScopes()
            ->when($systemKey, fn ($q) => $q->where('system_key', $systemKey))
            ->where('is_active', true)
            ->when(auth()->user()?->tenant_id, fn ($q, $tenantId) => $q->where('tenant_id', $tenantId))
            ->with('mitigatingControl:id,control_ref,title,status')
            ->get();

        $findings = [];

        foreach ($subjects as $identifier => $detail) {
            $functions = array_map(
                fn ($f) => mb_strtolower(trim((string) $f)),
                (array) ($detail['functions'] ?? []),
            );

            foreach ($rules as $rule) {
                $a = mb_strtolower(trim($rule->function_a));
                $b = mb_strtolower(trim($rule->function_b));

                if (! in_array($a, $functions, true) || ! in_array($b, $functions, true)) {
                    continue;
                }

                $findings[] = [
                    'record_identifier' => (string) $identifier,
                    'record_data' => [
                        'subject' => (string) $identifier,
                        'subject_name' => $detail['name'] ?? null,
                        'entity' => $detail['entity'] ?? null,
                        'rule_id' => $rule->id,
                        'rule' => $rule->name,
                        'conflict_type' => $rule->conflict_type,
                        'function_a' => $rule->function_a,
                        'function_b' => $rule->function_b,
                        'mitigating_control' => $rule->mitigatingControl?->control_ref,
                    ],
                    'severity' => $rule->risk_level,
                    'failure_reason' => sprintf(
                        '%s holds both "%s" and "%s" — %s conflict under %s.%s',
                        $detail['name'] ?: $identifier,
                        $rule->function_a,
                        $rule->function_b,
                        str_replace('_', '/', $rule->conflict_type),
                        $rule->name,
                        $rule->mitigatingControl
                            ? " Mitigating control {$rule->mitigatingControl->control_ref} is named on the rule."
                            : '',
                    ),
                ];
            }
        }

        return $findings;
    }

    /**
     * Persist the findings of a run as violations. Re-detecting a subject
     * that is already accepted or mitigated updates the detection time
     * rather than reopening it — the unique key on
     * (tenant, rule, subject) is what makes the sweep idempotent.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array{opened: int, refreshed: int}
     */
    public function record(array $findings, int $tenantId, ?DataSnapshot $snapshot = null): array
    {
        $opened = 0;
        $refreshed = 0;

        foreach ($findings as $finding) {
            $data = (array) ($finding['record_data'] ?? []);
            $ruleId = (int) ($data['rule_id'] ?? 0);

            if ($ruleId === 0) {
                continue;
            }

            $existing = SodViolation::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('rule_id', $ruleId)
                ->where('subject_identifier', (string) $finding['record_identifier'])
                ->first();

            if ($existing) {
                $existing->update([
                    'detected_at' => now(),
                    'snapshot_id' => $snapshot?->id ?? $existing->snapshot_id,
                    'subject_name' => $data['subject_name'] ?? $existing->subject_name,
                ]);
                $refreshed++;

                continue;
            }

            SodViolation::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'rule_id' => $ruleId,
                'subject_identifier' => (string) $finding['record_identifier'],
                'subject_name' => $data['subject_name'] ?? null,
                'entity_id' => $this->resolveEntity($data['entity'] ?? null, $tenantId),
                'detected_at' => now(),
                'snapshot_id' => $snapshot?->id,
                'status' => 'Open',
            ]);

            $opened++;
        }

        return ['opened' => $opened, 'refreshed' => $refreshed];
    }

    /**
     * Record a compensating arrangement. Mitigation is a claim about the
     * world, so it needs a note; nobody clears a toxic pair with a click.
     */
    public function mitigate(SodViolation $violation, User $user, string $notes, ?int $controlId = null): SodViolation
    {
        if ($violation->status === 'Remediated') {
            throw ValidationException::withMessages([
                'status' => 'This violation has already been remediated.',
            ]);
        }

        $violation->update(['status' => 'Mitigated', 'mitigation_notes' => $notes]);

        if ($controlId && Control::withoutGlobalScopes()->whereKey($controlId)->exists()) {
            $violation->rule->update(['mitigating_control_id' => $controlId]);
        }

        $violation->auditAction('mitigated', null, ['by' => $user->id, 'notes' => $notes]);

        return $violation->fresh();
    }

    /**
     * Accepting a toxic combination is a risk decision, not an
     * administrative one: it needs the accept-risk permission, an expiry
     * date, and it is never the person who raised it. The expiry is
     * mandatory — an open-ended acceptance is how a bank ends up with a
     * teller who can also authorise (R2 in spirit).
     */
    public function accept(SodViolation $violation, User $user, string $notes, string $until): SodViolation
    {
        if (! $user->can('accept sod-violations')) {
            throw ValidationException::withMessages([
                'acceptance' => 'Accepting a segregation-of-duties conflict requires senior approval.',
            ]);
        }

        if (strtotime($until) <= strtotime(now()->toDateString())) {
            throw ValidationException::withMessages([
                'accepted_until' => 'An acceptance must expire on a future date.',
            ]);
        }

        $violation->update([
            'status' => 'Accepted',
            'mitigation_notes' => $notes,
            'accepted_by' => $user->id,
            'accepted_until' => $until,
        ]);

        $violation->auditAction('accepted', null, [
            'by' => $user->id,
            'until' => $until,
            'notes' => $notes,
        ]);

        return $violation->fresh();
    }

    /** The entitlement is gone from the source; the next sweep will confirm. */
    public function remediate(SodViolation $violation, User $user, string $notes): SodViolation
    {
        $violation->update([
            'status' => 'Remediated',
            'remediated_at' => now(),
            'mitigation_notes' => $notes,
        ]);

        $violation->auditAction('remediated', null, ['by' => $user->id]);

        return $violation->fresh();
    }

    public function markFalsePositive(SodViolation $violation, User $user, string $notes): SodViolation
    {
        $violation->update(['status' => 'False Positive', 'mitigation_notes' => $notes]);
        $violation->auditAction('false_positive', null, ['by' => $user->id, 'notes' => $notes]);

        return $violation->fresh();
    }

    /**
     * Raise an exception for an unmitigated conflict, through the same
     * ExceptionService every other source uses (12.4).
     */
    public function raiseException(SodViolation $violation): ControlException
    {
        return app(ExceptionService::class)->raiseFromSodViolation($violation);
    }

    /**
     * Expire acceptances that have run out, on the daily sweep. An
     * expired acceptance reopens: the conflict did not go away because
     * the calendar turned.
     */
    public function expireAcceptances(?int $tenantId = null): int
    {
        $count = 0;

        SodViolation::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('status', 'Accepted')
            ->whereNotNull('accepted_until')
            ->whereDate('accepted_until', '<', now())
            ->each(function (SodViolation $violation) use (&$count) {
                $violation->update(['status' => 'Open']);
                $violation->auditAction('acceptance_expired');
                $count++;
            });

        return $count;
    }

    private function resolveEntity(?string $name, int $tenantId): ?int
    {
        if (! $name) {
            return null;
        }

        return OrganisationUnit::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('name', $name)->orWhere('code', $name))
            ->value('id');
    }
}
