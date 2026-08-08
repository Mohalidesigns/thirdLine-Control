<?php

namespace App\Http\Controllers;

use App\Http\Requests\PolicyRequest;
use App\Models\Framework;
use App\Models\Policy;
use App\Models\PolicyCategory;
use App\Models\User;
use App\Services\LinkageService;
use App\Services\PolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PolicyController extends Controller
{
    public function __construct(
        private PolicyService $policies,
        private LinkageService $linkage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Policy::class);

        $query = Policy::query()
            ->with(['category:id,name', 'owner:id,name', 'approver:id,name'])
            ->withCount(['sections', 'exceptions as open_exceptions_count' => fn ($q) => $q->open()])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->policy_type, fn ($q, $t) => $q->where('policy_type', $t))
            ->when($request->category_id, fn ($q, $c) => $q->where('category_id', $c))
            ->when($request->owner_id, fn ($q, $o) => $q->where('owner_id', $o))
            ->when($request->boolean('review_due'), fn ($q) => $q->dueForReview(30))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('policy_ref', 'like', "%{$s}%")));

        return Inertia::render('Policies/Index', [
            'policies' => $query->orderByDesc('updated_at')->paginate(15)->withQueryString(),
            'filters' => $request->only(['status', 'policy_type', 'category_id', 'owner_id', 'review_due', 'search']),
            'statuses' => Policy::STATUSES,
            'types' => Policy::TYPES,
            'categories' => PolicyCategory::active()->orderBy('sort_order')->get(['id', 'name']),
            'owners' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'stats' => [
                'published' => Policy::published()->count(),
                'in_draft' => Policy::whereIn('status', ['Draft', 'Under Review', 'Under Revision'])->count(),
                'awaiting_approval' => Policy::where('status', 'Pending Approval')->count(),
                'review_due' => Policy::dueForReview(30)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Policy::class);

        return Inertia::render('Policies/Create', [
            'types' => Policy::TYPES,
            'reviewFrequencies' => Policy::REVIEW_FREQUENCIES,
            'attestationFrequencies' => Policy::ATTESTATION_FREQUENCIES,
            'categories' => PolicyCategory::active()->orderBy('sort_order')->get(['id', 'name']),
            'owners' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'publishedPolicies' => Policy::published()->orderBy('policy_ref')->get(['id', 'policy_ref', 'title']),
        ]);
    }

    public function store(PolicyRequest $request): RedirectResponse
    {
        $this->authorize('create', Policy::class);

        $policy = $this->policies->create($request->validated(), $request->user());

        return redirect()
            ->route('policies.show', $policy)
            ->with('success', "Policy {$policy->policy_ref} drafted.");
    }

    public function show(Request $request, Policy $policy): Response
    {
        $this->authorize('view', $policy);

        $policy->load([
            'category:id,name', 'owner:id,name', 'approver:id,name',
            'document:id,reference,title', 'supersedes:id,policy_ref,title',
            'sections', 'exceptions.requester:id,name', 'exceptions.approver:id,name',
            'attestationCampaign:id,reference,name,status,closes_at',
        ]);

        $campaign = $policy->attestationCampaign;

        return Inertia::render('Policies/Show', [
            'policy' => $policy,
            'links' => $this->linkage->neighbours('policy', $policy->id),
            'attestation' => $campaign ? [
                'campaign' => $campaign->only(['id', 'reference', 'name', 'status', 'closes_at']),
                'attested' => $campaign->attestations()->count(),
                'invited' => $campaign->responses()->count(),
                'response_rate' => $campaign->responseRate(),
            ] : null,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'update' => $request->user()->can('update', $policy),
                'submit' => $request->user()->can('submit', $policy),
                'approve' => $request->user()->can('approve', $policy),
                'publish' => $request->user()->can('publish', $policy),
                'revise' => $request->user()->can('revise', $policy),
                'withdraw' => $request->user()->can('withdraw', $policy),
                'request_exception' => $request->user()->can('request policy-exceptions'),
                'decide_exception' => $request->user()->can('approve policy-exceptions'),
            ],
        ]);
    }

    public function update(PolicyRequest $request, Policy $policy): RedirectResponse
    {
        $this->authorize('update', $policy);

        $this->policies->update($policy, $request->validated());

        return back()->with('success', 'Policy updated.');
    }

    public function submit(Request $request, Policy $policy): RedirectResponse
    {
        $this->authorize('submit', $policy);

        $validated = $request->validate(['target' => ['required', 'in:review,approval']]);

        $validated['target'] === 'review'
            ? $this->policies->submitForReview($policy, $request->user())
            : $this->policies->submitForApproval($policy, $request->user());

        return back()->with('success', 'Policy submitted.');
    }

    public function approve(Request $request, Policy $policy): RedirectResponse
    {
        $this->authorize('approve', $policy);

        $this->policies->approve($policy, $request->user());

        return back()->with('success', 'Policy approved — it can now be published.');
    }

    public function reject(Request $request, Policy $policy): RedirectResponse
    {
        $this->authorize('approve', $policy);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->policies->reject($policy, $request->user(), $validated['reason']);

        return back()->with('success', 'Policy sent back to draft.');
    }

    public function publish(Request $request, Policy $policy): RedirectResponse
    {
        $this->authorize('publish', $policy);

        $validated = $request->validate([
            'scope' => ['nullable', 'array'],
            'scope.all_staff' => ['nullable', 'boolean'],
            'scope.role_names' => ['nullable', 'array'],
            'scope.user_ids' => ['nullable', 'array'],
            'scope.user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $published = $this->policies->publish($policy, $request->user(), $validated['scope'] ?? null);

        return back()->with('success', $published->attestation_campaign_id
            ? 'Policy published and its attestation campaign opened.'
            : 'Policy published.');
    }

    public function revise(Request $request, Policy $policy): RedirectResponse
    {
        $this->authorize('revise', $policy);

        $this->policies->revise($policy, $request->user());

        return back()->with('success', 'Policy opened for revision.');
    }

    public function withdraw(Request $request, Policy $policy): RedirectResponse
    {
        $this->authorize('withdraw', $policy);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->policies->withdraw($policy, $request->user(), $validated['reason']);

        return back()->with('success', 'Policy withdrawn.');
    }

    /** Framework requirements no published policy governs (11.1). */
    public function gaps(Request $request): Response
    {
        $this->authorize('viewAny', Policy::class);

        return Inertia::render('Policies/Gaps', [
            'analysis' => $this->policies->gapAnalysis($request->integer('framework_id') ?: null),
            'frameworks' => Framework::orderBy('code')->get(['id', 'code', 'name']),
            'filters' => $request->only(['framework_id']),
        ]);
    }
}
