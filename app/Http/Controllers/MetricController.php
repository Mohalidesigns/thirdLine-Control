<?php

namespace App\Http\Controllers;

use App\Http\Requests\MetricRequest;
use App\Http\Requests\MetricValueRequest;
use App\Models\Metric;
use App\Models\MetricBreach;
use App\Models\MetricThreshold;
use App\Models\MetricValue;
use App\Models\OrganisationUnit;
use App\Models\RiskCategory;
use App\Models\User;
use App\Services\LinkageService;
use App\Services\MetricService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MetricController extends Controller
{
    public function __construct(
        private MetricService $metrics,
        private LinkageService $linkage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Metric::class);

        $query = Metric::query()
            ->with(['owner:id,name', 'category:id,name', 'entity:id,name'])
            ->when($request->metric_type, fn ($q, $t) => $q->where('metric_type', $t))
            ->when($request->category_id, fn ($q, $c) => $q->where('category_id', $c))
            ->when($request->owner_id, fn ($q, $o) => $q->where('owner_id', $o))
            ->when($request->boolean('breached_only'), fn ($q) => $q->whereHas('breaches', fn ($b) => $b->unresolved()))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")));

        $metrics = $query->orderBy('code')->paginate(15)->withQueryString();

        // Latest reading and sparkline series in two queries, not one per row.
        $latest = MetricValue::query()
            ->whereIn('metric_id', $metrics->pluck('id'))
            ->orderBy('period_start')
            ->get()
            ->groupBy('metric_id')
            ->map(fn ($values) => $values->last());

        return Inertia::render('Metrics/Index', [
            'metrics' => $metrics->through(fn (Metric $metric) => [
                ...$metric->toArray(),
                'latest' => $latest->get($metric->id),
            ]),
            'sparklines' => $this->metrics->sparklines($metrics->getCollection()),
            'filters' => $request->only(['metric_type', 'category_id', 'owner_id', 'breached_only', 'search']),
            'types' => Metric::TYPES,
            'categories' => RiskCategory::query()->orderBy('sort_order')->get(['id', 'code', 'name']),
            'owners' => User::tenantPicker()->get(['id', 'name']),
            'entities' => OrganisationUnit::query()->orderBy('name')->get(['id', 'name']),
            'levels' => MetricThreshold::LEVELS,
            'operators' => MetricThreshold::OPERATORS,
            'options' => [
                'data_types' => Metric::DATA_TYPES,
                'directions' => Metric::DIRECTIONS,
                'frequencies' => Metric::FREQUENCIES,
                'sources' => Metric::SOURCES,
                'aggregations' => Metric::AGGREGATIONS,
            ],
            'stats' => [
                'active' => Metric::active()->count(),
                'breaching' => MetricBreach::unresolved()->distinct('metric_id')->count('metric_id'),
                'unacknowledged' => MetricBreach::unresolved()->whereNull('acknowledged_at')->count(),
            ],
            'can' => [
                'manage' => $request->user()->can('manage metrics'),
                'capture' => $request->user()->can('capture metrics'),
            ],
        ]);
    }

    public function store(MetricRequest $request): RedirectResponse
    {
        $this->authorize('create', Metric::class);

        $data = $request->validated();
        $thresholds = $data['thresholds'] ?? [];
        unset($data['thresholds']);

        $metric = Metric::create($data);
        $this->syncThresholds($metric, $thresholds);

        return redirect()->route('metrics.show', $metric)->with('success', "Metric {$metric->code} created.");
    }

    public function show(Request $request, Metric $metric): Response
    {
        $this->authorize('view', $metric);

        $metric->load(['owner:id,name', 'category:id,name', 'entity:id,name', 'thresholds']);

        return Inertia::render('Metrics/Show', [
            'metric' => $metric,
            'trend' => $this->metrics->trend($metric, 36),
            'period' => $this->metrics->periodFor($metric)['label'],
            'values' => $metric->values()
                ->reorder()
                ->orderByDesc('period_start')
                ->with('capturer:id,name')
                ->paginate(12)
                ->withQueryString(),
            'breaches' => $metric->breaches()
                ->with(['acknowledger:id,name', 'exception:id,reference,title,status'])
                ->orderByDesc('detected_at')
                ->limit(20)
                ->get(),
            'links' => $this->linkage->neighbours('metric', $metric->id),
            'can' => [
                'manage' => $request->user()->can('update', $metric),
                'capture' => $request->user()->can('capture', $metric),
                'acknowledge' => $request->user()->can('acknowledgeBreach', $metric),
            ],
        ]);
    }

    public function update(MetricRequest $request, Metric $metric): RedirectResponse
    {
        $this->authorize('update', $metric);

        $data = $request->validated();
        $thresholds = $data['thresholds'] ?? null;
        unset($data['thresholds']);

        $metric->update($data);

        if ($thresholds !== null) {
            $this->syncThresholds($metric, $thresholds);
        }

        return back()->with('success', "Metric {$metric->code} updated.");
    }

    /** Record a reading; threshold evaluation and breach opening follow. */
    public function capture(MetricValueRequest $request, Metric $metric): RedirectResponse
    {
        $this->authorize('capture', $metric);

        $value = $this->metrics->capture($metric, $request->validated(), $request->user());

        return back()->with('success', $value->breach_flag
            ? "Reading recorded — {$value->threshold_level_hit} breach raised for {$value->period_label}."
            : "Reading recorded for {$value->period_label}.");
    }

    public function acknowledgeBreach(Request $request, Metric $metric, MetricBreach $breach): RedirectResponse
    {
        $this->authorize('acknowledgeBreach', $metric);
        abort_unless($breach->metric_id === $metric->id, 404);

        $validated = $request->validate([
            'root_cause' => ['required', 'string', 'max:2000'],
            'action_taken' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->metrics->acknowledgeBreach($breach, $request->user(), $validated);

        return back()->with('success', 'Breach acknowledged.');
    }

    public function resolveBreach(Request $request, Metric $metric, MetricBreach $breach): RedirectResponse
    {
        $this->authorize('acknowledgeBreach', $metric);
        abort_unless($breach->metric_id === $metric->id, 404);

        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $this->metrics->resolveBreach($breach, $request->user(), $validated['note']);

        return back()->with('success', 'Breach resolved.');
    }

    /** Threshold bands are replaced wholesale — the order is the priority. */
    private function syncThresholds(Metric $metric, array $thresholds): void
    {
        $metric->thresholds()->delete();

        foreach ($thresholds as $index => $threshold) {
            $metric->thresholds()->create([
                'tenant_id' => $metric->tenant_id,
                'level' => $threshold['level'],
                'operator' => $threshold['operator'],
                'value_from' => $threshold['value_from'],
                'value_to' => $threshold['value_to'] ?? null,
                'colour_hex' => $threshold['colour_hex'] ?? '#0ca30c',
                'action_required' => $threshold['action_required'] ?? null,
                'escalate_to_role' => $threshold['escalate_to_role'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }
}
