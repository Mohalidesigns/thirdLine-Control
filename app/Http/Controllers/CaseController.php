<?php

namespace App\Http\Controllers;

use App\Http\Requests\CaseRequest;
use App\Models\InvestigationCase;
use App\Models\OrganisationUnit;
use App\Models\User;
use App\Services\CaseService;
use App\Services\LinkageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Case management (11.4). Every query here runs through the model's
 * allowlist global scope, so a user who is not on a case sees no row on the
 * index and a 403 on the detail page. The one exception is the read-only
 * 'view all cases' oversight permission (System Administrator): full sight,
 * every view logged, and no power to act on a case they are not named on.
 */
class CaseController extends Controller
{
    public function __construct(
        private CaseService $cases,
        private LinkageService $linkage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', InvestigationCase::class);

        $query = InvestigationCase::query()
            ->with(['leadInvestigator:id,name', 'entity:id,name'])
            ->withCount('notes')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->case_type, fn ($q, $t) => $q->where('case_type', $t))
            ->when($request->severity, fn ($q, $s) => $q->where('severity', $s))
            ->when($request->boolean('open_only'), fn ($q) => $q->open())
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('case_ref', 'like', "%{$s}%")));

        return Inertia::render('Cases/Index', [
            'cases' => $query->orderByDesc('received_at')->paginate(15)->withQueryString(),
            'filters' => $request->only(['status', 'case_type', 'severity', 'open_only', 'search']),
            'statuses' => InvestigationCase::STATUSES,
            'types' => InvestigationCase::TYPES,
            'severities' => InvestigationCase::SEVERITIES,
            'confidentialityLevels' => InvestigationCase::CONFIDENTIALITY_LEVELS,
            'entities' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'investigators' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // Counts are of cases the viewer may see — never of the register.
            'stats' => [
                'open' => InvestigationCase::open()->count(),
                'anonymous' => InvestigationCase::where('is_anonymous', true)->count(),
                'under_investigation' => InvestigationCase::where('status', 'Under Investigation')->count(),
            ],
        ]);
    }

    public function store(CaseRequest $request): RedirectResponse
    {
        $this->authorize('create', InvestigationCase::class);

        $result = $this->cases->open(
            $request->validated(),
            $request->user(),
            $request->user()->tenant_id,
            $request->boolean('is_anonymous'),
        );

        return redirect()
            ->route('cases.show', $result['case'])
            ->with('success', "Case {$result['case']->case_ref} opened.");
    }

    public function show(Request $request, InvestigationCase $case): Response
    {
        $this->authorize('view', $case);

        $this->cases->recordAccess($case, $request->user());

        $canSeePrivileged = $request->user()->can('viewPrivileged', $case);

        $case->load(['leadInvestigator:id,name', 'entity:id,name', 'closer:id,name',
            'incident:id,incident_ref,title', 'complaint:id,complaint_ref,subject']);

        $notes = $case->notes()
            ->when(! $canSeePrivileged, fn ($q) => $q->where('is_privileged', false))
            ->with('author:id,name')
            ->get();

        return Inertia::render('Cases/Show', [
            'case' => $case,
            'notes' => $notes,
            'links' => $this->linkage->neighbours('case', $case->id),
            'allowlist' => User::whereIn('id', $case->access_user_ids ?? [])->get(['id', 'name', 'email']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canSeePrivileged' => $canSeePrivileged,
            'can' => [
                'update' => $request->user()->can('update', $case),
                'assess' => $request->user()->can('assess', $case),
                'investigate' => $request->user()->can('investigate', $case),
                'conclude' => $request->user()->can('conclude', $case),
                'close' => $request->user()->can('close', $case),
                'manage_access' => $request->user()->can('manageAccess', $case),
            ],
        ]);
    }

    public function assess(Request $request, InvestigationCase $case): RedirectResponse
    {
        $this->authorize('assess', $case);

        $validated = $request->validate([
            'severity' => ['required', 'in:'.implode(',', InvestigationCase::SEVERITIES)],
            'confidentiality' => ['required', 'in:'.implode(',', InvestigationCase::CONFIDENTIALITY_LEVELS)],
            'lead_investigator_id' => ['nullable', 'tenant_user'],
            'entity_id' => ['nullable', 'exists:organisation_units,id'],
            'subject_persons' => ['nullable', 'array', 'max:20'],
            'subject_persons.*.name' => ['required', 'string', 'max:255'],
            'subject_persons.*.role' => ['nullable', 'string', 'max:120'],
        ]);

        $this->cases->assess($case, $request->user(), $validated);

        return back()->with('success', 'Case assessed.');
    }

    public function investigate(Request $request, InvestigationCase $case): RedirectResponse
    {
        $this->authorize('investigate', $case);

        $validated = $request->validate(['investigation_plan' => ['required', 'string', 'max:8000']]);

        $this->cases->startInvestigation($case, $request->user(), $validated['investigation_plan']);

        return back()->with('success', 'Investigation opened.');
    }

    public function conclude(Request $request, InvestigationCase $case): RedirectResponse
    {
        $this->authorize('conclude', $case);

        $validated = $request->validate([
            'outcome' => ['required', 'in:Substantiated,Unsubstantiated,Referred'],
            'findings' => ['required', 'string', 'max:8000'],
            'conclusion' => ['required', 'string', 'max:8000'],
            'actions_taken' => ['nullable', 'string', 'max:8000'],
            'referred_to' => ['required_if:outcome,Referred', 'nullable', 'string', 'max:255'],
        ]);

        $this->cases->conclude($case, $request->user(), $validated['outcome'], $validated);

        return back()->with('success', 'Case concluded.');
    }

    public function close(Request $request, InvestigationCase $case): RedirectResponse
    {
        $this->authorize('close', $case);

        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $this->cases->close($case, $request->user(), $validated['note'] ?? null);

        return back()->with('success', 'Case closed.');
    }

    public function storeNote(Request $request, InvestigationCase $case): RedirectResponse
    {
        $this->authorize('investigate', $case);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:8000'],
            'is_privileged' => ['nullable', 'boolean'],
            'is_reporter_visible' => ['nullable', 'boolean'],
        ]);

        $this->cases->addNote(
            $case,
            $request->user(),
            $validated['note'],
            (bool) ($validated['is_privileged'] ?? false),
            (bool) ($validated['is_reporter_visible'] ?? false),
        );

        return back()->with('success', 'Note added.');
    }

    public function grantAccess(Request $request, InvestigationCase $case): RedirectResponse
    {
        $this->authorize('manageAccess', $case);

        $validated = $request->validate(['user_id' => ['required', 'tenant_user']]);

        $this->cases->grantAccess($case, User::findOrFail($validated['user_id']), $request->user());

        return back()->with('success', 'Access granted and logged.');
    }

    public function revokeAccess(Request $request, InvestigationCase $case): RedirectResponse
    {
        $this->authorize('manageAccess', $case);

        $validated = $request->validate(['user_id' => ['required', 'tenant_user']]);

        $this->cases->revokeAccess($case, User::findOrFail($validated['user_id']), $request->user());

        return back()->with('success', 'Access revoked and logged.');
    }

    /** Aggregate-only board extract — no titles, no subjects, no notes. */
    public function boardExtract(Request $request): Response
    {
        $this->authorize('viewAny', InvestigationCase::class);
        abort_unless($request->user()->can('report cases'), 403);

        return Inertia::render('Cases/BoardExtract', [
            'extract' => $this->cases->boardExtract(
                $request->user()->tenant_id,
                $request->input('from'),
                $request->input('to'),
            ),
            'filters' => $request->only(['from', 'to']),
        ]);
    }
}
