<?php

namespace App\Services;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\CsaAnswer;
use App\Models\CsaCampaign;
use App\Models\CsaQuestion;
use App\Models\CsaQuestionnaire;
use App\Models\CsaResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The campaign engine behind CSA, attestation and survey campaigns
 * (9.3–9.5). CSA never auto-rates: results surface as a PROPOSED design
 * rating that only a Control Function Head can apply to the control.
 */
class CsaService
{
    public const TRANSITIONS = [
        'Draft' => ['Scheduled', 'Open'],
        'Scheduled' => ['Open', 'Draft'],
        'Open' => ['Closed'],
        'Closed' => ['Archived', 'Open'],
        'Archived' => [],
    ];

    // ── Campaign lifecycle ───────────────────────────────────────────────

    public function createCampaign(array $data, User $user): CsaCampaign
    {
        return DB::transaction(function () use ($data, $user) {
            // Explicit field list: scoring fields belong to the
            // questionnaire, and seeders run unguarded.
            $campaignFields = collect($data)->only([
                'tenant_id', 'name', 'description', 'campaign_type', 'scope_definition',
                'period_label', 'opens_at', 'closes_at', 'reminder_schedule',
                'is_anonymous', 'variance_threshold', 'response_rate_target',
            ])->all();

            $campaign = CsaCampaign::create([
                ...$campaignFields,
                'reference' => CsaCampaign::nextReference('CAM'),
                'status' => 'Draft',
                'created_by' => $user->id,
            ]);

            $campaign->questionnaires()->create([
                'name' => $data['name'].' questionnaire',
                'version_no' => 1,
                'is_active' => true,
                'instructions' => $data['instructions'] ?? null,
                'scoring_method' => $data['scoring_method'] ?? 'none',
                'pass_threshold' => $data['pass_threshold'] ?? null,
            ]);

            return $campaign;
        });
    }

    /** Maker-checker: the creator can never approve their own campaign. */
    public function approveCampaign(CsaCampaign $campaign, User $approver): CsaCampaign
    {
        if ($campaign->created_by === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A campaign cannot be approved by the user who created it.',
            ]);
        }

        if ($campaign->status !== 'Draft') {
            throw ValidationException::withMessages([
                'approval' => 'Only a draft campaign can be approved.',
            ]);
        }

        $campaign->update([
            'status' => 'Scheduled',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        $campaign->auditAction('approved');

        return $campaign;
    }

    public function open(CsaCampaign $campaign): CsaCampaign
    {
        if (! $campaign->approved_at) {
            throw ValidationException::withMessages([
                'status' => 'A campaign must be approved before it opens.',
            ]);
        }

        $this->transition($campaign, 'Open', ['opens_at' => $campaign->opens_at ?? now()]);
        $this->generateResponses($campaign);

        return $campaign;
    }

    public function close(CsaCampaign $campaign): CsaCampaign
    {
        $this->transition($campaign, 'Closed', ['closes_at' => $campaign->closes_at ?? now()]);

        return $campaign;
    }

    public function transition(CsaCampaign $campaign, string $to, array $extra = []): void
    {
        $allowed = self::TRANSITIONS[$campaign->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A campaign cannot move from {$campaign->status} to {$to}.",
            ]);
        }

        $campaign->update(['status' => $to, ...$extra]);
        $campaign->auditAction('status_changed', null, ['to' => $to]);
    }

    /**
     * Materialise Not-Started response rows from the campaign scope.
     * Anonymous campaigns create NO rows up front — a respondent's
     * submission creates an unattributed row (9.4).
     *
     * scope_definition: {"assignments": [{"respondent_id","entity_id","control_id"}...]}
     */
    public function generateResponses(CsaCampaign $campaign): int
    {
        if ($campaign->is_anonymous) {
            return 0;
        }

        $questionnaire = $campaign->activeQuestionnaire();

        if (! $questionnaire) {
            return 0;
        }

        $count = 0;

        foreach ($campaign->scope_definition['assignments'] ?? [] as $assignment) {
            if (empty($assignment['respondent_id'])) {
                continue;
            }

            CsaResponse::firstOrCreate([
                'campaign_id' => $campaign->id,
                'questionnaire_id' => $questionnaire->id,
                'respondent_id' => $assignment['respondent_id'],
                'entity_id' => $assignment['entity_id'] ?? null,
                'control_id' => $assignment['control_id'] ?? null,
            ], [
                'tenant_id' => $campaign->tenant_id,
                'status' => 'Not Started',
            ]);

            $count++;
        }

        return $count;
    }

    // ── Questionnaire builder (9.3) ──────────────────────────────────────

    public function syncQuestions(CsaQuestionnaire $questionnaire, array $questions): void
    {
        if ($questionnaire->campaign->responses()->whereNot('status', 'Not Started')->exists()) {
            throw ValidationException::withMessages([
                'questions' => 'The questionnaire can no longer change: responses are already in progress.',
            ]);
        }

        DB::transaction(function () use ($questionnaire, $questions) {
            $keptIds = [];

            foreach ($questions as $index => $data) {
                $payload = [
                    'section' => $data['section'] ?? null,
                    'sequence' => $index,
                    'question_text' => $data['question_text'],
                    'help_text' => $data['help_text'] ?? null,
                    'response_type' => $data['response_type'] ?? 'yes_no',
                    'options' => $data['options'] ?? null,
                    'is_required' => (bool) ($data['is_required'] ?? true),
                    'evidence_required' => (bool) ($data['evidence_required'] ?? false),
                    'weight' => $data['weight'] ?? 1,
                    'control_id' => $data['control_id'] ?? null,
                    'requirement_id' => $data['requirement_id'] ?? null,
                    'triggers_exception_on' => $data['triggers_exception_on'] ?? null,
                    'condition' => $data['condition'] ?? null,
                ];

                $question = ! empty($data['id'])
                    ? $questionnaire->questions()->find($data['id'])
                    : null;

                $question
                    ? $question->update($payload)
                    : $question = $questionnaire->questions()->create($payload);

                $keptIds[] = $question->id;
            }

            $questionnaire->questions()->whereNotIn('id', $keptIds)->delete();
        });
    }

    // ── Responding ───────────────────────────────────────────────────────

    /**
     * Save (and optionally submit) a response's answers. Enforces
     * conditional visibility, required questions, and evidence
     * requirements; computes the score; raises exceptions on
     * trigger-matching answers when submitted.
     *
     * @param  array<int, array{question_id: int, value: mixed, comment?: ?string, evidence_id?: ?int}>  $answers
     */
    public function saveAnswers(CsaResponse $response, array $answers, ?User $user, bool $submit = false): CsaResponse
    {
        $campaign = $response->campaign;

        if (! $campaign->isOpen()) {
            throw ValidationException::withMessages([
                'campaign' => 'This campaign is not open for responses.',
            ]);
        }

        if (in_array($response->status, ['Submitted', 'Under Review', 'Accepted'], true)) {
            throw ValidationException::withMessages([
                'response' => 'This response has already been submitted.',
            ]);
        }

        return DB::transaction(function () use ($response, $answers, $user, $submit) {
            $questions = $response->questionnaire->questions()->get()->keyBy('id');
            $valuesByQuestion = collect($answers)->keyBy('question_id')->map(fn ($a) => $a['value'] ?? null)->all();

            foreach ($answers as $answer) {
                $question = $questions->get($answer['question_id'] ?? 0);

                if (! $question || ! $question->isVisible($valuesByQuestion)) {
                    continue;
                }

                CsaAnswer::updateOrCreate(
                    ['response_id' => $response->id, 'question_id' => $question->id],
                    [
                        'answer_value' => $answer['value'] ?? null,
                        'comment' => $answer['comment'] ?? null,
                        'evidence_id' => $answer['evidence_id'] ?? null,
                        'answered_at' => now(),
                    ],
                );
            }

            if (! $submit) {
                $response->update(['status' => 'In Progress']);

                return $response;
            }

            $this->assertComplete($response, $questions->all(), $valuesByQuestion);

            $score = $this->score($response);

            $response->update([
                'status' => 'Submitted',
                'submitted_at' => now(),
                'score' => $score,
                'self_rating' => $this->deriveSelfRating($response, $score),
                'proposed_design_rating' => $this->deriveProposedRating($response, $score),
            ]);

            $this->raiseTriggeredExceptions($response, $questions->all(), $valuesByQuestion, $user);

            return $response->refresh();
        });
    }

    /** @param  array<int, CsaQuestion>  $questions */
    private function assertComplete(CsaResponse $response, array $questions, array $values): void
    {
        $answered = $response->answers()->pluck('answer_value', 'question_id');

        foreach ($questions as $question) {
            if (! $question->is_required || ! $question->isVisible($values)) {
                continue;
            }

            $value = $answered->get($question->id);

            if ($value === null || $value === [] || $value === ['']) {
                throw ValidationException::withMessages([
                    "question_{$question->id}" => "'".str($question->question_text)->limit(60)."' requires an answer.",
                ]);
            }

            if ($question->evidence_required
                && ! $response->answers()->where('question_id', $question->id)->whereNotNull('evidence_id')->exists()) {
                throw ValidationException::withMessages([
                    "question_{$question->id}" => "'".str($question->question_text)->limit(60)."' requires supporting evidence.",
                ]);
            }
        }
    }

    /** Weighted / maturity score in 0–100, null when scoring is off. */
    public function score(CsaResponse $response): ?float
    {
        $questionnaire = $response->questionnaire;

        if ($questionnaire->scoring_method === 'none') {
            return null;
        }

        $answers = $response->answers()->with('question')->get();
        $totalWeight = 0.0;
        $achieved = 0.0;

        foreach ($answers as $answer) {
            $question = $answer->question;
            $value = $question?->scoreValue($answer->answer_value);

            if ($value === null) {
                continue;
            }

            $weight = (float) $question->weight ?: 1.0;
            $totalWeight += $weight;
            $achieved += $value * $weight;
        }

        return $totalWeight > 0 ? round($achieved / $totalWeight * 100, 2) : null;
    }

    private function deriveSelfRating(CsaResponse $response, ?float $score): ?string
    {
        $threshold = $response->questionnaire->pass_threshold;

        if ($score === null || $threshold === null) {
            return null;
        }

        return $score >= (float) $threshold ? 'Adequate' : 'Inadequate';
    }

    /**
     * The proposal a Control Function Head may later apply to the control.
     * Never written to the control here (9.3: CSA never auto-rates).
     */
    private function deriveProposedRating(CsaResponse $response, ?float $score): ?string
    {
        return $response->control_id ? $this->deriveSelfRating($response, $score) : null;
    }

    /** @param  array<int, CsaQuestion>  $questions */
    private function raiseTriggeredExceptions(CsaResponse $response, array $questions, array $values, ?User $user): void
    {
        foreach ($questions as $question) {
            $value = $values[$question->id] ?? null;

            if (! $question->isVisible($values) || ! $question->triggersException($value)) {
                continue;
            }

            $controlId = $question->control_id ?? $response->control_id;

            $exists = ControlException::withoutGlobalScopes()
                ->where('source_type', 'CSA')
                ->where('source_id', $response->id)
                ->where('title', 'like', '%Q'.$question->id.']%')
                ->exists();

            if ($exists) {
                continue;
            }

            $control = $controlId ? Control::withoutGlobalScopes()->find($controlId) : null;

            ControlException::create([
                'tenant_id' => $response->tenant_id,
                'reference' => ControlException::nextReference('EXC'),
                'source_type' => 'CSA',
                'source_id' => $response->id,
                'control_id' => $controlId,
                'title' => '[Q'.$question->id.'] CSA flag: '.str($question->question_text)->limit(100),
                'description' => 'Answer "'.collect((array) $value)->implode(', ').'" on campaign '
                    .$response->campaign->reference.' triggered this exception.',
                'severity' => $question->triggers_exception_on['severity'] ?? 'Medium',
                'owner_id' => $control?->owner_id,
                'unit_id' => $response->entity_id ?? $control?->unit_id,
                'raised_by' => $user?->id,
                'date_raised' => now()->toDateString(),
                'target_closure_date' => now()->addDays(30)->toDateString(),
                'status' => $control?->owner_id ? 'Assigned' : 'Open',
            ]);
        }
    }

    // ── Reviewer workflow (9.3) ──────────────────────────────────────────

    /**
     * Reviewer rating vs self rating; a gap beyond the campaign's
     * variance threshold flags the response for the Control Function Head.
     */
    public function review(CsaResponse $response, User $reviewer, array $data): CsaResponse
    {
        if (! in_array($response->status, ['Submitted', 'Under Review'], true)) {
            throw ValidationException::withMessages([
                'review' => 'Only a submitted response can be reviewed.',
            ]);
        }

        if ($response->respondent_id === $reviewer->id) {
            throw ValidationException::withMessages([
                'review' => 'A respondent cannot review their own response.',
            ]);
        }

        $decision = $data['decision'] ?? 'accept';
        $reviewerRating = $data['reviewer_rating'] ?? null;

        $variance = $this->hasVariance($response, $reviewerRating);

        $response->update([
            'status' => $decision === 'return' ? 'Returned' : 'Accepted',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'] ?? null,
            'reviewer_rating' => $reviewerRating,
            'variance_flag' => $variance,
        ]);

        $response->auditAction('reviewed', null, [
            'decision' => $decision,
            'variance' => $variance,
        ]);

        return $response;
    }

    private function hasVariance(CsaResponse $response, ?string $reviewerRating): bool
    {
        if (! $reviewerRating || ! $response->self_rating) {
            return false;
        }

        // Ordinal scale for ratings; adjacent = variance of 1.
        $scale = ['Inadequate' => 0, 'Adequate' => 2];
        $numericScale = fn ($v) => $scale[$v] ?? (is_numeric($v) ? (int) $v : null);

        $self = $numericScale($response->self_rating);
        $reviewer = $numericScale($reviewerRating);

        if ($self === null || $reviewer === null) {
            return $response->self_rating !== $reviewerRating;
        }

        return abs($self - $reviewer) > $response->campaign->variance_threshold;
    }

    // ── Proposed rating approval (9.3, R4-adjacent) ──────────────────────

    /**
     * Apply a CSA-proposed design rating to the control. Only here — after
     * an explicit Control Function Head approval — does a control rating
     * change. Enforced again in CsaResponsePolicy.
     */
    public function approveProposedRating(CsaResponse $response, User $approver): CsaResponse
    {
        if (! $response->proposed_design_rating || ! $response->control_id) {
            throw ValidationException::withMessages([
                'rating' => 'This response carries no proposed rating.',
            ]);
        }

        if ($response->status !== 'Accepted') {
            throw ValidationException::withMessages([
                'rating' => 'A proposed rating can only be applied after the response review is accepted.',
            ]);
        }

        if (! $approver->hasRole('Control Function Head')) {
            throw ValidationException::withMessages([
                'rating' => 'Only a Control Function Head can apply a CSA-proposed rating.',
            ]);
        }

        return DB::transaction(function () use ($response, $approver) {
            app(ControlService::class)->assess($response->control, $approver, [
                'period_label' => $response->campaign->period_label,
                'design_result' => $response->reviewer_rating ?: $response->proposed_design_rating,
                'notes' => "CSA campaign {$response->campaign->reference} response #{$response->id}",
            ]);

            $response->update([
                'rating_approved_by' => $approver->id,
                'rating_approved_at' => now(),
            ]);

            $response->auditAction('proposed_rating_applied', null, [
                'rating' => $response->proposed_design_rating,
                'by' => $approver->id,
            ]);

            return $response;
        });
    }
}
