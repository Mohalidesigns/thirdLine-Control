<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvestigationFindingRequest;
use App\Models\ImprovementAction;
use App\Models\Investigation;
use App\Models\InvestigationFinding;
use App\Services\ConsequenceService;
use App\Services\InvestigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Findings of fact (CR-04 §C.4, §F.1, §F.2).
 *
 * raiseImprovement() is the loop that makes this module worth running
 * inside a control product: a recommendation stops being a paragraph in a
 * report and becomes tracked work with an owner, a due date and
 * independent verification.
 */
class InvestigationFindingController extends Controller
{
    public function __construct(
        private InvestigationService $investigations,
        private ConsequenceService $consequences,
    ) {}

    public function store(InvestigationFindingRequest $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('update', $investigation);

        $finding = $this->investigations->addFinding($investigation, $request->validated(), $request->user());

        return back()->with('success', "Finding {$finding->reference} recorded.");
    }

    public function update(InvestigationFindingRequest $request, Investigation $investigation, InvestigationFinding $finding): RedirectResponse
    {
        $this->authorize('update', $investigation);

        abort_unless($finding->investigation_id === $investigation->id, 404);

        $this->investigations->updateFinding($finding, $request->validated(), $request->user());

        return back()->with('success', "Finding {$finding->reference} updated.");
    }

    public function raiseImprovement(Request $request, Investigation $investigation, InvestigationFinding $finding): RedirectResponse
    {
        $this->authorize('update', $investigation);

        abort_unless($finding->investigation_id === $investigation->id, 404);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'owner_id' => ['nullable', 'tenant_user'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(ImprovementAction::PRIORITIES)],
        ]);

        $improvement = $this->consequences->raiseImprovementFromFinding($finding, $validated, $request->user());

        return back()->with('success', "Improvement action {$improvement->reference} raised from {$finding->reference}.");
    }
}
