<?php

namespace App\Http\Controllers;

use App\Models\DataSnapshot;
use App\Models\MonitoringRule;
use App\Models\MonitoringRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringRunController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MonitoringRule::class);

        $runs = MonitoringRun::query()
            ->with(['rule:id,rule_ref,name,severity,rule_type', 'testInstance:id,reference,status'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->rule_id, fn ($q, $r) => $q->where('rule_id', $r))
            ->when($request->boolean('failures_only'), fn ($q) => $q->where('records_failed', '>', 0))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Monitoring/Runs/Index', [
            'runs' => $runs,
            'filters' => $request->only(['status', 'rule_id', 'failures_only']),
            'statuses' => MonitoringRun::STATUSES,
            'rules' => MonitoringRule::query()->orderBy('rule_ref')->get(['id', 'rule_ref', 'name']),
            'stats' => [
                'today' => MonitoringRun::whereDate('started_at', now()->toDateString())->count(),
                'failed' => MonitoringRun::where('status', 'Failed')->count(),
                'with_findings' => MonitoringRun::where('records_failed', '>', 0)->count(),
            ],
        ]);
    }

    public function show(Request $request, MonitoringRun $run): Response
    {
        $this->authorize('view', $run->rule);

        $run->load([
            'rule:id,rule_ref,name,rule_type,severity,control_id,sample_config',
            'rule.control:id,control_ref,title',
            'testInstance:id,reference,status,period_label',
            'triggeredBy:id,name',
        ]);

        return Inertia::render('Monitoring/Runs/Show', [
            'run' => $run,
            'findings' => $run->findings()
                ->with(['reviewer:id,name', 'exception:id,reference,status'])
                ->orderByRaw("case severity when 'Critical' then 1 when 'High' then 2 when 'Medium' then 3 else 4 end")
                ->orderBy('id')
                ->paginate(25)
                ->withQueryString(),
            'snapshots' => DataSnapshot::query()
                ->whereIn('id', (array) ($run->snapshot_ids ?? []))
                ->with('dataset:id,name,pii_classification')
                ->get(['id', 'dataset_id', 'snapshot_ref', 'captured_at', 'row_count', 'checksum', 'status', 'legal_hold']),
            'can' => [
                'review' => $request->user()->can('review monitoring-findings'),
            ],
        ]);
    }
}
