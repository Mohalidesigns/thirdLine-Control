<?php

namespace App\Http\Controllers;

use App\Models\SpeakUpCase;
use App\Models\SpeakUpMetadataAccessLog;
use App\Models\SpeakUpRevealRequest;
use App\Services\SpeakUpMetadataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reporter technical metadata screens (CR).
 *
 * Layered gates, in order: the case allowlist (route-model binding runs
 * through the model's global scope, and view is re-authorised here), then
 * the metadata permissions —
 *
 *   speak_up.metadata.view_basic      Tier 1 + signals
 *   speak_up.metadata.request_reveal  open a break-glass request
 *   speak_up.metadata.approve_reveal  decide someone else's request,
 *                                     set/lift legal hold
 *   speak_up.metadata.audit_log       read the Metadata Access Log
 *
 * Tier 2 is never rendered without a usable (approved, unexpired) reveal
 * belonging to the viewer, and only when the request explicitly asks
 * (?reveal=1) — every render writes the access log, so an idle page
 * refresh must not silently mint access records.
 */
class SpeakUpMetadataController extends Controller
{
    public function __construct(private SpeakUpMetadataService $metadata) {}

    public function show(Request $request, SpeakUpCase $case): Response
    {
        $this->authorize('view', $case);
        abort_unless($request->user()->can('speak_up.metadata.view_basic'), 403);

        $row = $case->metadata()->first();

        $revealRequests = SpeakUpRevealRequest::where('report_id', $case->id)
            ->with(['requester:id,name', 'decider:id,name'])
            ->orderByDesc('created_at')
            ->get();

        $usable = $this->metadata->usableReveal($case, $request->user());

        // Tier 2 renders only on an explicit ask, and every render logs.
        $tier2 = ($usable && $request->boolean('reveal'))
            ? $this->metadata->reveal($case, $request->user())
            : null;

        $canReadLog = $request->user()->can('speak_up.metadata.audit_log');

        return Inertia::render('Cases/Metadata', [
            'case' => $case->only(['id', 'case_ref', 'title', 'status', 'is_anonymous',
                'legal_hold', 'legal_hold_reason', 'legal_hold_at']),
            'tier1' => $row?->tier1(),
            'signals' => $this->metadata->signals($case),
            'tier2' => $tier2,
            'purgeAfter' => $row?->purge_after?->toIso8601String(),
            'revealRequests' => $revealRequests,
            'hasUsableReveal' => $usable !== null,
            'reasonCodes' => $this->metadata->reasonCodes(),
            'accessLog' => $canReadLog
                ? SpeakUpMetadataAccessLog::where('report_id', $case->id)
                    ->with(['requester:id,name', 'approver:id,name'])
                    ->orderByDesc('occurred_at')
                    ->limit(200)
                    ->get()
                : null,
            'can' => [
                'request_reveal' => $request->user()->can('speak_up.metadata.request_reveal'),
                'approve_reveal' => $request->user()->can('speak_up.metadata.approve_reveal'),
                'audit_log' => $canReadLog,
            ],
        ]);
    }

    public function requestReveal(Request $request, SpeakUpCase $case): RedirectResponse
    {
        $this->authorize('view', $case);
        abort_unless($request->user()->can('speak_up.metadata.request_reveal'), 403);

        $validated = $request->validate([
            'reason_code' => ['required', 'string', 'max:60'],
            'justification' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        $this->metadata->requestReveal($case, $request->user(), $validated['reason_code'], $validated['justification']);

        return back()->with('success', 'Reveal requested — a second approver must now decide it.');
    }

    /**
     * The approver's queue. Deliberately independent of the case
     * allowlist: an approver sees who is asking, the reason and the
     * justification — never the case file and never a metadata value.
     */
    public function revealQueue(Request $request): Response
    {
        $pending = SpeakUpRevealRequest::where('status', 'pending')
            ->with(['requester:id,name', 'report' => fn ($q) => $q->withoutGlobalScope('allowlist')->select('id', 'case_ref')])
            ->orderBy('created_at')
            ->get();

        $decided = SpeakUpRevealRequest::where('status', '!=', 'pending')
            ->with(['requester:id,name', 'decider:id,name', 'report' => fn ($q) => $q->withoutGlobalScope('allowlist')->select('id', 'case_ref')])
            ->orderByDesc('decided_at')
            ->limit(50)
            ->get();

        return Inertia::render('SpeakUp/RevealQueue', [
            'pending' => $pending,
            'decided' => $decided,
            'userId' => $request->user()->id,
        ]);
    }

    public function decideReveal(Request $request, SpeakUpRevealRequest $revealRequest): RedirectResponse
    {
        abort_unless($request->user()->can('speak_up.metadata.approve_reveal'), 403);
        abort_unless($revealRequest->tenant_id === $request->user()->tenant_id, 404);

        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->metadata->decideReveal(
            $revealRequest,
            $request->user(),
            (bool) $validated['approve'],
            $validated['note'] ?? null,
        );

        return back()->with('success', $validated['approve'] ? 'Reveal approved and logged.' : 'Reveal denied and logged.');
    }

    public function setLegalHold(Request $request, SpeakUpCase $case): RedirectResponse
    {
        $this->authorize('view', $case);
        abort_unless($request->user()->can('speak_up.metadata.approve_reveal'), 403);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->metadata->setLegalHold($case, $request->user(), $validated['reason']);

        return back()->with('success', 'Legal hold set — metadata purge is suspended for this case.');
    }

    public function liftLegalHold(Request $request, SpeakUpCase $case): RedirectResponse
    {
        $this->authorize('view', $case);
        abort_unless($request->user()->can('speak_up.metadata.approve_reveal'), 403);

        $this->metadata->liftLegalHold($case, $request->user());

        return back()->with('success', 'Legal hold lifted.');
    }
}
