<?php

namespace App\Http\Controllers;

use App\Models\EscalationEvent;
use App\Models\EscalationMatrix;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EscalationMatrixController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/EscalationMatrix', [
            'rules' => EscalationMatrix::with('recipient:id,name')
                ->orderBy('severity')
                ->orderBy('tier_no')
                ->get(),
            'events' => EscalationEvent::with(['recipient:id,name', 'exception:id,reference'])
                ->latest('triggered_at')
                ->limit(50)
                ->get(),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'tierRoles' => EscalationMatrix::TIER_ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRule($request);

        EscalationMatrix::create($validated);

        return back()->with('success', 'Escalation rule added.');
    }

    public function update(Request $request, EscalationMatrix $escalationMatrix): RedirectResponse
    {
        $validated = $this->validateRule($request);

        $escalationMatrix->update($validated);

        return back()->with('success', 'Escalation rule updated.');
    }

    public function destroy(EscalationMatrix $escalationMatrix): RedirectResponse
    {
        $escalationMatrix->delete();

        return back()->with('success', 'Escalation rule removed.');
    }

    private function validateRule(Request $request): array
    {
        return $request->validate([
            'severity' => ['required', Rule::in(['Critical', 'High', 'Medium', 'Low'])],
            'tier_no' => ['required', 'integer', 'between:1,5'],
            'trigger_condition' => ['required', Rule::in(['exception_unassigned', 'exception_overdue', 'exception_inactive', 'test_overdue', 'extension_pending'])],
            'days_threshold' => ['required', 'integer', 'between:0,365'],
            'recipient_role' => ['required', 'string', 'max:100'],
            'recipient_user_id' => ['nullable', 'tenant_user'],
            'channel' => ['required', Rule::in(['in_app', 'email', 'both'])],
            'is_active' => ['boolean'],
        ]);
    }
}
