<?php

namespace App\Services;

use App\Dashboards\WidgetRegistry;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Composition and rendering of configurable dashboards (13.2).
 *
 * Two invariants live here rather than in the controller, because a
 * controller is one of several ways in and the invariants have to hold on
 * all of them: a widget may only name a registered data source, and a
 * dashboard may only be widened beyond its author once by someone entitled
 * to publish.
 */
class DashboardBuilderService
{
    public function __construct(private WidgetRegistry $registry) {}

    public function create(array $data, User $user): Dashboard
    {
        return DB::transaction(function () use ($data, $user) {
            $dashboard = Dashboard::create([
                'name' => $data['name'],
                'slug' => Dashboard::uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'owner_id' => $user->id,
                'visibility' => $this->resolveVisibility($data['visibility'] ?? 'private', $user),
                'role_ids' => $data['role_ids'] ?? null,
                'refresh_interval_seconds' => (int) ($data['refresh_interval_seconds'] ?? 0),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'public_token' => ($data['visibility'] ?? null) === 'public_link' ? Str::random(48) : null,
            ]);

            foreach ($data['widgets'] ?? [] as $index => $widget) {
                $this->addWidget($dashboard, $widget + ['sort_order' => $index], $user);
            }

            $dashboard->auditAction('dashboard.created', null, ['name' => $dashboard->name]);

            return $dashboard;
        });
    }

    public function update(Dashboard $dashboard, array $data, User $user): Dashboard
    {
        $before = $dashboard->only(['name', 'description', 'visibility', 'role_ids']);

        $dashboard->fill([
            'name' => $data['name'] ?? $dashboard->name,
            'description' => $data['description'] ?? $dashboard->description,
            'refresh_interval_seconds' => (int) ($data['refresh_interval_seconds'] ?? $dashboard->refresh_interval_seconds),
            'sort_order' => (int) ($data['sort_order'] ?? $dashboard->sort_order),
        ]);

        if (array_key_exists('visibility', $data)) {
            $visibility = $this->resolveVisibility($data['visibility'], $user);
            $dashboard->visibility = $visibility;
            $dashboard->role_ids = $visibility === 'role' ? ($data['role_ids'] ?? []) : null;

            // A link is minted once and revoked by changing visibility — it is
            // never reused, so a revoked URL stays dead.
            $dashboard->public_token = $visibility === 'public_link'
                ? ($dashboard->public_token ?? Str::random(48))
                : null;
        }

        if (isset($data['name']) && $data['name'] !== $before['name']) {
            $dashboard->slug = Dashboard::uniqueSlug($data['name'], $dashboard->id);
        }

        $dashboard->save();

        if (array_key_exists('widgets', $data)) {
            $this->syncWidgets($dashboard, $data['widgets'] ?? [], $user);
        }

        $dashboard->auditAction('dashboard.updated', $before, $dashboard->only(array_keys($before)));

        return $dashboard;
    }

    /**
     * Replace the tile set wholesale. The builder always sends the complete
     * canvas, so a diff would be guessing at intent — and a widget the user
     * deleted has to actually go.
     */
    public function syncWidgets(Dashboard $dashboard, array $widgets, User $user): void
    {
        $cap = (int) config('dashboards.max_widgets');

        if (count($widgets) > $cap) {
            throw ValidationException::withMessages([
                'widgets' => "A dashboard holds at most {$cap} widgets — past that it stops loading usefully on a slow connection.",
            ]);
        }

        foreach ($widgets as $widget) {
            $this->assertRegisteredSource($widget);
        }

        DB::transaction(function () use ($dashboard, $widgets) {
            $dashboard->widgets()->delete();

            foreach ($widgets as $index => $widget) {
                $dashboard->widgets()->create([
                    'widget_type' => $widget['widget_type'],
                    'title' => $widget['title'],
                    'subtitle' => $widget['subtitle'] ?? null,
                    'data_source_key' => $widget['data_source_key'] ?? null,
                    'query_config' => $widget['query_config'] ?? null,
                    'display_config' => $widget['display_config'] ?? null,
                    'drill_down_config' => $widget['drill_down_config'] ?? null,
                    'position' => $widget['position'] ?? null,
                    'refresh_seconds' => (int) ($widget['refresh_seconds'] ?? 0),
                    'permission_required' => $widget['permission_required'] ?? null,
                    'is_visible' => $widget['is_visible'] ?? true,
                    'sort_order' => $index,
                ]);
            }
        });
    }

    /**
     * Visibility beyond the author is a publishing act. Anyone may keep a
     * private board; widening it to a role, the tenant or a public link
     * needs the publish permission, and asking without it is refused rather
     * than silently downgraded — a dashboard the author believes is shared
     * but is not is worse than an error.
     */
    private function resolveVisibility(string $requested, User $user): string
    {
        if (! in_array($requested, Dashboard::VISIBILITIES, true)) {
            $requested = 'private';
        }

        if ($requested !== 'private' && ! $user->can('publish dashboards')) {
            throw ValidationException::withMessages([
                'visibility' => 'Sharing a dashboard beyond yourself needs the "publish dashboards" permission.',
            ]);
        }

        return $requested;
    }

    public function duplicate(Dashboard $dashboard, User $user, ?string $name = null): Dashboard
    {
        return DB::transaction(function () use ($dashboard, $user, $name) {
            $name ??= $dashboard->name.' (copy)';

            $copy = Dashboard::create([
                'name' => $name,
                'slug' => Dashboard::uniqueSlug($name),
                'description' => $dashboard->description,
                'owner_id' => $user->id,
                'visibility' => 'private',
                'refresh_interval_seconds' => $dashboard->refresh_interval_seconds,
                'is_system' => false,
                'sort_order' => $dashboard->sort_order,
            ]);

            foreach ($dashboard->widgets as $widget) {
                $copy->widgets()->create($widget->only([
                    'widget_type', 'title', 'subtitle', 'data_source_key', 'query_config',
                    'display_config', 'drill_down_config', 'position', 'refresh_seconds',
                    'permission_required', 'is_visible', 'sort_order',
                ]));
            }

            $copy->auditAction('dashboard.duplicated', ['from' => $dashboard->slug], ['to' => $copy->slug]);

            return $copy;
        });
    }

    public function addWidget(Dashboard $dashboard, array $data, User $user): DashboardWidget
    {
        $this->assertRegisteredSource($data);
        $this->assertWithinWidgetCap($dashboard);

        return $dashboard->widgets()->create([
            'widget_type' => $data['widget_type'],
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? null,
            'data_source_key' => $data['data_source_key'] ?? null,
            'query_config' => $data['query_config'] ?? null,
            'display_config' => $data['display_config'] ?? null,
            'drill_down_config' => $data['drill_down_config'] ?? null,
            'position' => $data['position'] ?? null,
            'refresh_seconds' => (int) ($data['refresh_seconds'] ?? 0),
            'permission_required' => $data['permission_required'] ?? null,
            'is_visible' => $data['is_visible'] ?? true,
            'sort_order' => (int) ($data['sort_order'] ?? $dashboard->widgets()->count()),
        ]);
    }

    public function updateWidget(DashboardWidget $widget, array $data): DashboardWidget
    {
        $this->assertRegisteredSource($data + ['data_source_key' => $widget->data_source_key]);

        $widget->update([
            'widget_type' => $data['widget_type'] ?? $widget->widget_type,
            'title' => $data['title'] ?? $widget->title,
            'subtitle' => $data['subtitle'] ?? $widget->subtitle,
            'data_source_key' => $data['data_source_key'] ?? $widget->data_source_key,
            'query_config' => $data['query_config'] ?? $widget->query_config,
            'display_config' => $data['display_config'] ?? $widget->display_config,
            'drill_down_config' => $data['drill_down_config'] ?? $widget->drill_down_config,
            'position' => $data['position'] ?? $widget->position,
            'refresh_seconds' => (int) ($data['refresh_seconds'] ?? $widget->refresh_seconds),
            'permission_required' => $data['permission_required'] ?? $widget->permission_required,
            'is_visible' => $data['is_visible'] ?? $widget->is_visible,
        ]);

        return $widget;
    }

    /** Drag-and-drop persistence: geometry only, one write. */
    public function saveLayout(Dashboard $dashboard, array $positions): void
    {
        DB::transaction(function () use ($dashboard, $positions) {
            $widgets = $dashboard->widgets()->get()->keyBy('id');

            foreach ($positions as $index => $position) {
                $widget = $widgets->get((int) ($position['id'] ?? 0));

                if (! $widget) {
                    continue;
                }

                $widget->update([
                    'position' => [
                        'x' => max(0, (int) ($position['x'] ?? 0)),
                        'y' => max(0, (int) ($position['y'] ?? 0)),
                        'w' => min(Dashboard::GRID_COLUMNS, max(1, (int) ($position['w'] ?? 3))),
                        'h' => max(1, (int) ($position['h'] ?? 2)),
                    ],
                    'sort_order' => $index,
                ]);
            }
        });

        $dashboard->auditAction('dashboard.layout_saved');
    }

    /**
     * Render for one viewer. Every tile is resolved through the registry, so
     * permission filtering happens in the query layer — a denied tile never
     * had its data fetched, it is not hidden at render time.
     *
     * @return array<string, mixed>
     */
    public function render(Dashboard $dashboard, User $user): array
    {
        $widgets = $dashboard->widgets()->where('is_visible', true)->get();

        return [
            'dashboard' => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'slug' => $dashboard->slug,
                'description' => $dashboard->description,
                'visibility' => $dashboard->visibility,
                'is_system' => $dashboard->is_system,
                'refresh_interval_seconds' => $dashboard->refresh_interval_seconds,
                'owner' => $dashboard->owner?->only(['id', 'name']),
            ],
            'widgets' => $widgets->map(fn (DashboardWidget $widget) => [
                'id' => $widget->id,
                'type' => $widget->widget_type,
                'title' => $widget->title,
                'subtitle' => $widget->subtitle,
                'source' => $widget->data_source_key,
                'display' => $widget->display_config ?? [],
                'position' => $widget->geometry(),
                'data' => $this->registry->resolveWidget($user, $widget),
            ])->values()->all(),
        ];
    }

    public function delete(Dashboard $dashboard): void
    {
        if ($dashboard->is_system) {
            throw ValidationException::withMessages([
                'dashboard' => 'A shipped dashboard cannot be deleted. Duplicate it and edit the copy instead.',
            ]);
        }

        $dashboard->auditAction('dashboard.deleted', ['name' => $dashboard->name], null);
        $dashboard->delete();
    }

    private function assertRegisteredSource(array $data): void
    {
        $key = $data['data_source_key'] ?? null;

        if ($key !== null && ! $this->registry->has($key)) {
            throw ValidationException::withMessages([
                'data_source_key' => "'{$key}' is not a registered dashboard data source.",
            ]);
        }
    }

    private function assertWithinWidgetCap(Dashboard $dashboard): void
    {
        $cap = (int) config('dashboards.max_widgets');

        if ($dashboard->widgets()->count() >= $cap) {
            throw ValidationException::withMessages([
                'widgets' => "A dashboard holds at most {$cap} widgets — past that it stops loading usefully on a slow connection.",
            ]);
        }
    }
}
