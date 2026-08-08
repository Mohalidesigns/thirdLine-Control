<?php

namespace App\Http\Controllers;

use App\Http\Requests\RiskAssessmentRequest;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Services\RiskAssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RiskAssessmentController extends Controller
{
    public function __construct(private RiskAssessmentService $assessments) {}

    public function store(RiskAssessmentRequest $request, Risk $risk): RedirectResponse
    {
        $this->authorize('assess', $risk);

        $assessment = $this->assessments->record($risk, $request->validated(), $request->user());

        if ($this->assessments->requiresSecondLineReview($assessment)) {
            $this->assessments->submitForReview($assessment);

            return back()->with('success',
                "Assessment recorded at score {$assessment->score} — submitted for second-line review.");
        }

        $this->assessments->publish($assessment, $request->user());

        return back()->with('success', "Assessment published: {$assessment->rating} ({$assessment->score}).");
    }

    public function publish(Request $request, Risk $risk, RiskAssessment $assessment): RedirectResponse
    {
        $this->authorize('review', $risk);
        abort_unless($assessment->risk_id === $risk->id, 404);

        $this->assessments->publish($assessment, $request->user());

        return back()->with('success', 'Assessment published.');
    }

    public function reject(Request $request, Risk $risk, RiskAssessment $assessment): RedirectResponse
    {
        $this->authorize('review', $risk);
        abort_unless($assessment->risk_id === $risk->id, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->assessments->reject($assessment, $request->user(), $validated['reason']);

        return back()->with('success', 'Assessment returned to the assessor.');
    }

    /**
     * Re-run the Monte Carlo simulation. Deterministic under the tenant's
     * configured seed; an explicit seed is accepted so a reviewer can
     * reproduce a published figure exactly.
     */
    public function simulate(Request $request, Risk $risk, RiskAssessment $assessment): JsonResponse
    {
        $this->authorize('assess', $risk);
        abort_unless($assessment->risk_id === $risk->id, 404);

        $validated = $request->validate(['seed' => ['nullable', 'integer', 'min:1']]);

        abort_unless($this->assessments->hasQuantitativeInputs($assessment), 422,
            'This assessment has no quantitative loss inputs to simulate.');

        return response()->json(
            $this->assessments->simulate($assessment, $validated['seed'] ?? null)
        );
    }
}
