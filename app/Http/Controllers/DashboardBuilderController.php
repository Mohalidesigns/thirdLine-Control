<?php

namespace App\Http\Controllers;

use App\Dashboards\WidgetRegistry;
use App\Http\Requests\DashboardRequest;
use App\Http\Requests\DashboardWidgetRequest;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Services\DashboardBuilderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class DashboardBuilderController extends Controller
{
    public function __construct(
        private DashboardBuilderService $builder,
        private WidgetRegistry $registry,
    ) {}

    public function create(Request $request): Response
    {
        $this->authorize('create', Dashboard::class);

        return Inertia::render('Dashboards/Builder', $this->palette($request) + [
            'dashboard' => null,
            'widgets' => [],
        ]);
    }

    public function edit(Request $request, Dashboard $dashboard): Response
    {
        $this->authorize('update', $dashboard);

        return Inertia::render('Dashboards/Builder', $this->palette($request) + [
            'dashboard' => $dashboard->only([
                'id', 'name', 'slug', 'description', 'visibility', 'role_ids',
                'refresh_interval_seconds', 'sort_order',
            ]),
            'widgets' => $dashboard->widgets()->get()->map(fn (DashboardWidget $widget) => [
                'id' => $widget->id,
                'widget_type' => $widget->widget_type,
                'title' => $widget->title,
                'subtitle' => $widget->subtitle,
                'data_source_key' => $widget->data_source_key,
                'query_config' => $widget->query_config ?? [],
                'display_config' => $widget->display_config ?? [],
                'position' => $widget->geometry(),
                'permission_required' => $widget->permission_required,
                'is_visible' => $widget->is_visible,
                'preview' => $this->registry->resolveWidget($request->user(), $widget),
            ])->values(),
        ]);
    }

    public function store(DashboardRequest $request): RedirectResponse
    {
        $this->authorize('create', Dashboard::class);

        $dashboard = $this->builder->create($request->validated(), $request->user());

        return redirect()
            ->route('dashboards.edit', $dashboard)
            ->with('success', "Dashboard '{$dashboard->name}' created.");
    }

    public function update(DashboardRequest $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorize('update', $dashboard);

        $this->builder->update($dashboard, $request->validated(), $request->user());

        return back()->with('success', 'Dashboard saved.');
    }

    public function destroy(Dashboard $dashboard): RedirectResponse
    {
        $this->authorize('delete', $dashboard);

        $this->builder->delete($dashboard);

        return redirect()->route('dashboards.index')->with('success', 'Dashboard deleted.');
    }

    public function duplicate(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorize('duplicate', $dashboard);

        $validated = $request->validate(['name' => ['nullable', 'string', 'max:120']]);

        $copy = $this->builder->duplicate($dashboard, $request->user(), $validated['name'] ?? null);

        return redirect()
            ->route('dashboards.edit', $copy)
            ->with('success', "Copied to '{$copy->name}'. The copy is private until you share it.");
    }

    public function layout(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorize('update', $dashboard);

        $validated = $request->validate([
            'positions' => ['required', 'array'],
            'positions.*.id' => ['required', 'integer'],
            'positions.*.x' => ['required', 'integer', 'min:0', 'max:11'],
            'positions.*.y' => ['required', 'integer', 'min:0', 'max:200'],
            'positions.*.w' => ['required', 'integer', 'min:1', 'max:12'],
            'positions.*.h' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $this->builder->saveLayout($dashboard, $validated['positions']);

        return back()->with('success', 'Layout saved.');
    }

    public function storeWidget(DashboardWidgetRequest $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorize('update', $dashboard);

        $widget = $this->builder->addWidget($dashboard, $request->validated(), $request->user());

        return back()->with('success', "Added '{$widget->title}'.");
    }

    public function updateWidget(DashboardWidgetRequest $request, Dashboard $dashboard, DashboardWidget $widget): RedirectResponse
    {
        $this->authorize('update', $dashboard);
        abort_unless($widget->dashboard_id === $dashboard->id, 404);

        $this->builder->updateWidget($widget, $request->validated());

        return back()->with('success', 'Widget updated.');
    }

    public function destroyWidget(Dashboard $dashboard, DashboardWidget $widget): RedirectResponse
    {
        $this->authorize('update', $dashboard);
        abort_unless($widget->dashboard_id === $dashboard->id, 404);

        $widget->delete();

        return back()->with('success', 'Widget removed.');
    }

    /** The builder's palette: only datasets this composer may themselves read. */
    private function palette(Request $request): array
    {
        return [
            'sources' => $this->registry->availableTo($request->user()),
            'widgetTypes' => DashboardWidget::TYPES,
            'visibilities' => Dashboard::VISIBILITIES,
            'gridColumns' => Dashboard::GRID_COLUMNS,
            'maxWidgets' => (int) config('dashboards.max_widgets'),
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'canPublish' => $request->user()->can('publish dashboards'),
        ];
    }
}
