<?php

namespace App\Http\Controllers;

use App\Models\CsaCampaign;
use App\Models\CsaResponse;
use App\Services\CsaService;
use App\Services\SurveyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CsaResponseController extends Controller
{
    public function __construct(
        private CsaService $csaService,
        private SurveyService $surveyService,
    ) {}

    /** My assigned responses across open campaigns. */
    public function index(Request $request)
    {
        $user = $request->user();

        $responses = $user->csaResponses()
            ->with(['campaign:id,reference,name,campaign_type,status,closes_at', 'entity:id,name', 'control:id,control_ref,title'])
            ->whereHas('campaign', fn ($q) => $q->whereIn('status', ['Open', 'Closed']))
            ->orderByRaw("case status when 'Returned' then 0 when 'Not Started' then 1 when 'In Progress' then 2 else 3 end")
            ->paginate(15);

        return Inertia::render('Csa/MyResponses', ['responses' => $responses]);
    }

    /** The respondent's answer sheet. */
    public function show(CsaResponse $response)
    {
        $this->authorize('view', $response);

        $user = auth()->user();

        return Inertia::render('Csa/Respond', [
            'response' => $response->load([
                'campaign:id,reference,name,campaign_type,status,closes_at,is_anonymous,variance_threshold',
                'entity:id,name', 'control:id,control_ref,title',
                'answers', 'reviewer:id,name',
            ]),
            'questions' => $response->questionnaire->questions()->get(),
            'instructions' => $response->questionnaire->instructions,
            'can' => [
                'respond' => $user->can('respond', $response),
                'review' => $user->can('review', $response),
                'approveRating' => $user->can('approveRating', $response),
            ],
        ]);
    }

    public function save(Request $request, CsaResponse $response)
    {
        $this->authorize('respond', $response);

        $validated = $this->validateAnswers($request);

        $this->csaService->saveAnswers($response, $validated['answers'], $request->user(), $validated['submit']);

        return back()->with('success', $validated['submit'] ? 'Response submitted for review.' : 'Draft saved.');
    }

    public function review(Request $request, CsaResponse $response)
    {
        $this->authorize('review', $response);

        $validated = $request->validate([
            'decision' => ['required', 'in:accept,return'],
            'reviewer_rating' => ['nullable', 'string', 'max:50'],
            'review_notes' => ['required_if:decision,return', 'nullable', 'string'],
        ]);

        $reviewed = $this->csaService->review($response, $request->user(), $validated);

        return back()->with($reviewed->variance_flag ? 'warning' : 'success', $reviewed->variance_flag
            ? 'Review recorded — the self/reviewer rating variance has been flagged to the Control Function Head.'
            : 'Review recorded.');
    }

    public function approveRating(CsaResponse $response)
    {
        $this->authorize('approveRating', $response);

        $this->csaService->approveProposedRating($response, auth()->user());

        return back()->with('success', 'Proposed rating applied to the control.');
    }

    /** Anonymous survey submission (9.4): the stored row carries no identity. */
    public function submitAnonymous(Request $request, CsaCampaign $campaign)
    {
        abort_unless($request->user()->can('respond campaigns'), 403);

        $validated = $this->validateAnswers($request);

        $this->surveyService->submitAnonymous($campaign, $validated['answers']);

        return redirect()->route('csa-campaigns.index', ['type' => 'survey'])
            ->with('success', 'Thank you — your anonymous response has been recorded.');
    }

    private function validateAnswers(Request $request): array
    {
        return $request->validate([
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.value' => ['nullable'],
            'answers.*.comment' => ['nullable', 'string'],
            'answers.*.evidence_id' => ['nullable', 'integer', 'exists:evidence,id'],
            'submit' => ['boolean'],
        ]) + ['submit' => $request->boolean('submit')];
    }
}
