<?php

namespace App\Services;

use App\Models\Control;
use App\Models\CsaCampaign;
use App\Models\CsaResponse;
use App\Models\TestInstance;
use App\Models\User;

/**
 * "What do I owe, by when" (15.1/15.2): one feed behind both the mobile
 * task view and the offline bootstrap, so what a device can act on
 * offline is exactly what the task list shows. Everything is trimmed to
 * what renders (R6) — no eager relation goes over the wire unless a card
 * displays it.
 */
class MyWorkService
{
    public function __construct(private AttestationService $attestations) {}

    /**
     * @return array{test_instances: array, csa_responses: array, attestations: array, controls: array}
     */
    public function feed(User $user): array
    {
        return [
            'test_instances' => $this->openTestInstances($user),
            'csa_responses' => $this->openCsaResponses($user),
            'attestations' => $this->outstandingAttestations($user),
            'controls' => $this->myControls($user),
        ];
    }

    private function openTestInstances(User $user): array
    {
        return TestInstance::query()
            ->where('assigned_tester_id', $user->id)
            ->whereIn('status', ['Scheduled', 'In Progress', 'Reopened'])
            ->with([
                'control:id,control_ref,title',
                'testScript:id,name',
                'testScript.checkItems:id,test_script_id,sequence,question,guidance,is_mandatory',
                'checkResults:id,test_instance_id,check_item_id,result,comment',
            ])
            ->orderBy('due_date')
            ->limit(50)
            ->get()
            ->map(fn (TestInstance $t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                'period_label' => $t->period_label,
                'status' => $t->status,
                'due_date' => $t->due_date?->toDateString(),
                'is_overdue' => $t->is_overdue,
                'updated_at' => $t->updated_at?->toIso8601String(),
                'control' => $t->control
                    ? ['id' => $t->control->id, 'reference' => $t->control->control_ref, 'title' => $t->control->title]
                    : null,
                'check_items' => $t->testScript?->checkItems->map(fn ($c) => [
                    'id' => $c->id,
                    'sequence' => $c->sequence,
                    'question' => $c->question,
                    'guidance' => $c->guidance,
                    'is_mandatory' => $c->is_mandatory,
                    'result' => $t->checkResults->firstWhere('check_item_id', $c->id)?->only(['result', 'comment']),
                ])->values()->all() ?? [],
            ])
            ->values()
            ->all();
    }

    private function openCsaResponses(User $user): array
    {
        return CsaResponse::query()
            ->where('respondent_id', $user->id)
            ->whereIn('status', ['Not Started', 'In Progress'])
            ->whereHas('campaign', fn ($q) => $q->where('status', 'Open')->where('campaign_type', '!=', 'attestation'))
            ->with([
                'campaign:id,reference,name,closes_at,campaign_type',
                'questionnaire:id,name',
                'questionnaire.questions:id,questionnaire_id,section,sequence,question_text,help_text,response_type,options,is_required',
                'answers:id,response_id,question_id,answer_value,comment',
            ])
            ->limit(25)
            ->get()
            ->map(fn (CsaResponse $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'updated_at' => $r->updated_at?->toIso8601String(),
                'campaign' => $r->campaign?->only(['id', 'reference', 'name', 'closes_at']),
                'questions' => $r->questionnaire?->questions
                    ->sortBy('sequence')
                    ->map(fn ($q) => $q->only([
                        'id', 'section', 'sequence', 'question_text', 'help_text',
                        'response_type', 'options', 'is_required',
                    ]))->values()->all() ?? [],
                'answers' => $r->answers->map(fn ($a) => $a->only(['question_id', 'answer_value', 'comment']))->all(),
            ])
            ->values()
            ->all();
    }

    private function outstandingAttestations(User $user): array
    {
        return CsaCampaign::ofType('attestation')
            ->where('status', 'Open')
            ->whereHas('responses', fn ($q) => $q
                ->where('respondent_id', $user->id)
                ->whereNotIn('status', ['Submitted', 'Accepted']))
            ->orderBy('closes_at')
            ->limit(25)
            ->get()
            ->map(function (CsaCampaign $campaign) {
                $subject = $this->attestations->resolveSubject($campaign);

                if (! $subject) {
                    return null;
                }

                $text = null;

                foreach (['attestation_text', 'body', 'content', 'description', 'objective'] as $field) {
                    if (is_string($subject->getAttribute($field)) && trim($subject->getAttribute($field)) !== '') {
                        $text = $subject->getAttribute($field);
                        break;
                    }
                }

                return [
                    'campaign_id' => $campaign->id,
                    'reference' => $campaign->reference,
                    'name' => $campaign->name,
                    'closes_at' => $campaign->closes_at?->toDateString(),
                    'subject_label' => $subject->getAttribute('title') ?? $subject->getAttribute('name'),
                    // The exact text the user signs — must be readable offline.
                    'subject_text' => $text,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function myControls(User $user): array
    {
        return Control::query()
            ->where('owner_id', $user->id)
            ->whereNotIn('status', ['Retired'])
            ->orderBy('control_ref')
            ->limit(100)
            ->get(['id', 'control_ref', 'title', 'status'])
            ->map(fn (Control $c) => [
                'id' => $c->id,
                'reference' => $c->control_ref,
                'title' => $c->title,
                'status' => $c->status,
            ])
            ->all();
    }
}
