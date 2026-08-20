<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExceptionRoutingRuleRequest;
use App\Http\Requests\ExceptionSlaPolicyRequest;
use App\Models\BusinessProcess;
use App\Models\ControlCategory;
use App\Models\ControlException;
use App\Models\ExceptionRoutingRule;
use App\Models\ExceptionSlaPolicy;
use App\Models\OrganisationUnit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * CR-01 admin config: routing rules and SLA policies. Gated on
 * 'configure exception-routing' — held by the System Administrator, who
 * deliberately gains no review or closure rights with it.
 */
class ExceptionRoutingController extends Controller
{
    public function routing(): Response
    {
        return Inertia::render('Settings/ExceptionRouting', [
            'rules' => ExceptionRoutingRule::query()
                ->with(['matchUnit:id,name', 'matchProcess:id,name', 'routeToUser:id,name', 'routeToUnit:id,name', 'slaPolicy:id,name,severity'])
                ->orderBy('sequence')
                ->get(),
            'units' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'processes' => BusinessProcess::orderBy('name')->get(['id', 'name']),
            'categories' => ControlCategory::orderBy('name')->get(['id', 'name']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'roles' => Role::orderBy('name')->pluck('name'),
            'slaPolicies' => ExceptionSlaPolicy::orderBy('severity')->get(['id', 'name', 'severity']),
            'severities' => ControlException::SEVERITIES,
        ]);
    }

    public function storeRule(ExceptionRoutingRuleRequest $request): RedirectResponse
    {
        ExceptionRoutingRule::create($request->validated());

        return back()->with('success', 'Routing rule created.');
    }

    public function updateRule(ExceptionRoutingRuleRequest $request, ExceptionRoutingRule $rule): RedirectResponse
    {
        $rule->update($request->validated());

        return back()->with('success', 'Routing rule updated.');
    }

    public function destroyRule(ExceptionRoutingRule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('success', 'Routing rule removed.');
    }

    // ── SLA policies (R1) ───────────────────────────────────────────

    public function sla(): Response
    {
        return Inertia::render('Settings/ExceptionSla', [
            'policies' => ExceptionSlaPolicy::query()
                ->orderByRaw("case severity when 'Critical' then 0 when 'High' then 1 when 'Medium' then 2 else 3 end")
                ->get(),
            'severities' => ControlException::SEVERITIES,
        ]);
    }

    public function storePolicy(ExceptionSlaPolicyRequest $request): RedirectResponse
    {
        ExceptionSlaPolicy::create($request->validated());

        return back()->with('success', 'SLA policy created.');
    }

    public function updatePolicy(ExceptionSlaPolicyRequest $request, ExceptionSlaPolicy $policy): RedirectResponse
    {
        $policy->update($request->validated());

        return back()->with('success', 'SLA policy updated.');
    }
}
