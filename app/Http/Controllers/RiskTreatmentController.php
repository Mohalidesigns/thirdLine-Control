<?php

namespace App\Http\Controllers;

use App\Http\Requests\RiskTreatmentRequest;
use App\Models\Risk;
use App\Models\RiskTreatment;
use App\Models\TreatmentMilestone;
use App\Models\User;
use App\Services\LinkageService;
use App\Services\TreatmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RiskTreatmentController extends Controller
{
    public function __construct(
        private TreatmentService $treatments,
        private LinkageService $linkage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RiskTreatment::class);

        $query = RiskTreatment::query()
            ->with(['risk:id,code,title,current_rating_band', 'owner:id,name', 'approver:id,name', 'verifier:id,name'])
            ->withCount([
                'milestones',
                'milestones as completed_milestones_count' => fn ($q) => $q->where('status', 'Completed'),
            ])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->strategy, fn ($q, $s) => $q->where('strategy', $s))
            ->when($request->owner_id, fn ($q, $o) => $q->where('owner_id', $o))
            ->when($request->boolean('overdue_only'), fn ($q) => $q->overdue())
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('reference', 'like', "%{$s}%")));

        return Inertia::render('Treatments/Index', [
            'treatments' => $query->orderByDesc('created_at')->paginate(15)->withQueryString(),
            'filters' => $request->only(['status', 'strategy', 'owner_id', 'overdue_only', 'search']),
            'statuses' => RiskTreatment::STATUSES,
            'strategies' => RiskTreatment::STRATEGIES,
            'owners' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stats' => [
                'open' => RiskTreatment::open()->count(),
                'overdue' => RiskTreatment::overdue()->count(),
                'awaiting_verification' => RiskTreatment::where('status', 'Implemented')->count(),
                'accepted' => RiskTreatment::where('strategy', 'Accept')->whereIn('status', ['Approved', 'Verified'])->count(),
            ],
        ]);
    }

    public function show(Request $request, RiskTreatment $treatment): Response
    {
        $this->authorize('view', $treatment);

        $treatment->load([
            'risk:id,code,title,current_rating_band,residual_rating,target_rating',
            'owner:id,name', 'approver:id,name', 'verifier:id,name',
            'control:id,control_ref,title', 'milestones.owner:id,name',
        ]);

        return Inertia::render('Treatments/Show', [
            'treatment' => $treatment,
            'links' => $this->linkage->neighbours('treatment', $treatment->id),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'update' => $request->user()->can('update', $treatment),
                'approve' => $request->user()->can('approve', $treatment),
                'progress' => $request->user()->can('progress', $treatment),
                'verify' => $request->user()->can('verify', $treatment),
            ],
        ]);
    }

    public function store(RiskTreatmentRequest $request, Risk $risk): RedirectResponse
    {
        $this->authorize('create', RiskTreatment::class);

        $treatment = $this->treatments->propose($risk, $request->validated(), $request->user());

        if ($treatment->control_id) {
            $this->linkage->link('control', $treatment->control_id, 'treatment', $treatment->id, 'mitigates');
        }

        $this->linkage->link('risk', $risk->id, 'treatment', $treatment->id, 'relates_to');

        return back()->with('success', "Treatment {$treatment->reference} proposed.");
    }

    public function approve(Request $request, RiskTreatment $treatment): RedirectResponse
    {
        $this->authorize('approve', $treatment);

        $validated = $request->validate([
            'acceptance_reason' => ['nullable', 'string', 'max:2000'],
            'acceptance_expiry' => ['nullable', 'date', 'after:today'],
        ]);

        $this->treatments->approve($treatment, $request->user(), $validated);

        return back()->with('success', 'Treatment approved.');
    }

    public function progress(Request $request, RiskTreatment $treatment): RedirectResponse
    {
        $this->authorize('progress', $treatment);

        $validated = $request->validate([
            'action' => ['required', 'in:start,progress,implement'],
            'progress' => ['required_if:action,progress', 'nullable', 'integer', 'between:0,100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        match ($validated['action']) {
            'start' => $this->treatments->start($treatment),
            'implement' => $this->treatments->markImplemented($treatment),
            default => $this->treatments->recordProgress($treatment, (int) $validated['progress'], $validated['note'] ?? null),
        };

        return back()->with('success', 'Treatment updated.');
    }

    public function verify(Request $request, RiskTreatment $treatment): RedirectResponse
    {
        $this->authorize('verify', $treatment);

        $validated = $request->validate(['verification_notes' => ['nullable', 'string', 'max:2000']]);

        $this->treatments->verify($treatment, $request->user(), $validated['verification_notes'] ?? null);

        return back()->with('success', 'Treatment verified — the risk position has been re-scored.');
    }

    public function cancel(Request $request, RiskTreatment $treatment): RedirectResponse
    {
        $this->authorize('update', $treatment);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->treatments->cancel($treatment, $request->user(), $validated['reason']);

        return back()->with('success', 'Treatment cancelled.');
    }

    public function storeMilestone(Request $request, RiskTreatment $treatment): RedirectResponse
    {
        $this->authorize('update', $treatment);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'tenant_user'],
        ]);

        $this->treatments->addMilestone($treatment, $validated);

        return back()->with('success', 'Milestone added.');
    }

    public function completeMilestone(RiskTreatment $treatment, TreatmentMilestone $milestone): RedirectResponse
    {
        $this->authorize('progress', $treatment);
        abort_unless($milestone->treatment_id === $treatment->id, 404);

        $this->treatments->completeMilestone($milestone);

        return back()->with('success', 'Milestone completed.');
    }
}
