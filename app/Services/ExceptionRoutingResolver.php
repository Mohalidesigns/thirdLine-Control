<?php

namespace App\Services;

use App\Models\BusinessProcess;
use App\Models\ControlException;
use App\Models\ExceptionRoutingRule;
use App\Models\OrganisationUnit;
use App\Models\User;

/**
 * CR-01: who a given exception is issued to by default. Resolution order at
 * issue time: explicit user > routing rule > unit head > process owner.
 * A rule only PRE-FILLS the issue form — the control officer always
 * confirms the recipient — and an escalation with no resolvable recipient
 * is a HARD FAILURE at issue time, never a silent no-op (R-G).
 */
class ExceptionRoutingResolver
{
    /**
     * The first active rule matching this exception + target, in sequence
     * order. Null match columns are wildcards.
     */
    public function matchingRule(ControlException $exception, ?int $unitId = null, ?int $processId = null): ?ExceptionRoutingRule
    {
        return ExceptionRoutingRule::withoutGlobalScope('tenant')
            ->where('tenant_id', $exception->tenant_id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get()
            ->first(function (ExceptionRoutingRule $rule) use ($exception, $unitId, $processId) {
                return ($rule->match_unit_id === null || $rule->match_unit_id === $unitId)
                    && ($rule->match_process_id === null || $rule->match_process_id === $processId)
                    && ($rule->match_control_category_id === null
                        || $rule->match_control_category_id === $exception->control?->category_id)
                    && ($rule->match_severity === null || $rule->match_severity === $exception->severity)
                    && ($rule->match_source_type === null || $rule->match_source_type === $exception->source_type);
            });
    }

    /**
     * Resolve the named officer who must answer, for a unit/process/user
     * target. Returns null only when every rung fails — the caller turns
     * that into a loud validation error (R-G).
     */
    public function resolveRespondent(
        ControlException $exception,
        string $targetType,
        ?int $unitId = null,
        ?int $processId = null,
        ?int $explicitUserId = null,
    ): ?User {
        if ($explicitUserId) {
            return $this->activeUser($exception->tenant_id, $explicitUserId);
        }

        $rule = $this->matchingRule($exception, $unitId, $processId);

        if ($rule && ($user = $this->userForRule($rule, $exception->tenant_id, $unitId, $processId))) {
            return $user;
        }

        if ($targetType === 'unit' || $unitId) {
            $head = OrganisationUnit::withoutGlobalScope('tenant')->find($unitId)?->head;

            if ($head && $head->is_active) {
                return $head;
            }
        }

        if ($targetType === 'process' || $processId) {
            $process = BusinessProcess::withoutGlobalScope('tenant')->find($processId);

            $owner = $process?->owner;

            if ($owner && $owner->is_active) {
                return $owner;
            }

            // A process without an owner still belongs to a unit whose head
            // can answer for it.
            $head = $process?->unit?->head;

            if ($head && $head->is_active) {
                return $head;
            }
        }

        return null;
    }

    private function userForRule(ExceptionRoutingRule $rule, int $tenantId, ?int $unitId, ?int $processId): ?User
    {
        return match ($rule->route_to_type) {
            'user' => $this->activeUser($tenantId, $rule->route_to_user_id),
            'unit_head' => OrganisationUnit::withoutGlobalScope('tenant')
                ->find($rule->route_to_unit_id ?? $unitId)?->head,
            'process_owner' => BusinessProcess::withoutGlobalScope('tenant')
                ->find($processId)?->owner,
            'role' => $rule->route_to_role
                ? User::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->role($rule->route_to_role)
                    ->orderBy('id')
                    ->first()
                : null,
        };
    }

    private function activeUser(int $tenantId, ?int $userId): ?User
    {
        if (! $userId) {
            return null;
        }

        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->find($userId);
    }
}
