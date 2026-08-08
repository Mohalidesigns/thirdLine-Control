<?php

namespace App\Http\Controllers;

use App\Http\Requests\PolicyExceptionRequest;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Services\PolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PolicyExceptionController extends Controller
{
    public function __construct(private PolicyService $policies) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PolicyException::class);

        $query = PolicyException::query()
            ->with(['policy:id,policy_ref,title', 'requester:id,name', 'approver:id,name', 'entity:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->policy_id, fn ($q, $p) => $q->where('policy_id', $p))
            ->when($request->boolean('open_only'), fn ($q) => $q->open());

        return Inertia::render('Policies/Exceptions', [
            'exceptions' => $query->orderByDesc('created_at')->paginate(15)->withQueryString(),
            'filters' => $request->only(['status', 'policy_id', 'open_only']),
            'statuses' => PolicyException::STATUSES,
            'policies' => Policy::published()->orderBy('policy_ref')->get(['id', 'policy_ref', 'title']),
            'stats' => [
                'open' => PolicyException::open()->count(),
                'live' => PolicyException::live()->count(),
                'expiring' => PolicyException::live()
                    ->whereDate('requested_to', '<=', now()->addDays(30)->toDateString())->count(),
            ],
        ]);
    }

    public function store(PolicyExceptionRequest $request, Policy $policy): RedirectResponse
    {
        $this->authorize('create', PolicyException::class);

        $exception = $this->policies->requestException($policy, $request->validated(), $request->user());

        return back()->with('success', "Waiver {$exception->reference} requested.");
    }

    public function decide(Request $request, PolicyException $exception): RedirectResponse
    {
        $this->authorize('decide', $exception);

        $validated = $request->validate([
            'approved' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->policies->decideException(
            $exception,
            $request->user(),
            $validated['approved'],
            $validated['notes'] ?? null,
        );

        return back()->with('success', $validated['approved'] ? 'Waiver approved.' : 'Waiver rejected.');
    }

    public function revoke(Request $request, PolicyException $exception): RedirectResponse
    {
        $this->authorize('revoke', $exception);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->policies->revokeException($exception, $request->user(), $validated['reason']);

        return back()->with('success', 'Waiver revoked.');
    }
}
