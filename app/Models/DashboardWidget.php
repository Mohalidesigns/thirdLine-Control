<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardWidget extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const TYPES = [
        'stat', 'line', 'bar', 'stacked_bar', 'horizontal_bar', 'pie', 'donut',
        'heatmap', 'gauge', 'table', 'tree', 'timeline', 'progress', 'sparkline',
        'map', 'scorecard', 'text',
    ];

    protected $fillable = [
        'tenant_id', 'dashboard_id', 'widget_type', 'title', 'subtitle',
        'data_source_key', 'query_config', 'display_config', 'drill_down_config',
        'position', 'refresh_seconds', 'permission_required', 'is_visible', 'sort_order',
    ];

    protected $casts = [
        'query_config' => 'array',
        'display_config' => 'array',
        'drill_down_config' => 'array',
        'position' => 'array',
        'refresh_seconds' => 'integer',
        'is_visible' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'widget_type' => 'stat',
    ];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /** Grid geometry, defaulted so a widget saved without one still lays out. */
    public function geometry(): array
    {
        $position = $this->position ?? [];

        return [
            'x' => max(0, (int) ($position['x'] ?? 0)),
            'y' => max(0, (int) ($position['y'] ?? 0)),
            'w' => min(Dashboard::GRID_COLUMNS, max(1, (int) ($position['w'] ?? 3))),
            'h' => max(1, (int) ($position['h'] ?? 2)),
        ];
    }
}
