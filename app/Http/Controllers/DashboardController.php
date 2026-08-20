<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use App\Models\OrganisationUnit;
use App\Services\DashboardBuilderService;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
            return $this->renderDashboard($request, $dashboard);
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
                    'can' => [
                        'update' => $user->can('update', $dashboard),
                        'delete' => $user->can('delete', $dashboard),
                        'duplicate' => $user->can('duplicate', $dashboard),
                    ],
                ]),
            'can' => [
                'create' => $user->can('create', Dashboard::class),
                'publish' => $user->can('publish dashboards'),
            ],
        ]);
    }

    public function show(Request $request, Dashboard $dashboard): Response
    {
        $this->authorize('view', $dashboard);

        return $this->renderDashboard($request, $dashboard);
    }

    private function renderDashboard(Request $request, Dashboard $dashboard): Response
    {
        $user = $request->user();
        $dashboard->load('owner:id,name');

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
