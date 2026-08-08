<?php

namespace App\Http\Controllers;

use App\Http\Requests\SodConflictRuleRequest;
use App\Models\Control;
use App\Models\SodConflictRule;
use App\Models\SodViolation;
use App\Services\SodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The toxic-combination matrix and what it finds (12.3).
 *
 * This is the *client's* segregation of duties — who in the bank's core
 * banking or ERP holds two functions they should not. SecondLine's own
 * segregation of duties (R2) is a separate thing, enforced in policies.
 */
class SodController extends Controller
{
    public function __construct(private SodService $sod) {}

    public function conflicts(Request $request): Response
    {
        $this->authorize('viewAny', SodConflictRule::class);

        $rules = SodConflictRule::query()
            ->with('mitigatingControl:id,control_ref,title,status')
            ->withCount(['violations as open_violations_count' => fn ($q) => $q->where('status', 'Open')])
            ->when($request->system_key, fn ($q, $s) => $q->where('system_key', $s))
            ->when($request->conflict_type, fn ($q, $t) => $q->where('conflict_type', $t))
            ->when($request->risk_level, fn ($q, $r) => $q->where('risk_level', $r))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('function_a', 'like', "%{$s}%")
                ->orWhere('function_b', 'like', "%{$s}%")))
            ->orderBy('system_key')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Sod/Conflicts', [
            'rules' => $rules,
            'filters' => $request->only(['system_key', 'conflict_type', 'risk_level', 'search']),
            'options' => [
                'conflict_types' => SodConflictRule::CONFLICT_TYPES,
                'risk_levels' => SodConflictRule::RISK_LEVELS,
                'systems' => SodConflictRule::query()->distinct()->orderBy('system_key')->pluck('system_key')->filter()->values(),
            ],
            'controls' => Control::query()
                ->where('status', 'Active')
                ->orderBy('control_ref')
                ->get(['id', 'control_ref', 'title']),
            'stats' => [
                'rules' => SodConflictRule::active()->count(),
                'open_violations' => SodViolation::where('status', 'Open')->count(),
                'unmitigated_critical' => SodViolation::where('status', 'Open')
                    ->whereHas('rule', fn ($q) => $q->where('risk_level', 'Critical'))
                    ->count(),
            ],
            'can' => [
                'manage' => $request->user()->can('manage sod-rules'),
            ],
        ]);
    }

    public function storeConflict(SodConflictRuleRequest $request): RedirectResponse
    {
        $this->authorize('create', SodConflictRule::class);

        $rule = SodConflictRule::create($request->validated());

        return back()->with('success', "Conflict rule '{$rule->name}' added.");
    }

    public function updateConflict(SodConflictRuleRequest $request, SodConflictRule $rule): RedirectResponse
    {
        $this->authorize('update', $rule);

        $rule->update($request->validated());

        return back()->with('success', 'Conflict rule updated.');
    }

    public function destroyConflict(Request $request, SodConflictRule $rule): RedirectResponse
    {
        $this->authorize('delete', $rule);

        // Deactivating rather than deleting keeps the violations it found
        // attributable to a rule that still exists.
        $rule->update(['is_active' => false]);
        $rule->auditAction('deactivated', null, ['by' => $request->user()->id]);

        return back()->with('success', 'Conflict rule deactivated. Its existing violations are unchanged.');
    }

    public function violations(Request $request): Response
    {
        $this->authorize('viewAny', SodViolation::class);

        $violations = SodViolation::query()
            ->with([
                'rule:id,name,system_key,function_a,function_b,conflict_type,risk_level,mitigating_control_id',
                'rule.mitigatingControl:id,control_ref,title',
                'entity:id,name',
                'acceptor:id,name',
                'exception:id,reference,status',
            ])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', 'Open'))
            ->when($request->rule_id, fn ($q, $r) => $q->where('rule_id', $r))
            ->when($request->risk_level, fn ($q, $r) => $q->whereHas('rule', fn ($w) => $w->where('risk_level', $r)))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('subject_identifier', 'like', "%{$s}%")
                ->orWhere('subject_name', 'like', "%{$s}%")))
            ->orderByDesc('detected_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Sod/Violations', [
            'violations' => $violations,
            'filters' => $request->only(['status', 'rule_id', 'risk_level', 'search']),
            'statuses' => SodViolation::STATUSES,
            'riskLevels' => SodConflictRule::RISK_LEVELS,
            'rules' => SodConflictRule::query()->orderBy('name')->get(['id', 'name', 'system_key']),
            'controls' => Control::query()
                ->where('status', 'Active')
                ->orderBy('control_ref')
                ->get(['id', 'control_ref', 'title']),
            'stats' => [
                'open' => SodViolation::where('status', 'Open')->count(),
                'accepted' => SodViolation::where('status', 'Accepted')->count(),
                'expiring' => SodViolation::where('status', 'Accepted')
                    ->whereNotNull('accepted_until')
                    ->whereDate('accepted_until', '<=', now()->addDays(30))
                    ->count(),
            ],
            'can' => [
                'mitigate' => $request->user()->can('manage sod-rules'),
                'accept' => $request->user()->can('accept sod-violations'),
            ],
        ]);
    }

    public function mitigate(Request $request, SodViolation $violation): RedirectResponse
    {
        $this->authorize('mitigate', $violation);

        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:10', 'max:2000'],
            'mitigating_control_id' => ['nullable', 'exists:controls,id'],
        ]);

        $this->sod->mitigate(
            $violation,
            $request->user(),
            $validated['notes'],
            $validated['mitigating_control_id'] ?? null,
        );

        return back()->with('success', 'Mitigation recorded.');
    }

    public function accept(Request $request, SodViolation $violation): RedirectResponse
    {
        $this->authorize('accept', $violation);

        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:10', 'max:2000'],
            'accepted_until' => ['required', 'date', 'after:today'],
        ]);

        $this->sod->accept($violation, $request->user(), $validated['notes'], $validated['accepted_until']);

        return back()->with('success', 'Conflict accepted until '.$validated['accepted_until'].'.');
    }

    public function remediate(Request $request, SodViolation $violation): RedirectResponse
    {
        $this->authorize('remediate', $violation);

        $validated = $request->validate(['notes' => ['required', 'string', 'min:10', 'max:2000']]);

        $this->sod->remediate($violation, $request->user(), $validated['notes']);

        return back()->with('success', 'Marked remediated. The next sweep will confirm the entitlement is gone.');
    }

    public function falsePositive(Request $request, SodViolation $violation): RedirectResponse
    {
        $this->authorize('mitigate', $violation);

        $validated = $request->validate(['notes' => ['required', 'string', 'min:10', 'max:2000']]);

        $this->sod->markFalsePositive($violation, $request->user(), $validated['notes']);

        return back()->with('success', 'Marked a false positive — review the conflict rule’s function names.');
    }

    /** Escalate an unmitigated conflict into the exception register. */
    public function escalate(Request $request, SodViolation $violation): RedirectResponse
    {
        $this->authorize('escalate', $violation);

        $exception = $this->sod->raiseException($violation);

        return back()->with('success', "Exception {$exception->reference} raised.");
    }
}
