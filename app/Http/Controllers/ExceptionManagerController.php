<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueEscalationRequest;
use App\Http\Requests\ReviewResponseRequest;
use App\Http\Requests\WithdrawEscalationRequest;
use App\Models\ControlException;
use App\Models\Evidence;
use App\Models\ExceptionEscalation;
use App\Models\ExceptionResponse;
use App\Models\OrganisationUnit;
use App\Services\ExceptionEscalationService;
use App\Services\ExceptionResponseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CR1.7 — the Exception Manager cockpit. Rows are ESCALATIONS, not
 * exceptions: the register the control function demos is the fan-out and
 * its response state, department by department.
 */
class ExceptionManagerController extends Controller
{
    public function __construct(
        private ExceptionEscalationService $escalationService,
        private ExceptionResponseService $responseService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ExceptionEscalation::class);

        $query = ExceptionEscalation::query()
            ->with([
                'exception:id,reference,title,severity,status',
                'unit:id,name', 'process:id,name', 'respondent:id,name', 'issuer:id,name',
            ])
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('reference', 'like', "%{$s}%")
                ->orWhereHas('exception', fn ($e) => $e->where('reference', 'like', "%{$s}%")
                    ->orWhere('title', 'like', "%{$s}%"))))
            ->when($request->unit_id, fn ($q, $u) => $q->where('unit_id', $u))
            ->when($request->respondent_id, fn ($q, $u) => $q->where('respondent_id', $u))
            ->when($request->severity, fn ($q, $s) => $q->where('severity', $s))
            ->when($request->status, fn ($q, $s) => $s === 'open' ? $q->open() : $q->where('status', $s))
            ->when($request->boolean('overdue'), fn ($q) => $q->responseOverdue())
            ->when($request->boolean('reissued'), fn ($q) => $q->where('round_no', '>', 1))
            ->when($request->source, fn ($q, $s) => $q->whereHas('exception', fn ($e) => $e->where('source_type', $s)))
            ->when($request->ageing, function ($q, $bucket) {
                [$from, $to] = match ($bucket) {
                    '0-30' => [now()->subDays(30), now()],
                    '31-60' => [now()->subDays(60), now()->subDays(31)],
                    '61-90' => [now()->subDays(90), now()->subDays(61)],
                    default => [null, now()->subDays(91)],
                };

                return $q->when($from, fn ($w) => $w->where('issued_at', '>=', $from))
                    ->where('issued_at', '<=', $to);
            });

        $tenantId = $request->user()->tenant_id;
        $periodStart = now()->startOfMonth();

        $stats = [
            'issued' => ExceptionEscalation::count(),
            'awaitingResponse' => ExceptionEscalation::awaitingResponse()->count(),
            'overdue' => ExceptionEscalation::responseOverdue()->count(),
            'awaitingReview' => ExceptionEscalation::where('status', 'Responded')->count(),
            'closedThisPeriod' => ExceptionEscalation::where('status', 'Closed')
                ->where('closed_at', '>=', $periodStart)->count(),
            'averageResponseDays' => round(
                (float) ExceptionEscalation::whereNotNull('first_response_at')
                    ->get(['issued_at', 'first_response_at'])
                    ->map(fn ($e) => $e->issued_at?->diffInDays($e->first_response_at))
                    ->filter(fn ($d) => $d !== null)
                    ->avg(),
                1,
            ),
        ];

        return Inertia::render('ExceptionManager/Index', [
            'escalations' => $query->orderByDesc('issued_at')->paginate(15)->withQueryString(),
            'filters' => $request->only([
                'search', 'unit_id', 'respondent_id', 'severity', 'status',
                'overdue', 'reissued', 'source', 'ageing', 'view',
            ]),
            'stats' => $stats,
            'statuses' => ExceptionEscalation::STATUSES,
            'severities' => ControlException::SEVERITIES,
            'units' => OrganisationUnit::orderBy('name')->get(['id', 'name']),
            'scorecard' => $request->input('view') === 'scorecard'
                ? $this->escalationService->departmentScorecard($tenantId)
                : null,
            'ageingReport' => $request->input('view') === 'ageing'
                ? $this->escalationService->ageing($tenantId)
                : null,
        ]);
    }

    /** The full round-by-round thread (CR1.7). */
    public function show(ExceptionEscalation $escalation): Response
    {
        $this->authorize('view', $escalation);

        $escalation->load([
            'exception:id,reference,title,severity,status,control_id,raised_by',
            'exception.control:id,control_ref,title',
            'unit:id,name', 'process:id,name', 'respondent:id,name,email',
            'issuer:id,name', 'acknowledger:id,name', 'closer:id,name', 'withdrawer:id,name',
            'slaPolicy:id,name,severity,respond_within_days',
            'responses.responder:id,name', 'responses.reviewer:id,name',
            'actions.owner:id,name', 'actions.validator:id,name',
            'responseLinks.creator:id,name',
        ]);

        $user = auth()->user();
        $pendingResponse = $escalation->responses->firstWhere('review_status', 'Pending Review');

        return Inertia::render('ExceptionManager/Show', [
            'escalation' => $escalation,
            'evidence' => Evidence::query()
                ->where('linked_type', ExceptionEscalation::class)
                ->where('linked_id', $escalation->id)
                ->with('uploader:id,name')
                ->latest('uploaded_at')
                ->get(),
            'can' => [
                'respond' => $user->can('respond', $escalation),
                'review' => $pendingResponse ? $user->can('review', $pendingResponse) : false,
                'accept' => $pendingResponse ? $user->can('accept', $pendingResponse) : false,
                'close' => $user->can('close', $escalation),
                'withdraw' => $user->can('withdraw', $escalation),
                'manageLinks' => $user->can('manageLinks', $escalation),
                'progressActions' => $escalation->actions
                    ->contains(fn ($a) => $user->can('progress', $a)),
                'validateActions' => $escalation->actions
                    ->contains(fn ($a) => $user->can('validateAction', $a)),
            ],
        ]);
    }

    /** CR1.1 — fan-out issue from the exception. */
    public function issue(IssueEscalationRequest $request, ControlException $exception): RedirectResponse
    {
        $this->authorize('issue', ExceptionEscalation::class);

        $escalations = $this->escalationService->issue(
            $exception,
            $request->validated('targets'),
            $request->user(),
        );

        return back()->with('success', count($escalations).' escalation(s) issued — each department now owes a response on its own clock.');
    }

    /** CR1.4 — review the pending response: accept, or reject and re-issue. */
    public function review(ReviewResponseRequest $request, ExceptionEscalation $escalation, ExceptionResponse $response): RedirectResponse
    {
        if ($request->validated('decision') === 'accept') {
            $this->authorize('accept', $response);

            $this->escalationService->acceptResponse($escalation, $response, $request->user(), $request->validated());

            return back()->with('success', "Response accepted — {$escalation->reference} moves to action tracking.");
        }

        $this->authorize('review', $response);

        $this->escalationService->rejectResponse($escalation, $response, $request->user(), $request->validated('rejection_reason'));

        return back()->with('warning', "Response rejected — {$escalation->reference} re-issued as round {$escalation->fresh()->round_no}.");
    }

    /** CR1.5 — validate the completed actions and close (or re-issue). */
    public function close(Request $request, ExceptionEscalation $escalation): RedirectResponse
    {
        $this->authorize('close', $escalation);

        $validated = $request->validate([
            'validation_status' => ['required', 'in:Effective,Partially Effective,Ineffective'],
            'closure_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $this->escalationService->close(
            $escalation,
            $request->user(),
            $validated['validation_status'],
            $validated['closure_note'] ?? null,
        );

        return $result->status === 'Closed'
            ? back()->with('success', "{$escalation->reference} validated Effective and closed.")
            : back()->with('warning', "{$escalation->reference} validated {$validated['validation_status']} — re-issued as round {$result->round_no}.");
    }

    public function withdraw(WithdrawEscalationRequest $request, ExceptionEscalation $escalation): RedirectResponse
    {
        $this->authorize('withdraw', $escalation);

        $this->escalationService->withdraw($escalation, $request->user(), $request->validated('reason'));

        return back()->with('success', "{$escalation->reference} withdrawn.");
    }

    /** R-H — generate the one-time secure answer link for an external party. */
    public function createLink(Request $request, ExceptionEscalation $escalation): RedirectResponse
    {
        $this->authorize('manageLinks', $escalation);

        $validated = $request->validate([
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $result = $this->responseService->createSecureLink(
            $escalation,
            $request->user(),
            isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null,
        );

        // The plaintext URL is flashed ONCE and never persisted.
        return back()
            ->with('success', 'Secure link generated — copy it now; it will not be shown again.')
            ->with('secureLinkUrl', $result['url']);
    }

    public function revokeLink(Request $request, ExceptionEscalation $escalation, int $link): RedirectResponse
    {
        $this->authorize('manageLinks', $escalation);

        $linkModel = $escalation->responseLinks()->findOrFail($link);

        $this->responseService->revokeLink($linkModel, $request->user());

        return back()->with('success', 'Secure link revoked.');
    }
}
