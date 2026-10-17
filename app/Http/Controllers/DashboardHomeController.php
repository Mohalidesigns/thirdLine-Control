<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use App\Models\DashboardHomeAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Reporting → Dashboards: which board each role lands on at /dashboard.
 * The landing page only displays what is configured here.
 */
class DashboardHomeController extends Controller
{
    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('assignHome', Dashboard::class);

        $validated = $request->validate([
            'dashboard_id' => ['nullable', 'integer'],
        ]);

        if (empty($validated['dashboard_id'])) {
            DashboardHomeAssignment::query()->where('role_id', $role->id)->get()->each->delete();

            return back()->with('success', "{$role->name} now lands on the default dashboard.");
        }

        $dashboard = Dashboard::query()->findOrFail($validated['dashboard_id']);

        // A role can only land on a board every holder of it may open.
        if (! $dashboard->isVisibleToRole($role->id)) {
            throw ValidationException::withMessages([
                'dashboard_id' => "Share '{$dashboard->name}' with {$role->name} (or the whole organisation) before making it their home dashboard.",
            ]);
        }

        DashboardHomeAssignment::query()->updateOrCreate(
            ['tenant_id' => $request->user()->tenant_id, 'role_id' => $role->id],
            ['dashboard_id' => $dashboard->id, 'assigned_by' => $request->user()->id],
        );

        return back()->with('success', "{$role->name} now lands on '{$dashboard->name}'.");
    }
}
