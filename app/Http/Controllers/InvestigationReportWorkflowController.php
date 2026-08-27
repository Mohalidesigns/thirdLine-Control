<?php

namespace App\Http\Controllers;

use App\Models\Investigation;
use App\Models\InvestigationReport;
use App\Services\InvestigationReportWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spec §5.3 — the investigation report's review chain.
 *
 * Every action here authorises against the INVESTIGATION, not the report.
 * The report has no visibility scope of its own, and it must not acquire
 * one: a confidential case's report is confidential because the case is,
 * and deriving that from one place means the two cannot drift. Route-model
 * binding is scoped to the investigation for the same reason — a report id
 * from another case cannot be smuggled in through the URL.
 */
class InvestigationReportWorkflowController extends Controller
{
    public function __construct(private InvestigationReportWorkflow $workflow) {}

    public function show(Request $request, Investigation $investigation, InvestigationReport $report): Response
    {
        $this->authorize('view', $investigation);

        $user = $request->user();

        $report->load([
            'preparedBy:id,name', 'managerReviewedBy:id,name',
            'ghicReviewedBy:id,name', 'approvedBy:id,name', 'run',
        ]);

        return Inertia::render('Investigations/Report', [
            'investigation' => $investigation->load('leadInvestigator:id,name', 'controlEntity:id,name', 'organisationUnit:id,name'),
            'report' => $report,
            // The frozen snapshot once issued, the live record before that.
            'document' => $this->workflow->documentFor($report, $user),
            'states' => InvestigationReport::STATES,
            'can' => [
                'submit' => $report->workflow_state === 'draft' && $user->can('edit investigations'),
                'review' => $report->workflow_state === 'manager_review' && $user->can('review investigation-reports'),
                'approve' => $report->workflow_state === 'ghic_review' && $user->can('approve investigation-reports'),
                'issue' => $report->workflow_state === 'approved' && $user->can('issue investigation-reports'),
                'return' => $report->isReturnable() && (
                    $user->can('review investigation-reports') || $user->can('approve investigation-reports')
                ),
                'download' => $report->isIssued() && $report->report_run_id !== null,
            ],
        ]);
    }

    /**
     * One endpoint for the four forward steps. The target is validated
     * against the model's transition map rather than trusted, so a posted
     * state cannot skip a node.
     */
    public function advance(Request $request, Investigation $investigation, InvestigationReport $report): RedirectResponse
    {
        $this->authorize('view', $investigation);

        $data = $request->validate([
            'to' => ['required', 'string', 'in:'.implode(',', InvestigationReport::STATES)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $report = $this->workflow->advance($report, $request->user(), $data['to'], $data['comment'] ?? null);

        return back()->with('success', "{$report->report_number} moved on.");
    }

    public function returnToPreparer(Request $request, Investigation $investigation, InvestigationReport $report): RedirectResponse
    {
        $this->authorize('view', $investigation);

        $data = $request->validate([
            'returned_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $report = $this->workflow->returnToPreparer($report, $request->user(), $data['returned_reason']);

        return back()->with('warning', "{$report->report_number} returned to the preparer.");
    }

    public function issue(Request $request, Investigation $investigation, InvestigationReport $report): RedirectResponse
    {
        $this->authorize('view', $investigation);

        $report = $this->workflow->issue($report, $request->user());

        return back()->with('success', "{$report->report_number} issued. It is now fixed — later changes to the case produce a new version.");
    }

    /**
     * Spec §8.5 — a further version after -R01 was issued, rather than a
     * rewrite of it.
     */
    public function store(Request $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('update', $investigation);

        $report = $this->workflow->openNextVersion($investigation, $request->user());

        return back()->with('success', "{$report->report_number} opened as version {$report->version}.");
    }
}
