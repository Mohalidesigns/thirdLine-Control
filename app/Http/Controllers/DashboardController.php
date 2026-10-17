<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use App\Models\DashboardHomeAssignment;
use App\Models\OrganisationUnit;
use App\Services\DashboardBuilderService;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService,
        private DashboardBuilderService $builder,
    ) {}

    /**
     * The landing board. A tenant with configurable dashboards lands on the
     * one its role is defaulted to; a tenant that has none — a fresh install,
     * or one that has deleted them — still gets the Phase 4 fixed dashboard
     * rather than an empty screen (R8).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $dashboard = $user->can('view dashboards') ? Dashboard::defaultFor($user) : null;

        if ($dashboard && $request->user()->can('view', $dashboard)) {
            // The landing board is display-only: everything that configures
            // it lives in Reporting → Dashboards.
            return $this->renderDashboard($request, $dashboard, home: true);
        }

        return Inertia::render('Dashboard', [
            'metrics' => $this->dashboardService->metrics(
                $request->only(['unit_id', 'severity']),
            ),
            'filters' => $request->only(['unit_id', 'severity']),
            'units' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** The dashboard library — everything this viewer may open. */
    public function library(Request $request): Response
    {
        $this->authorize('viewAny', Dashboard::class);

        $user = $request->user();
        $homes = $this->homeDashboards();

        return Inertia::render('Dashboards/Index', [
            'dashboards' => Dashboard::query()
                ->visibleTo($user)
                ->with('owner:id,name')
                ->withCount('widgets')
                ->orderByDesc('is_system')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Dashboard $dashboard) => [
                    'id' => $dashboard->id,
                    'name' => $dashboard->name,
                    'slug' => $dashboard->slug,
                    'description' => $dashboard->description,
                    'visibility' => $dashboard->visibility,
                    'is_system' => $dashboard->is_system,
                    'widgets_count' => $dashboard->widgets_count,
                    'owner' => $dashboard->owner?->only(['id', 'name']),
                    'home_for' => collect($homes)->filter(fn ($home) => $home['dashboard_id'] === $dashboard->id)->pluck('role')->values(),
                    'can' => [
                        'update' => $user->can('update', $dashboard),
                        'delete' => $user->can('delete', $dashboard),
                        'duplicate' => $user->can('duplicate', $dashboard),
                    ],
                ]),
            'homes' => array_values($homes),
            'can' => [
                'create' => $user->can('create', Dashboard::class),
                'publish' => $user->can('publish dashboards'),
                'assignHome' => $user->can('assignHome', Dashboard::class),
            ],
        ]);
    }

    public function show(Request $request, Dashboard $dashboard): Response
    {
        $this->authorize('view', $dashboard);

        return $this->renderDashboard($request, $dashboard);
    }

    /**
     * The board each role lands on: its Reporting assignment, else the seeded
     * role default. A role with neither lands on the first board it can open.
     *
     * @return array<int, array{role_id: int, role: string, dashboard_id: ?int, assigned: bool}>
     */
    private function homeDashboards(): array
    {
        $assigned = DashboardHomeAssignment::query()->pluck('dashboard_id', 'role_id');
        $seeded = Dashboard::query()->whereNotNull('is_default_for_role_id')
            ->orderBy('sort_order')->get(['id', 'is_default_for_role_id'])
            ->unique('is_default_for_role_id')
            ->pluck('id', 'is_default_for_role_id');

        return Role::query()->orderBy('name')->get(['id', 'name'])
            ->mapWithKeys(fn (Role $role) => [$role->id => [
                'role_id' => $role->id,
                'role' => $role->name,
                'dashboard_id' => $assigned[$role->id] ?? $seeded[$role->id] ?? null,
                'assigned' => isset($assigned[$role->id]),
            ]])
            ->all();
    }

    private function renderDashboard(Request $request, Dashboard $dashboard, bool $home = false): Response
    {
        $user = $request->user();
        $dashboard->load('owner:id,name');

        if ($home) {
            return Inertia::render('Dashboards/Show', $this->builder->render($dashboard, $user) + [
                'home' => true,
                'can' => ['manage' => $user->can('manage dashboards')],
            ]);
        }

        return Inertia::render('Dashboards/Show', $this->builder->render($dashboard, $user) + [
            'available' => Dashboard::query()
                ->visibleTo($user)
                ->orderByDesc('is_system')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn (Dashboard $item) => $item->only(['id', 'name', 'slug'])),
            'can' => [
                'update' => $user->can('update', $dashboard),
                'duplicate' => $user->can('duplicate', $dashboard),
                'manage' => $user->can('manage dashboards'),
            ],
        ]);
    }
}
