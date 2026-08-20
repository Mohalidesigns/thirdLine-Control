<?php

namespace App\Http\Controllers;

use App\Models\EffectivenessRating;
use App\Models\Evidence;
use App\Models\TestInstance;
use App\Rules\RichTextRule;
use App\Services\TestingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TestInstanceController extends Controller
{
    public function __construct(private TestingService $testingService) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', TestInstance::class);

        $user = $request->user();
        $view = $request->input('view', 'all');

        $query = TestInstance::query()
            ->with(['control:id,control_ref,title,frequency', 'tester:id,name', 'reviewer:id,name'])
            ->when($view === 'mine', fn ($q) => $q->where('assigned_tester_id', $user->id)->orWhereNull('assigned_tester_id'))
            ->when($view === 'review', fn ($q) => $q->where('status', 'Submitted'))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('reference', 'like', "%{$s}%")
                ->orWhereHas('control', fn ($c) => $c->where('title', 'like', "%{$s}%"))));

        return Inertia::render('TestInstances/Index', [
            'instances' => $query->orderBy('due_date')->paginate(15)->withQueryString(),
            'filters' => $request->only(['status', 'search', 'view']),
            'statuses' => TestInstance::STATUSES,
        ]);
    }

    public function show(TestInstance $testInstance): Response
    {
        $this->authorize('view', $testInstance);

        $testInstance->load([
            'control:id,control_ref,title,owner_id',
            'testScript.checkItems',
            'checkResults',
            'tester:id,name',
            'reviewer:id,name',
            'effectivenessRating',
        ]);

        $user = auth()->user();

        return Inertia::render('TestInstances/Show', [
            'instance' => $testInstance,
            'evidence' => Evidence::query()
                ->where('linked_type', TestInstance::class)
                ->where('linked_id', $testInstance->id)
                ->with('uploader:id,name')
                ->latest('uploaded_at')
                ->get(),
            'can' => [
                'execute' => $user->can('execute', $testInstance),
                'review' => $user->can('review', $testInstance),
                'reopen' => $user->can('reopen', $testInstance),
                'rate' => $user->can('rate', $testInstance),
                'approveRating' => $user->can('approveRating', $testInstance),
            ],
        ]);
    }

    public function start(Request $request, TestInstance $testInstance): RedirectResponse
    {
        $this->authorize('execute', $testInstance);

        $this->testingService->start($testInstance, $request->user());

        return back()->with('success', 'Test started.');
    }

    public function recordResult(Request $request, TestInstance $testInstance): RedirectResponse
    {
        $this->authorize('execute', $testInstance);

        $validated = $request->validate([
            'check_item_id' => ['required', 'integer'],
            'result' => ['required', Rule::in(['Pass', 'Fail', 'NA'])],
            'comment' => ['nullable', 'string'],
        ]);

        $this->testingService->recordResult(
            $testInstance,
            $validated['check_item_id'],
            $validated['result'],
            $validated['comment'] ?? null,
            $request->user(),
        );

        return back();
    }

    public function submit(Request $request, TestInstance $testInstance): RedirectResponse
    {
        $this->authorize('execute', $testInstance);

        $sampling = $request->validate([
            'population_size' => ['nullable', 'integer', 'min:0'],
            'sample_size' => ['nullable', 'integer', 'min:0'],
            'sampling_method' => ['nullable', 'string', 'max:255'],
            'sample_items' => ['nullable', 'string'],
        ]);

        $this->testingService->submit($testInstance, $sampling, $request->user());

        $failures = $testInstance->checkResults()->where('result', 'Fail')->count();

        return redirect()->route('test-instances.show', $testInstance)
            ->with($failures > 0 ? 'warning' : 'success',
                $failures > 0
                    ? "Test submitted — {$failures} failed check(s) automatically raised exceptions."
                    : 'Test submitted for review.');
    }

    public function review(Request $request, TestInstance $testInstance): RedirectResponse
    {
        $this->authorize('review', $testInstance);

        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'required_if:approved,false'],
        ]);

        $this->testingService->review($testInstance, $request->user(), $validated['approved'], $validated['notes'] ?? null);

        return back()->with('success', $validated['approved'] ? 'Test reviewed and locked.' : 'Test returned to the tester.');
    }

    public function reopen(Request $request, TestInstance $testInstance): RedirectResponse
    {
        $this->authorize('reopen', $testInstance);

        $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->testingService->reopen($testInstance, $request->user(), $request->string('reason'));

        return back()->with('warning', 'Test reopened — the change is on the audit trail.');
    }

    public function rate(Request $request, TestInstance $testInstance): RedirectResponse
    {
        $this->authorize('rate', $testInstance);

        $validated = $request->validate([
            'design_effectiveness' => ['required', Rule::in(EffectivenessRating::SCALE)],
            'design_rationale' => ['nullable', 'string'],
            'design_rationale_rich' => ['nullable', 'array', new RichTextRule],
            'operating_effectiveness' => ['required', Rule::in(EffectivenessRating::SCALE)],
            'operating_rationale' => ['nullable', 'string'],
            'operating_rationale_rich' => ['nullable', 'array', new RichTextRule],
        ]);

        $this->testingService->rate($testInstance, $validated, $request->user());

        return back()->with('success', 'Effectiveness rating recorded, pending approval.');
    }

    public function approveRating(Request $request, TestInstance $testInstance): RedirectResponse
    {
        $this->authorize('approveRating', $testInstance);

        $rating = $testInstance->effectivenessRating;
        abort_unless($rating && $rating->status === 'Pending Approval', 404);

        $this->testingService->approveRating($rating, $request->user());

        return back()->with('success', 'Rating published and residual risk recomputed.');
    }
}
