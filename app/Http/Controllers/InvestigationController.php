<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArchiveInvestigationRequest;
use App\Http\Requests\CompleteInvestigationRequest;
use App\Http\Requests\StoreInvestigationRequest;
use App\Http\Requests\UpdateInvestigationRequest;
use App\Models\ConsequenceAction;
use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\Investigation;
use App\Models\InvestigationActivity;
use App\Models\InvestigationFinding;
use App\Models\InvestigationSubject;
use App\Models\InvestigationTeamMember;
use App\Models\OrganisationUnit;
use App\Models\User;
use App\Services\InvestigationReportBuilder;
use App\Services\InvestigationService;
use App\Services\LinkageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The investigation register (CR-04).
 *
 * Thin by design: authorize → Form Request → service → Inertia::render.
 * Every workflow rule lives in InvestigationService, because a rule in a
 * controller is a rule the next entry point forgets.
 *
 * Every query here inherits the model's `visibility` global scope, so an
 * index is a list of what the viewer may see rather than a list with rows
 * removed afterwards, and a count on this page never counts a case its
 * reader cannot open.
 */
class InvestigationController extends Controller
{
    public function __construct(
        private InvestigationService $investigations,
        private InvestigationReportBuilder $reports,
        private LinkageService $linkage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Investigation::class);

        $user = $request->user();

        $query = Investigation::query()
            ->with(['leadInvestigator:id,name', 'controlEntity:id,name'])
            ->withCount(['subjects', 'findings', 'consequenceActions'])
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->active())
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->category, fn ($q, $category) => $q->where('category', $category))
            ->when($request->priority, fn ($q, $priority) => $q->where('priority', $priority))
            ->when($request->risk_rating, fn ($q, $rating) => $q->where('risk_rating', $rating))
            ->when($request->control_entity_id, fn ($q, $id) => $q->where('control_entity_id', $id))
            ->when($request->boolean('open_only'), fn ($q) => $q->open())
            ->when($request->boolean('mine'), fn ($q) => $q->where('lead_investigator_id', $user->id))
            ->when($request->search, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$term}%")
                ->orWhere('reference', 'like', "%{$term}%")));

        return Inertia::render('Investigations/Index', [
            'investigations' => $query->orderByDesc('reported_date')->orderByDesc('id')
                ->paginate(15)->withQueryString(),
            'filters' => $request->only([
                'status', 'category', 'priority', 'risk_rating', 'control_entity_id',
                'open_only', 'mine', 'include_archived', 'search',
            ]),
            'options' => $this->options(),
            // Counted over the same visibility as the list — a stat that
            // sees more than the table under it is a leak.
            'stats' => [
                'open' => Investigation::query()->active()->open()->count(),
                'under_investigation' => Investigation::query()->active()
                    ->where('status', 'under_investigation')->count(),
                'confidential' => Investigation::query()->active()
                    ->where('is_confidential', true)->count(),
                'overdue' => Investigation::query()->active()->open()
                    ->whereNotNull('target_completion_date')
                    ->whereDate('target_completion_date', '<', now()->toDateString())
                    ->count(),
            ],
            'can' => [
                'create' => $user->can('create', Investigation::class),
                'view_dashboard' => $user->can('view investigation-dashboard'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Investigation::class);

        $origin = $this->resolveOrigin($request->query('origin_type'), $request->query('origin_id'));

        return Inertia::render('Investigations/Create', [
            'options' => $this->options(full: true),
            'prefill' => $origin ? $this->prefillFrom($request->query('origin_type'), $origin) : null,
        ]);
    }

    public function store(StoreInvestigationRequest $request): RedirectResponse
    {
        $this->authorize('create', Investigation::class);

        $data = $request->validated();
        $origin = $this->resolveOrigin($data['origin_type'] ?? null, $data['origin_id'] ?? null);

        $investigation = $this->investigations->open($data, $request->user(), $origin);

        return redirect()
            ->route('investigations.show', $investigation)
            ->with('success', "Investigation {$investigation->reference} opened.");
    }

    public function show(Request $request, Investigation $investigation): Response
    {
        $this->authorize('view', $investigation);

        // §D.3. Reading a confidential case file is itself an event: it
        // lands on the case timeline as well as the audit trail, because
        // an access log nobody opens is not oversight.
        $this->investigations->recordAccess($investigation, $request->user());

        $investigation->load([
            'leadInvestigator:id,name', 'creator:id,name', 'archiver:id,name',
            'controlEntity:id,name,entity_kind', 'organisationUnit:id,name',
            'teamMembers.user:id,name,email',
            'subjects.user:id,name', 'subjects.outcomeRecorder:id,name', 'subjects.organisationUnit:id,name',
            'findings.control:id,control_ref,title', 'findings.exception:id,reference,title',
            'findings.improvementAction:id,reference,status,due_at', 'findings.raiser:id,name',
            'consequenceActions.subject:id,name', 'consequenceActions.recommender:id,name',
            'consequenceActions.approver:id,name', 'consequenceActions.improvementAction:id,reference,status',
            'evidence.uploader:id,name', 'evidence.collector:id,name',
            'activities.performer:id,name',
        ]);

        $user = $request->user();

        return Inertia::render('Investigations/Show', [
            'investigation' => $investigation,
            'origin' => $this->originSummary($investigation),
            'links' => $this->linkage->neighbours('investigation', $investigation->id),
            'reports' => $this->reports->runsFor($investigation),
            'hasReport' => $this->reports->hasReport($investigation),
            'options' => $this->options(full: true),
            'users' => User::tenantPicker()->get(['id', 'name']),
            'manualActivityTypes' => InvestigationActivity::MANUAL_TYPES,
            'can' => [
                'update' => $user->can('update', $investigation),
                'assign' => $user->can('assign', $investigation),
                'complete' => $user->can('complete', $investigation),
                'archive' => $user->can('archive', $investigation),
                'unarchive' => $user->can('unarchive', $investigation),
                'delete' => $user->can('delete', $investigation),
                'consequences' => $user->can('recommendConsequence', $investigation),
                'report' => $user->can('generateReport', $investigation),
                'change_confidentiality' => $user->can('changeConfidentiality', $investigation),
            ],
        ]);
    }

    public function edit(Request $request, Investigation $investigation): Response
    {
        $this->authorize('update', $investigation);

        return Inertia::render('Investigations/Edit', [
            'investigation' => $investigation->load('controlEntity:id,name'),
            'options' => $this->options(full: true),
            'can' => [
                'change_confidentiality' => $request->user()->can('changeConfidentiality', $investigation),
            ],
        ]);
    }

    public function update(UpdateInvestigationRequest $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('update', $investigation);

        $data = $request->validated();

        // §D.3-1. Inherited confidentiality cannot be lowered by the
        // investigating team — the protection belongs to a reporter who is
        // not on the team and cannot argue for it.
        if ($investigation->confidentiality_locked || ! $request->user()->can('changeConfidentiality', $investigation)) {
            unset($data['is_confidential']);
        }

        $investigation->update([...$data, 'updated_by' => $request->user()->id]);

        return back()->with('success', 'Investigation updated.');
    }

    public function destroy(Investigation $investigation): RedirectResponse
    {
        $this->authorize('delete', $investigation);

        $reference = $investigation->reference;
        $investigation->delete();

        return redirect()->route('investigations.index')
            ->with('success', "Draft investigation {$reference} deleted.");
    }

    // ── Workflow ─────────────────────────────────────────────────────────

    public function updateStatus(Request $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('update', $investigation);

        $validated = $request->validate([
            // 'completed' is absent on purpose: completion has its own
            // route because it carries obligations a status change cannot
            // check.
            'status' => ['required', Rule::in(array_diff(Investigation::STATUSES, ['completed']))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $investigation = $validated['status'] === 'closed'
            ? $this->investigations->close($investigation, $request->user(), $validated['note'] ?? null)
            : $this->investigations->transition($investigation, $validated['status'], $request->user(), $validated['note'] ?? null);

        return back()->with('success', "Investigation moved to {$investigation->status}.");
    }

    public function complete(CompleteInvestigationRequest $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('complete', $investigation);

        $investigation = $this->investigations->complete($investigation, $request->user(), $request->validated());

        return back()->with('success', "Investigation completed and rated {$investigation->risk_rating}. A draft report has been generated.");
    }

    public function archive(ArchiveInvestigationRequest $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('archive', $investigation);

        $this->investigations->archive($investigation, $request->user(), $request->validated()['archive_reason']);

        return back()->with('success', 'Investigation archived.');
    }

    public function unarchive(Request $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('unarchive', $investigation);

        $this->investigations->unarchive($investigation, $request->user());

        return back()->with('success', 'Investigation restored.');
    }

    // ── Team ─────────────────────────────────────────────────────────────

    public function storeTeamMember(Request $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('assign', $investigation);

        $validated = $request->validate([
            'user_id' => ['required', 'tenant_user'],
            'role' => ['required', Rule::in(InvestigationTeamMember::ROLES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $member = User::findOrFail($validated['user_id']);

        $this->investigations->assignTeamMember(
            $investigation,
            $member,
            $validated['role'],
            $request->user(),
            $validated['notes'] ?? null,
        );

        return back()->with('success', "{$member->name} added to the investigation team.");
    }

    public function removeTeamMember(Request $request, Investigation $investigation, InvestigationTeamMember $member): RedirectResponse
    {
        $this->authorize('assign', $investigation);

        abort_unless($member->investigation_id === $investigation->id, 404);

        $this->investigations->removeTeamMember($investigation, $member, $request->user());

        return back()->with('success', 'Team member removed.');
    }

    // ── Diary ────────────────────────────────────────────────────────────

    public function storeActivity(Request $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('update', $investigation);

        $validated = $request->validate([
            // Only the six a human may log. The system types are written
            // by the workflow and forging one would make the chronology
            // worthless as evidence.
            'activity_type' => ['required', Rule::in(InvestigationActivity::MANUAL_TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'activity_date' => ['nullable', 'date'],
        ]);

        $this->investigations->recordActivity($investigation, $validated, $request->user());

        return back()->with('success', 'Diary entry recorded.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Vocabulary for the page.
     *
     * `$full` is not tidiness: the control picker is hundreds of rows, and
     * only the finding dialog on Show and the two forms need it. Shipping
     * it with every page of the index would put the control library into
     * the payload fifteen rows at a time (R6).
     *
     * @return array<string, mixed>
     */
    private function options(bool $full = false): array
    {
        $options = [
            'categories' => Investigation::CATEGORIES,
            'sources' => Investigation::SOURCES,
            'statuses' => Investigation::STATUSES,
            'priorities' => Investigation::PRIORITIES,
            'riskRatings' => Investigation::RISK_RATINGS,
            'transitions' => Investigation::TRANSITIONS,
            'teamRoles' => InvestigationTeamMember::ROLES,
            'subjectTypes' => InvestigationSubject::TYPES,
            'subjectRoles' => InvestigationSubject::ROLES_IN_CASE,
            'subjectOutcomes' => InvestigationSubject::OUTCOMES,
            'findingSeverities' => InvestigationFinding::SEVERITIES,
            'consequenceTypes' => ConsequenceAction::ACTION_TYPES,
            'consequenceStatuses' => ConsequenceAction::STATUSES,
            'controlEntities' => ControlEntity::query()->where('is_active', true)
                ->orderBy('name')->get(['id', 'name', 'entity_kind']),
        ];

        if (! $full) {
            return $options;
        }

        return $options + [
            'organisationUnits' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'controls' => Control::query()->orderBy('control_ref')->limit(500)->get(['id', 'control_ref', 'title']),
        ];
    }

    /**
     * The origin record, resolved through its own model — and therefore
     * through its own access rules. A user who cannot see the Speak Up
     * case cannot use it as an origin.
     */
    private function resolveOrigin(?string $alias, mixed $id): ?Model
    {
        if (! $alias || ! $id) {
            return null;
        }

        $modelClass = InvestigationService::ORIGIN_ALIASES[$alias] ?? null;

        return $modelClass ? $modelClass::find($id) : null;
    }

    /** @return array<string, mixed>|null */
    private function originSummary(Investigation $investigation): ?array
    {
        if (! $investigation->origin_type) {
            return null;
        }

        $alias = array_search($investigation->origin_type, InvestigationService::ORIGIN_ALIASES, true) ?: null;
        $origin = $investigation->origin;

        return [
            'alias' => $alias,
            'label' => $alias ? ucfirst(str_replace('_', ' ', $alias)) : class_basename($investigation->origin_type),
            'id' => $investigation->origin_id,
            // Resolved through the origin's own scope, so a record the
            // viewer may not open renders as unavailable rather than
            // leaking its title.
            'reference' => $origin?->reference ?? $origin?->case_ref ?? $origin?->incident_ref ?? $origin?->complaint_ref,
            'title' => $origin?->title ?? $origin?->subject ?? '(not visible to you)',
            'available' => $origin !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function prefillFrom(string $alias, Model $origin): array
    {
        return match ($alias) {
            'case' => [
                'origin_type' => 'case', 'origin_id' => $origin->getKey(),
                'title' => $origin->title,
                'category' => in_array($origin->case_type, Investigation::CATEGORIES, true) ? $origin->case_type : 'whistleblowing',
                'source' => 'whistleblowing',
                // Locked, and the form is told so rather than being allowed
                // to offer a switch that the service would refuse (§D.3-1).
                'is_confidential' => true,
                'confidentiality_locked' => true,
            ],
            'exception' => [
                'origin_type' => 'exception', 'origin_id' => $origin->getKey(),
                'title' => $origin->title,
                'category' => 'process_breach',
                'source' => 'control_exception',
                'control_entity_id' => $origin->control?->control_entity_id,
                'finding_stub' => [
                    'title' => "Control failure behind {$origin->reference}",
                    'control_id' => $origin->control_id,
                    'exception_id' => $origin->id,
                ],
            ],
            'incident' => [
                'origin_type' => 'incident', 'origin_id' => $origin->getKey(),
                'title' => $origin->title,
                'category' => 'other',
                'source' => 'system_alert',
                // Incidents keep money in minor units (Basel loss capture);
                // investigations keep it in major. Convert, do not copy.
                'estimated_financial_impact' => $origin->gross_loss_minor !== null
                    ? round($origin->gross_loss_minor / 100, 2)
                    : null,
                'currency' => $origin->currency,
            ],
            'complaint' => [
                'origin_type' => 'complaint', 'origin_id' => $origin->getKey(),
                'title' => $origin->subject,
                'category' => 'customer_complaint',
                'source' => 'customer_complaint',
            ],
            'test_instance' => [
                'origin_type' => 'test_instance', 'origin_id' => $origin->getKey(),
                'title' => 'Control test failure: '.($origin->control?->title ?? $origin->reference),
                'category' => 'process_breach',
                'source' => 'control_test_failure',
                'control_entity_id' => $origin->control_entity_id,
                'finding_stub' => ['title' => 'Failed control test', 'control_id' => $origin->control_id],
            ],
            default => [],
        };
    }
}
