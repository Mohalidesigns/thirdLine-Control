<?php

namespace App\Http\Controllers;

use App\Http\Requests\ObligationAssignmentRequest;
use App\Models\ObligationAssignment;
use App\Models\OrganisationUnit;
use App\Models\RegulatoryObligation;
use App\Services\ObligationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ObligationAssignmentController extends Controller
{
    public function __construct(private ObligationService $obligations) {}

    public function store(ObligationAssignmentRequest $request): RedirectResponse
    {
        $this->authorize('create', ObligationAssignment::class);

        $data = $request->validated();

        $assignment = ObligationAssignment::updateOrCreate(
            [
                'tenant_id' => $request->user()->tenant_id,
                'obligation_id' => $data['obligation_id'],
                'entity_id' => $data['entity_id'],
            ],
            [
                'owner_id' => $data['owner_id'],
                'reviewer_id' => $data['reviewer_id'] ?? null,
                'is_applicable' => true,
            ],
        );

        // Populate the calendar immediately so the owner sees their deadlines
        // without waiting for the nightly run.
        $created = $this->obligations->generateInstances($assignment);

        return back()->with('success', "Obligation assigned — {$created} calendar entr(y/ies) generated.");
    }

    public function update(ObligationAssignmentRequest $request, ObligationAssignment $assignment): RedirectResponse
    {
        $this->authorize('update', $assignment);

        $assignment->update($request->safe()->only(['owner_id', 'reviewer_id']));

        return back()->with('success', 'Assignment updated.');
    }

    /** Bulk-assign every obligation that applies to an entity. */
    public function bulkStore(Request $request, OrganisationUnit $entity): RedirectResponse
    {
        $this->authorize('create', ObligationAssignment::class);

        $data = $request->validate([
            'obligation_ids' => ['required', 'array', 'max:200'],
            'obligation_ids.*' => ['integer', 'exists:regulatory_obligations,id'],
            'owner_id' => ['required', 'exists:users,id'],
            'reviewer_id' => ['nullable', 'different:owner_id', 'exists:users,id'],
        ]);

        $assigned = 0;

        foreach (RegulatoryObligation::whereIn('id', $data['obligation_ids'])->get() as $obligation) {
            $assignment = ObligationAssignment::updateOrCreate(
                [
                    'tenant_id' => $request->user()->tenant_id,
                    'obligation_id' => $obligation->id,
                    'entity_id' => $entity->id,
                ],
                [
                    'owner_id' => $data['owner_id'],
                    'reviewer_id' => $data['reviewer_id'] ?? null,
                    'is_applicable' => true,
                ],
            );

            $this->obligations->generateInstances($assignment);
            $assigned++;
        }

        return back()->with('success', "{$assigned} obligation(s) assigned to {$entity->name}.");
    }

    /**
     * Declaring an obligation inapplicable needs a reason and, before it
     * suppresses anything, a second person's approval (R2).
     */
    public function declareNonApplicable(Request $request, ObligationAssignment $assignment): RedirectResponse
    {
        $this->authorize('declareNonApplicable', $assignment);

        $data = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:2000']]);

        $this->obligations->declareNonApplicable($assignment, $request->user(), $data['reason']);

        return back()->with('warning', 'Non-applicability declared — it takes effect once approved by a second person.');
    }

    public function approveNonApplicability(Request $request, ObligationAssignment $assignment): RedirectResponse
    {
        $this->authorize('approveNonApplicability', $assignment);

        $this->obligations->approveNonApplicability($assignment, $request->user());

        return back()->with('success', 'Non-applicability approved — no further calendar entries will be generated.');
    }
}
