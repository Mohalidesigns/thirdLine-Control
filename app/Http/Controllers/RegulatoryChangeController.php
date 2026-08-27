<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegulatoryChangeRequest;
use App\Models\Regulator;
use App\Models\RegulatoryChange;
use App\Rules\RichTextRule;
use App\Services\RegulatoryChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegulatoryChangeController extends Controller
{
    public function __construct(private RegulatoryChangeService $changes) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RegulatoryChange::class);

        $query = RegulatoryChange::query()
            ->with(['regulator:id,code,name', 'assessor:id,name', 'actioner:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('reference', 'like', "%{$s}%")))
            ->when($request->regulator_id, fn ($q, $r) => $q->where('regulator_id', $r))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->source, fn ($q, $s) => $q->where('source', $s));

        return Inertia::render('RegulatoryChanges/Index', [
            'changes' => $query->orderByDesc('published_at')->orderByDesc('id')->paginate(20)->withQueryString(),
            'filters' => $request->only(['search', 'regulator_id', 'status', 'source']),
            'regulators' => Regulator::active()->orderBy('name')->get(['id', 'name']),
            'statuses' => RegulatoryChange::STATUSES,
            'stats' => [
                'new' => RegulatoryChange::query()->where('status', 'New')->count(),
                'underReview' => RegulatoryChange::query()->where('status', 'Under Review')->count(),
                'assessed' => RegulatoryChange::query()->where('status', 'Impact Assessed')->count(),
                'actioned' => RegulatoryChange::query()->where('status', 'Actioned')->count(),
            ],
            'can' => [
                'assess' => $request->user()->can('assess regulatory-changes'),
                'action' => $request->user()->can('action regulatory-changes'),
            ],
        ]);
    }

    public function store(RegulatoryChangeRequest $request): RedirectResponse
    {
        $this->authorize('create', RegulatoryChange::class);

        $this->changes->create($request->validated(), $request->user());

        return back()->with('success', 'Regulatory publication logged.');
    }

    public function startReview(Request $request, RegulatoryChange $change): RedirectResponse
    {
        $this->authorize('assess', $change);

        $this->changes->startReview($change, $request->user());

        return back()->with('success', 'Under review.');
    }

    public function assess(Request $request, RegulatoryChange $change): RedirectResponse
    {
        $this->authorize('assess', $change);

        $data = $request->validate([
            'impact_assessment' => ['required', 'string', 'min:20', 'max:10000'],
            'impact_assessment_rich' => ['nullable', 'array', new RichTextRule],
            'affected_obligation_ids' => ['nullable', 'array'],
            'affected_obligation_ids.*' => ['integer', 'exists:regulatory_obligations,id'],
            'affected_control_ids' => ['nullable', 'array'],
            'affected_control_ids.*' => ['integer', 'exists:controls,id'],
        ]);

        $this->changes->recordAssessment($change, $request->user(), $data);

        return back()->with('success', 'Impact assessment recorded — awaiting sign-off by a second person.');
    }

    public function action(Request $request, RegulatoryChange $change): RedirectResponse
    {
        $this->authorize('action', $change);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);

        $this->changes->markActioned($change, $request->user(), $data['notes'] ?? null);

        return back()->with('success', 'Change actioned and closed.');
    }

    public function notApplicable(Request $request, RegulatoryChange $change): RedirectResponse
    {
        $this->authorize('assess', $change);

        $data = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:5000']]);

        $this->changes->markNotApplicable($change, $request->user(), $data['reason']);

        return back()->with('success', 'Marked not applicable.');
    }
}
