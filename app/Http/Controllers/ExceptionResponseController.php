<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExceptionActionUpdateRequest;
use App\Http\Requests\ExceptionResponseRequest;
use App\Models\Evidence;
use App\Models\ExceptionAction;
use App\Models\ExceptionEscalation;
use App\Services\ExceptionResponseService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CR1.3 — the authenticated departmental response screen, and the
 * department's progress on its committed actions (CR1.5).
 */
class ExceptionResponseController extends Controller
{
    public function __construct(private ExceptionResponseService $responseService) {}

    /**
     * The respond screen. Opening it for the FIRST time records the
     * acknowledgement — the department cannot claim it never saw it.
     */
    public function respond(ExceptionEscalation $escalation): Response
    {
        $this->authorize('respond', $escalation);

        $this->responseService->acknowledge($escalation, auth()->user());

        $escalation->load([
            'exception:id,reference,title,description,severity,status,control_id',
            'exception.control:id,control_ref,title',
            'unit:id,name', 'process:id,name', 'issuer:id,name',
            'responses.responder:id,name', 'responses.reviewer:id,name',
        ]);

        return Inertia::render('ExceptionManager/Respond', [
            'escalation' => $escalation->fresh([
                'exception.control', 'unit', 'process', 'issuer',
                'responses.responder', 'responses.reviewer',
            ]),
            'draft' => $escalation->responses()
                ->where('round_no', $escalation->round_no)
                ->whereNull('submitted_at')
                ->first(),
            'evidence' => Evidence::query()
                ->where('linked_type', ExceptionEscalation::class)
                ->where('linked_id', $escalation->id)
                ->with('uploader:id,name')
                ->latest('uploaded_at')
                ->get(),
        ]);
    }

    /** Save-draft: no status change (CR1.3). */
    public function saveDraft(ExceptionResponseRequest $request, ExceptionEscalation $escalation): RedirectResponse
    {
        $this->authorize('respond', $escalation);

        $this->responseService->saveDraft($escalation, $request->validated(), $request->user());

        return back()->with('success', 'Draft saved — nothing has been submitted yet.');
    }

    /** Submit: the on-the-record answer. */
    public function submit(ExceptionResponseRequest $request, ExceptionEscalation $escalation): RedirectResponse
    {
        $this->authorize('respond', $escalation);

        $this->responseService->submit($escalation, $request->validated(), $request->user());

        return redirect()
            ->route('exception-manager.show', $escalation)
            ->with('success', "Response submitted on {$escalation->reference} — the control function has been notified.");
    }

    // ── Committed actions (CR1.5) ───────────────────────────────────

    public function progressAction(ExceptionActionUpdateRequest $request, ExceptionAction $action): RedirectResponse
    {
        $this->authorize('progress', $action);

        $this->responseService->recordActionProgress(
            $action,
            $request->user(),
            (float) $request->validated('progress_percentage', $action->progress_percentage),
            $request->validated('progress_note'),
        );

        return back()->with('success', "Progress recorded on {$action->reference}.");
    }

    public function completeAction(ExceptionActionUpdateRequest $request, ExceptionAction $action): RedirectResponse
    {
        $this->authorize('complete', $action);

        $this->responseService->completeAction($action, $request->user(), $request->validated('note'));

        return back()->with('success', "{$action->reference} marked completed — awaiting control function validation.");
    }
}
