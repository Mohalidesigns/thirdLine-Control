<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvestigationSubjectRequest;
use App\Models\Investigation;
use App\Models\InvestigationSubject;
use App\Services\InvestigationService;
use Illuminate\Http\RedirectResponse;

/**
 * Naming the people an investigation is about (CR-04 §C.3).
 *
 * Two rules the service keeps and this controller does not restate: a
 * subject may never also be on the team (§D.4-1), and an outcome recorded
 * against a named person always carries a written rationale.
 */
class InvestigationSubjectController extends Controller
{
    public function __construct(private InvestigationService $investigations) {}

    public function store(InvestigationSubjectRequest $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('update', $investigation);

        $subject = $this->investigations->addSubject($investigation, $request->validated(), $request->user());

        return back()->with('success', "{$subject->name} named on the investigation.");
    }

    public function update(InvestigationSubjectRequest $request, Investigation $investigation, InvestigationSubject $subject): RedirectResponse
    {
        $this->authorize('update', $investigation);

        abort_unless($subject->investigation_id === $investigation->id, 404);

        $data = $request->validated();

        $subject->update(collect($data)->only([
            'subject_type', 'name', 'user_id', 'staff_id', 'account_number',
            'department', 'organisation_unit_id', 'position', 'role_in_case', 'notes',
        ])->all());

        if (array_key_exists('outcome', $data) && $data['outcome'] !== null && $data['outcome'] !== $subject->outcome) {
            $this->investigations->recordSubjectOutcome(
                $subject,
                $data['outcome'],
                $data['outcome_rationale'] ?? null,
                $request->user(),
            );
        }

        return back()->with('success', 'Subject updated.');
    }
}
