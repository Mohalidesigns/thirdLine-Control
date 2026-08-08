<?php

namespace App\Http\Controllers;

use App\Http\Requests\MonitoringRuleRequest;
use App\Models\Control;
use App\Models\DataSourceDataset;
use App\Models\MonitoringRule;
use App\Models\User;
use App\Services\MonitoringService;
use App\Services\RuleEngineService;
use App\Services\SamplingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringRuleController extends Controller
{
    public function __construct(
        private MonitoringService $monitoring,
        private RuleEngineService $engine,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MonitoringRule::class);

        $rules = MonitoringRule::query()
            ->with(['control:id,control_ref,title,is_key_control', 'owner:id,name', 'approver:id,name'])
            ->withCount('runs')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->rule_type, fn ($q, $t) => $q->where('rule_type', $t))
            ->when($request->severity, fn ($q, $s) => $q->where('severity', $s))
            ->when($request->control_id, fn ($q, $c) => $q->where('control_id', $c))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('rule_ref', 'like', "%{$s}%")))
            ->orderBy('rule_ref')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Monitoring/Rules/Index', [
            'rules' => $rules,
            'filters' => $request->only(['status', 'rule_type', 'severity', 'control_id', 'search']),
            'options' => [
                'types' => MonitoringRule::TYPES,
                'statuses' => MonitoringRule::STATUSES,
                'frequencies' => MonitoringRule::FREQUENCIES,
                'severities' => ['Critical', 'High', 'Medium', 'Low'],
                'sampling_methods' => SamplingService::METHODS,
                'operators' => RuleEngineService::OPERATORS,
                'aggregates' => RuleEngineService::AGGREGATES,
                'patterns' => RuleEngineService::PATTERNS,
            ],
            'schemas' => RuleEngineService::schemas(),
            'datasets' => DataSourceDataset::query()
                ->active()
                ->with('dataSource:id,name,vendor_key')
                ->orderBy('name')
                ->get(['id', 'data_source_id', 'name', 'pii_classification'])
                ->map(fn (DataSourceDataset $dataset) => [
                    'id' => $dataset->id,
                    'name' => $dataset->name,
                    'source' => $dataset->dataSource?->name,
                    'pii_classification' => $dataset->pii_classification,
                    'table_name' => $this->engine->tableNameFor($dataset->id, $dataset),
                ]),
            'controls' => Control::query()
                ->where('status', 'Active')
                ->orderBy('control_ref')
                ->get(['id', 'control_ref', 'title', 'is_key_control']),
            'owners' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stats' => [
                'active' => MonitoringRule::where('status', 'Active')->count(),
                'pending' => MonitoringRule::where('status', 'Pending Approval')->count(),
                'paused' => MonitoringRule::where('status', 'Paused')->count(),
            ],
            'can' => [
                'manage' => $request->user()->can('manage monitoring-rules'),
                'approve' => $request->user()->can('approve monitoring-rules'),
            ],
        ]);
    }

    public function store(MonitoringRuleRequest $request): RedirectResponse
    {
        $this->authorize('create', MonitoringRule::class);

        $rule = $this->monitoring->createRule($request->validated(), $request->user());

        return redirect()
            ->route('monitoring-rules.show', $rule)
            ->with('success', "Rule {$rule->rule_ref} created. It needs a second person's approval before it runs.");
    }

    public function show(Request $request, MonitoringRule $rule): Response
    {
        $this->authorize('view', $rule);

        $rule->load(['control:id,control_ref,title,owner_id', 'owner:id,name', 'creator:id,name', 'approver:id,name']);

        return Inertia::render('Monitoring/Rules/Show', [
            'rule' => $rule,
            'datasets' => $rule->datasets()->map(fn (DataSourceDataset $dataset) => [
                'id' => $dataset->id,
                'name' => $dataset->name,
                'source' => $dataset->dataSource?->name,
                'health' => $dataset->dataSource?->health_status,
                'pii_classification' => $dataset->pii_classification,
                'table_name' => $this->engine->tableNameFor($dataset->id, $dataset),
            ])->values(),
            'schema' => RuleEngineService::schemas()[$rule->rule_type] ?? null,
            'runs' => $rule->runs()
                ->with('testInstance:id,reference,status')
                ->orderByDesc('id')
                ->paginate(10)
                ->withQueryString(),
            'falsePositives' => $this->monitoring->falsePositiveRate($rule),
            'versions' => $rule->objectVersions()->with('author:id,name')->limit(10)->get(),
            'can' => [
                'manage' => $request->user()->can('update', $rule),
                'submit' => $request->user()->can('submit', $rule),
                'approve' => $request->user()->can('approve', $rule),
                'run' => $request->user()->can('run', $rule),
                'retire' => $request->user()->can('retire', $rule),
            ],
        ]);
    }

    public function update(MonitoringRuleRequest $request, MonitoringRule $rule): RedirectResponse
    {
        $this->authorize('update', $rule);

        $updated = $this->monitoring->updateRule($rule, $request->validated(), $request->user());

        return back()->with('success', $updated->status === 'Pending Approval' && $rule->status === 'Active'
            ? "Rule {$rule->rule_ref} updated. Because what it tests changed, it is paused pending re-approval."
            : "Rule {$rule->rule_ref} updated.");
    }

    public function submit(Request $request, MonitoringRule $rule): RedirectResponse
    {
        $this->authorize('submit', $rule);

        $this->monitoring->submitRule($rule, $request->user());

        return back()->with('success', 'Submitted for approval.');
    }

    public function approve(Request $request, MonitoringRule $rule): RedirectResponse
    {
        $this->authorize('approve', $rule);

        $this->monitoring->approveRule($rule, $request->user());

        return back()->with('success', "Rule {$rule->rule_ref} approved and active.");
    }

    public function reject(Request $request, MonitoringRule $rule): RedirectResponse
    {
        $this->authorize('approve', $rule);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->monitoring->rejectRule($rule, $request->user(), $validated['reason']);

        return back()->with('success', 'Rule returned to draft.');
    }

    public function pause(Request $request, MonitoringRule $rule): RedirectResponse
    {
        $this->authorize('pause', $rule);

        $validated = $request->validate([
            'paused' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->monitoring->setPaused($rule, $request->user(), $validated['paused'], $validated['reason'] ?? null);

        return back()->with('success', $validated['paused'] ? 'Rule paused.' : 'Rule resumed.');
    }

    public function retire(Request $request, MonitoringRule $rule): RedirectResponse
    {
        $this->authorize('retire', $rule);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->monitoring->retireRule($rule, $request->user(), $validated['reason']);

        return redirect()->route('monitoring-rules.index')->with('success', "Rule {$rule->rule_ref} retired.");
    }

    /**
     * Run now. `capture=false` re-evaluates the stored snapshot, which is
     * what testing a rule change wants — same data, different rule.
     */
    public function run(Request $request, MonitoringRule $rule): RedirectResponse
    {
        $this->authorize('run', $rule);

        $validated = $request->validate(['capture' => ['nullable', 'boolean']]);

        try {
            $run = $this->monitoring->runRule(
                $rule,
                'manual',
                $request->user(),
                (bool) ($validated['capture'] ?? false),
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return back()->with('error', 'The run failed: '.$e->getMessage());
        }

        return redirect()
            ->route('monitoring-runs.show', $run)
            ->with('success', sprintf(
                '%s completed — %d of %d record(s) failed.',
                $run->run_ref, $run->records_failed, $run->records_evaluated,
            ));
    }
}
