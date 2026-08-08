<?php

namespace App\Services;

use App\Models\CsaCampaign;
use App\Models\CsaResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * General surveys (9.4) ride on the CSA campaign machinery with one hard
 * guarantee: when a campaign is anonymous, no identifying column is ever
 * written — not on the response, not on the answers, not in the audit
 * trail. Proven in SurveyAnonymityTest.
 */
class SurveyService
{
    public function __construct(private CsaService $csaService) {}

    /**
     * Submit an anonymous survey response. The authenticated user opens
     * the survey, but the stored row carries no trace of them.
     *
     * @param  array<int, array{question_id: int, value: mixed, comment?: ?string}>  $answers
     */
    public function submitAnonymous(CsaCampaign $campaign, array $answers): CsaResponse
    {
        if ($campaign->campaign_type !== 'survey' || ! $campaign->is_anonymous) {
            throw ValidationException::withMessages([
                'campaign' => 'Anonymous submission is only available on anonymous survey campaigns.',
            ]);
        }

        if (! $campaign->isOpen()) {
            throw ValidationException::withMessages([
                'campaign' => 'This survey is not open for responses.',
            ]);
        }

        $questionnaire = $campaign->activeQuestionnaire();

        return DB::transaction(function () use ($campaign, $questionnaire, $answers) {
            $response = CsaResponse::create([
                'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->id,
                'questionnaire_id' => $questionnaire->id,
                'respondent_id' => null,
                'entity_id' => null,
                'status' => 'Not Started',
            ]);

            // Evidence uploads are identifying by nature — strip them.
            $sanitised = array_map(
                fn ($a) => ['question_id' => $a['question_id'], 'value' => $a['value'] ?? null, 'comment' => $a['comment'] ?? null],
                $answers,
            );

            return $this->csaService->saveAnswers($response, $sanitised, null, submit: true);
        });
    }

    /** Named (non-anonymous) surveys submit like any other campaign response. */
    public function submitNamed(CsaResponse $response, array $answers, User $user, bool $submit): CsaResponse
    {
        return $this->csaService->saveAnswers($response, $answers, $user, $submit);
    }

    /** Aggregate results only — anonymous surveys never list responses. */
    public function results(CsaCampaign $campaign): array
    {
        $questionnaire = $campaign->activeQuestionnaire();

        if (! $questionnaire) {
            return ['questions' => [], 'response_count' => 0];
        }

        $questions = $questionnaire->questions()->with('answers')->get();

        return [
            'response_count' => $campaign->responses()
                ->whereIn('status', ['Submitted', 'Under Review', 'Accepted'])
                ->count(),
            'response_rate' => $campaign->responseRate(),
            'questions' => $questions->map(function ($question) {
                $values = $question->answers
                    ->flatMap(fn ($a) => (array) $a->answer_value)
                    ->filter(fn ($v) => $v !== null && $v !== '');

                return [
                    'id' => $question->id,
                    'question_text' => $question->question_text,
                    'response_type' => $question->response_type,
                    'answers' => $values->countBy()->sortDesc()->all(),
                    'average' => $values->every(fn ($v) => is_numeric($v)) && $values->isNotEmpty()
                        ? round($values->avg(), 2)
                        : null,
                ];
            })->values()->all(),
        ];
    }
}
