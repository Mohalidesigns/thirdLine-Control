<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImprovementActionRequest;
use App\Models\Control;
use App\Models\ImprovementAction;
use App\Models\Risk;
use App\Models\User;
use App\Services\ImprovementService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ImprovementActionController extends Controller
{
    public function __construct(private ImprovementService $improvementService) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ImprovementAction::class);

        $query = ImprovementAction::query()
            ->with(['owner:id,name', 'control:id,control_ref,title', 'risk:id,code,title', 'approver:id,name', 'verifier:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->priority, fn ($q, $p) => $q->where('priority', $p))
            ->when($request->source_type, fn ($q, $s) => $q->where('source_type', $s))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('reference', 'like', "%{$s}%")));

        return Inertia::render('Improvements/Index', [
            'improvements' => $query->orderByDesc('created_at')->paginate(15)->withQueryString(),
            'filters' => $request->only(['status', 'priority', 'source_type', 'search']),
            'statuses' => ImprovementAction::STATUSES,
            'priorities' => ImprovementAction::PRIORITIES,
            'sources' => ImprovementAction::SOURCES,
            'stats' => [
                'open' => ImprovementAction::open()->count(),
                'proposed' => ImprovementAction::where('status', 'Proposed')->count(),
                'implemented' => ImprovementAction::where('status', 'Implemented')->count(),
                'verified' => ImprovementAction::where('status', 'Verified')->count(),
            ],
            'controls' => Control::where('is_template', false)->orderBy('control_ref')->get(['id', 'control_ref', 'title']),
            'risks' => Risk::orderBy('code')->get(['id', 'code', 'title']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ImprovementActionRequest $request)
    {
        $this->authorize('create', ImprovementAction::class);

        $action = $this->improvementService->propose($request->validated(), $request->user());

        return back()->with('success', "Improvement {$action->reference} proposed.");
    }

    public function decide(Request $request, ImprovementAction $improvement)
    {
        $this->authorize('approve', $improvement);

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string'],
        ]);

        $validated['decision'] === 'approve'
            ? $this->improvementService->approve($improvement, $request->user())
            : $this->improvementService->reject($improvement, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', 'Decision recorded.');
    }

    public function progress(Request $request, ImprovementAction $improvement)
    {
        $this->authorize('progress', $improvement);

        $validated = $request->validate(['to' => ['required', 'in:In Progress,Implemented']]);

        $validated['to'] === 'In Progress'
            ? $this->improvementService->start($improvement)
            : $this->improvementService->markImplemented($improvement);

        return back()->with('success', 'Improvement updated.');
    }

    public function verify(ImprovementAction $improvement)
    {
        $this->authorize('verify', $improvement);

        $this->improvementService->verify($improvement, auth()->user());

        return back()->with('success', 'Improvement verified — well done.');
    }
}
