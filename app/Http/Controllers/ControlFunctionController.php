<?php

namespace App\Http\Controllers;

use App\Models\CheckItem;
use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlFrequency;
use App\Models\TestInstance;
use App\Services\ControlFunctionExportService;
use App\Services\ControlTaskService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CR-03 §E.2: the departmental control function catalogue — unit,
 * function, line count, frequency, next due — and one function's full
 * checklist with the rhythms each line runs to.
 *
 * Thin: generation, triggering and assignment all live in
 * ControlTaskService.
 */
class ControlFunctionController extends Controller
{
    public function __construct(private ControlTaskService $tasks) {}

    public function index(Request $request): Response
    {
        Gate::authorize('control-functions.viewAny');

        $tenantId = $request->user()->tenant_id;

        $functions = Control::query()
            ->controlFunctions()
            ->with([
                'controlUnit:id,code,name,domain',
                // default_officer_id and owner_id must be in the select
                // list or the nested relations below have no key to load
                // on and silently resolve to null (§C.4 assignment chain).
                'homeEntity:id,name,entity_kind,default_officer_id,owner_id',
                'homeEntity.defaultOfficer:id,name',
                'homeEntity.owner:id,name',
                'controlFrequency:id,code,label,cycle,generation_mode',
                'owner:id,name',
            ])
            ->withCount(['controlEntities as entity_count'])
            ->when($request->unit, fn ($q, $unit) => $q->where('control_unit_id', $unit))
            ->when($request->entity, fn ($q, $entity) => $q->whereHas('controlEntities', fn ($e) => $e->where('control_entities.id', $entity)))
            ->when($request->frequency, fn ($q, $code) => $q->whereHas('controlFrequency', fn ($f) => $f->where('code', $code)))
            ->when($request->owner, fn ($q, $owner) => $q->where('owner_id', $owner))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('control_ref', 'like', "%{$s}%")))
            ->orderBy('control_unit_id')
            ->orderBy('control_ref')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Control $control) => $this->summarise($control));

        return Inertia::render('ControlFunctions/Index', [
            'functions' => $functions,
            'filters' => $request->only(['unit', 'entity', 'frequency', 'owner', 'status', 'search']),
            'units' => $this->tasks->unitsWithFunctions($tenantId)->map->only(['id', 'code', 'name', 'domain'])->values(),
            'frequencies' => ControlFrequency::query()->active()->orderBy('sequence')
                ->get(['id', 'code', 'label', 'cycle', 'generation_mode'])->values(),
            'summary' => $this->summaryTiles($tenantId),
            'can' => [
                'manage' => Gate::allows('control-functions.manage'),
                'import' => Gate::allows('control-functions.import'),
            ],
        ]);
    }

    public function show(Control $control): Response
    {
        Gate::authorize('control-functions.view', $control);

        $control->load([
            'controlUnit:id,code,name,domain',
            'homeEntity:id,name,entity_kind,default_officer_id,owner_id',
            'homeEntity.defaultOfficer:id,name',
            'homeEntity.owner:id,name',
            'controlFrequency',
            'owner:id,name',
            'controlEntities:id,name,entity_kind,control_unit_id',
            'controlEntities.defaultOfficer:id,name',
        ]);

        $scripts = $control->testScripts()
            ->with(['checkItems' => fn ($q) => $q->orderBy('sequence'), 'checkItems.controlFrequency:id,code,label,cycle'])
            ->orderByDesc('version_no')
            ->get();

        $active = $scripts->firstWhere('status', 'Active') ?? $scripts->first();

        return Inertia::render('ControlFunctions/Show', [
            'function' => [
                ...$this->summarise($control),
                'description' => $control->description,
                'type' => $control->type,
                'nature' => $control->nature,
                'source_ref' => $control->source_ref,
            ],
            'rhythms' => $this->tasks->rhythmsFor($control, $active)
                ->map->only(['id', 'code', 'label', 'cycle', 'generation_mode'])->values(),
            'checklist' => $active?->checkItems->map(fn (CheckItem $item) => [
                'id' => $item->id,
                'sequence' => $item->sequence,
                'question' => $item->question,
                'is_mandatory' => $item->is_mandatory,
                'source_ref' => $item->source_ref,
                // NULL means the line inherits — show the function's own
                // rhythm rather than a blank chip.
                'frequency' => $item->controlFrequency?->only(['code', 'label', 'cycle'])
                    ?? $control->controlFrequency?->only(['code', 'label', 'cycle']),
                'frequency_raw' => $item->frequency_raw,
                'is_override' => $item->frequency_id !== null,
            ])->values() ?? [],
            'versions' => $scripts->map(fn ($script) => [
                'id' => $script->id,
                'version_no' => $script->version_no,
                'status' => $script->status,
                'items' => $script->checkItems->count(),
                'approved_at' => $script->approved_at?->toDateString(),
            ])->values(),
            'entities' => $control->controlEntities->map(fn (ControlEntity $entity) => [
                'id' => $entity->id,
                'name' => $entity->name,
                'entity_kind' => $entity->entity_kind,
                'officer' => $entity->defaultOfficer?->name,
            ])->values(),
            'instances' => TestInstance::query()
                ->where('control_id', $control->id)
                ->with(['controlEntity:id,name', 'frequency:id,code,label', 'tester:id,name'])
                ->orderByDesc('period_start')
                ->limit(30)
                ->get()
                ->map(fn (TestInstance $instance) => [
                    'id' => $instance->id,
                    'reference' => $instance->reference,
                    'period_label' => $instance->period_label,
                    'status' => $instance->status,
                    'due_date' => $instance->due_date?->toDateString(),
                    'is_overdue' => $instance->is_overdue,
                    'entity' => $instance->controlEntity?->name,
                    'frequency' => $instance->frequency?->label,
                    'tester' => $instance->tester?->name,
                ])->values(),
            'can' => [
                'manage' => Gate::allows('control-functions.manage', $control),
                'trigger' => Gate::allows('control-functions.trigger', $control)
                    && $control->resolvedFrequency()?->isEventDriven() === true,
            ],
        ]);
    }

    /**
     * §C.5: raise an occurrence of an event-driven function — the Trigger
     * button. The instance records what fired it.
     */
    public function trigger(Request $request, Control $control): RedirectResponse
    {
        Gate::authorize('control-functions.trigger', $control);

        $data = $request->validate([
            'control_entity_id' => ['nullable', 'integer', 'exists:control_entities,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $entity = $data['control_entity_id']
            ? ControlEntity::query()->findOrFail($data['control_entity_id'])
            : null;

        $instance = $this->tasks->raiseEventInstance(
            $control,
            $entity,
            ['reason' => $data['reason']],
            $request->user(),
        );

        return back()->with('success', "Raised {$instance->reference} — {$control->title}.");
    }

    /** §E.4 report 2: expected vs actual, the examiner's question. */
    public function compliance(Request $request): Response
    {
        Gate::authorize('control-functions.viewAny');

        $from = CarbonImmutable::parse($request->input('from', now()->subDays(30)->toDateString()));
        $to = CarbonImmutable::parse($request->input('to', now()->toDateString()));

        return Inertia::render('ControlFunctions/Compliance', [
            'rows' => $this->tasks->frequencyCompliance($request->user()->tenant_id, $from, $to),
            'units' => $this->tasks->completionByUnit($request->user()->tenant_id, $from, $to),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    /**
     * §E.4 report 5 — the round trip. The register goes back out in the
     * client's own workbook layout, so the document they gave us stays
     * the document they recognise.
     */
    public function export(Request $request, ControlFunctionExportService $exporter)
    {
        Gate::authorize('control-functions.viewAny');

        return $exporter->stream($request->user()->tenant_id);
    }

    private function summarise(Control $control): array
    {
        // withCount() supplies this on the catalogue; the detail page has
        // no such count, so resolve it once and use it for BOTH the
        // displayed number and the shared-function test. Reading the raw
        // attribute for one and the resolved value for the other is how
        // a branch template ends up reporting three branches and no
        // officer in the same header.
        $entityCount = $control->entity_count ?? $control->controlEntities()->count();

        return [
            'id' => $control->id,
            'reference' => $control->control_ref,
            'title' => $control->title,
            'status' => $control->status,
            'unit' => $control->controlUnit?->only(['id', 'code', 'name', 'domain']),
            'entity' => $control->homeEntity?->only(['id', 'name', 'entity_kind']),
            'entity_count' => $entityCount,
            'frequency' => $control->controlFrequency?->only(['code', 'label', 'cycle', 'generation_mode']),
            // The client's own wording, shown alongside ours. "Frequency of
            // Activity" is the column they expect to recognise.
            'frequency_raw' => $control->frequency_raw,
            // §C.4: the person who performs it, not a stale snapshot.
            // A branch template has many performers and no single owner,
            // so it reports the count instead of a name.
            'owner' => $control->effective_owner?->only(['id', 'name']),
            'is_shared' => $control->control_entity_id === null && $entityCount > 1,
            'line_count' => $control->activeTestScript()?->checkItems()->count() ?? 0,
            'next_due' => TestInstance::query()
                ->where('control_id', $control->id)
                ->whereNotIn('status', ['Reviewed', 'Closed'])
                ->whereNotNull('due_date')
                ->min('due_date'),
        ];
    }

    /** @return array<string, int|float> */
    private function summaryTiles(int $tenantId): array
    {
        $functions = Control::query()->controlFunctions()->where('status', 'Active')->count();

        $lines = (int) DB::table('check_items')
            ->join('test_scripts', 'test_scripts.id', '=', 'check_items.test_script_id')
            ->join('controls', 'controls.id', '=', 'test_scripts.control_id')
            ->where('controls.tenant_id', $tenantId)
            ->where('controls.is_control_function', true)
            ->where('test_scripts.status', 'Active')
            ->whereNull('check_items.deleted_at')
            ->count();

        $open = TestInstance::query()
            ->whereHas('control', fn ($q) => $q->where('is_control_function', true))
            ->whereNotIn('status', ['Reviewed', 'Closed']);

        return [
            'functions' => $functions,
            'lines' => $lines,
            'open_tasks' => (clone $open)->count(),
            'due_today' => (clone $open)->whereDate('due_date', now()->toDateString())->count(),
            'overdue' => (clone $open)->overdue()->count(),
        ];
    }
}
