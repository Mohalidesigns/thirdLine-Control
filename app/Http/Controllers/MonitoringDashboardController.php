<?php

namespace App\Http\Controllers;

use App\Models\Control;
use App\Models\DataSource;
use App\Models\MonitoringRule;
use App\Services\MonitoringService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The CCM dashboard (12.6). The question it answers is the one a Chief
 * Control Officer actually asks: which of my key controls are monitored
 * continuously, and which are still tested once a quarter by a person
 * with a spreadsheet?
 */
class MonitoringDashboardController extends Controller
{
    public function __construct(private MonitoringService $monitoring) {}

    public function __invoke(Request $request): Response
    {
        $this->authorize('viewAny', MonitoringRule::class);

        $tenantId = (int) $request->user()->tenant_id;

        return Inertia::render('Monitoring/Dashboard', [
            'dashboard' => $this->monitoring->dashboard($tenantId),
            'sources' => DataSource::query()
                ->with('owner:id,name')
                ->orderByRaw("case health_status when 'Failed' then 1 when 'Degraded' then 2 when 'Unknown' then 3 else 4 end")
                ->limit(10)
                ->get(['id', 'name', 'vendor_key', 'health_status', 'last_sync_at', 'last_sync_status', 'consecutive_failures', 'paused_at', 'owner_id']),
            'uncoveredKeyControls' => Control::query()
                ->where('status', 'Active')
                ->where('is_key_control', true)
                ->whereDoesntHave('monitoringRules', fn ($q) => $q->where('status', 'Active'))
                ->orderBy('control_ref')
                ->limit(15)
                ->get(['id', 'control_ref', 'title', 'frequency']),
            'can' => [
                'manage_sources' => $request->user()->can('manage data-sources'),
                'manage_rules' => $request->user()->can('manage monitoring-rules'),
            ],
        ]);
    }
}
