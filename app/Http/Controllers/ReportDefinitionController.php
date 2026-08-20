<?php

namespace App\Http\Controllers;

use App\Dashboards\WidgetRegistry;
use App\Http\Requests\ReportDefinitionRequest;
use App\Models\Framework;
use App\Models\OrganisationUnit;
use App\Models\ReportDefinition;
use App\Services\ReportDesignerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportDefinitionController extends Controller
{
    public function __construct(
        private ReportDesignerService $designer,
        private WidgetRegistry $registry,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ReportDefinition::class);

        $user = $request->user();

        return Inertia::render('Reports/Library', [
            'definitions' => ReportDefinition::query()
                ->with(['owner:id,name', 'approver:id,name', 'regulator:id,code'])
                ->withCount('schedules')
                ->when($request->report_type, fn ($q, $t) => $q->where('report_type', $t))
                ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%")))
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->paginate(15)
                ->withQueryString(),
            'filters' => $request->only(['report_type', 'search']),
            'types' => ReportDefinition::TYPES,
            'formats' => $this->designer->formats(),
            'can' => [
                'design' => $user->can('design reports'),
                'approve' => $user->can('approve report-definitions'),
                'schedule' => $user->can('schedule reports'),
            ],
        ]);
    }

    public function show(Request $request, ReportDefinition $definition): Response
    {
        $this->authorize('view', $definition);

        $definition->load(['owner:id,name', 'approver:id,name', 'regulator:id,code']);

        return Inertia::render('Reports/Definition', [
            'definition' => $definition,
            'runs' => $definition->runs()
                ->with('requester:id,name')
                ->orderByDesc('id')
                ->paginate(10)
                ->withQueryString(),
            'parameterOptions' => $this->parameterOptions(),
            'can' => [
                'run' => $request->user()->can('run', $definition),
                'design' => $request->user()->can('update', $definition),
                'approve' => $request->user()->can('approve', $definition),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', ReportDefinition::class);

        return Inertia::render('Reports/Designer', $this->designerProps($request) + ['definition' => null]);
    }

    public function edit(Request $request, ReportDefinition $definition): Response
    {
        $this->authorize('update', $definition);

        return Inertia::render('Reports/Designer', $this->designerProps($request) + [
            'definition' => $definition->only([
                'id', 'code', 'name', 'description', 'report_type', 'confidentiality',
                'output_formats', 'sections', 'parameters', 'styling',
                'cover_page_config', 'header_config', 'footer_config', 'is_active',
                'version_no', 'approved_at',
            ]),
        ]);
    }

    public function store(ReportDefinitionRequest $request): RedirectResponse
    {
        $this->authorize('create', ReportDefinition::class);

        $definition = $this->designer->createDefinition($request->validated(), $request->user());

        return redirect()
            ->route('reports.definitions.edit', $definition)
            ->with('success', "'{$definition->name}' created. It needs a second person's approval before it is a signed-off layout.");
    }

    public function update(ReportDefinitionRequest $request, ReportDefinition $definition): RedirectResponse
    {
        $this->authorize('update', $definition);

        $wasApproved = $definition->approved_at !== null;
        $this->designer->updateDefinition($definition, $request->validated());

        return back()->with('success', $wasApproved && $definition->approved_at === null
            ? 'Saved. Because the layout changed, the previous approval no longer stands.'
            : 'Report definition saved.');
    }

    public function approve(Request $request, ReportDefinition $definition): RedirectResponse
    {
        $this->authorize('approve', $definition);

        $this->designer->approveDefinition($definition, $request->user());

        return back()->with('success', "Version {$definition->version_no} approved.");
    }

    public function destroy(ReportDefinition $definition): RedirectResponse
    {
        $this->authorize('delete', $definition);

        $definition->auditAction('report_definition.deleted', ['code' => $definition->code], null);
        $definition->delete();

        return redirect()->route('reports.library')->with('success', 'Report definition deleted.');
    }

    private function designerProps(Request $request): array
    {
        return [
            'sectionTypes' => ReportDefinition::SECTION_TYPES,
            'types' => ReportDefinition::TYPES,
            'formats' => $this->designer->formats(),
            'confidentialities' => ReportDefinition::CONFIDENTIALITY,
            'sources' => $this->registry->availableTo($request->user()),
            'parameterOptions' => $this->parameterOptions(),
        ];
    }

    private function parameterOptions(): array
    {
        return [
            'periods' => [
                'current_quarter' => 'Current quarter',
                'last_quarter' => 'Last quarter',
                'current_month' => 'Current month',
                'last_month' => 'Last month',
                'current_year' => 'Current financial year',
                'last_year' => 'Last financial year',
            ],
            'entities' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'frameworks' => Framework::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }
}
