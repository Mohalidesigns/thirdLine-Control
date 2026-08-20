<?php

namespace Database\Seeders;

use App\Models\Control;
use App\Models\CsaCampaign;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\ImprovementAction;
use App\Models\OrganisationUnit;
use App\Models\User;
use App\Services\AttestationService;
use App\Services\CsaService;
use App\Services\DistributionService;
use App\Services\ImprovementService;
use App\Services\SurveyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

/**
 * Phase 9 demo data: a distributed group control with implementation
 * progress, an open CSA campaign, an anonymous risk-culture survey, an
 * attestation campaign over a published policy, a small document library
 * and a set of improvement actions — a realistic Nigerian bank dataset.
 */
class Phase9DemoSeeder extends Seeder
{
    use Concerns\ResolvesDemoTenant;

    public function run(): void
    {
        $tenant = $this->demoTenant();

        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        $cfh = User::withoutGlobalScopes()->where('email', 'cfh@secondline.test')->first();
        $officer1 = User::withoutGlobalScopes()->where('email', 'officer1@secondline.test')->first();
        $owner1 = User::withoutGlobalScopes()->where('email', 'owner1@secondline.test')->first();
        $owner2 = User::withoutGlobalScopes()->where('email', 'owner2@secondline.test')->first();

        if (! $cfh || ! $officer1 || ! $owner1 || ! $owner2) {
            return;
        }

        $ops = OrganisationUnit::withoutGlobalScopes()->where('tenant_id', $tid)->where('name', 'Operations')->first();
        $marina = OrganisationUnit::withoutGlobalScopes()->where('tenant_id', $tid)->where('name', 'Marina Branch')->first();

        Auth::login($officer1);

        try {
            $group = $this->seedDistribution($tid, $cfh, $officer1, $owner1, $owner2, $ops, $marina);
            $this->seedCsaCampaign($tid, $cfh, $officer1, $owner1, $owner2);
            $this->seedAnonymousSurvey($tid, $cfh, $officer1);
            $policy = $this->seedDocuments($tid, $cfh, $officer1);
            $this->seedAttestationCampaign($tid, $cfh, $officer1, $owner1, $owner2, $policy);
            $this->seedImprovements($tid, $cfh, $officer1, $owner1, $group);
        } finally {
            Auth::logout();
        }
    }

    private function seedDistribution(int $tid, User $cfh, User $officer, User $owner1, User $owner2, ?OrganisationUnit $ops, ?OrganisationUnit $marina): ?Control
    {
        if (! $ops || ! $marina) {
            return null;
        }

        $group = Control::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tid, 'title' => 'Group standard: dormant account reactivation control'],
            [
                'control_ref' => Control::nextReference('CTL', false, 'control_ref'),
                'description' => 'Reactivation of a dormant account requires documented customer instruction, branch-manager approval and next-day head-office review.',
                'objective' => 'Prevent unauthorised reactivation and looting of dormant accounts.',
                'type' => 'Preventive',
                'nature' => 'Manual',
                'frequency' => 'Event-driven',
                'status' => 'Active',
                'library_level' => 'group',
                'is_distributable' => true,
                'function_grouping' => 'Protect',
                'control_level' => 'Operational',
                'is_key_control' => true,
                'created_by' => $officer->id,
                'approved_by' => $cfh->id,
                'approved_at' => now()->subDays(30),
                'current_version' => 1,
            ],
        );

        if ($group->distributions()->exists()) {
            return $group;
        }

        $service = app(DistributionService::class);

        $ops->update(['head_user_id' => $ops->head_user_id ?? $owner1->id]);
        $marina->update(['head_user_id' => $marina->head_user_id ?? $owner2->id]);

        $distributions = $service->distribute($group, [$ops->id, $marina->id], $officer, [
            'due_at' => now()->addDays(45)->toDateString(),
            'tasks' => [
                ['title' => 'Adopt the group procedure into the local operating manual'],
                ['title' => 'Train branch staff on the reactivation checklist', 'evidence_required' => true],
                ['title' => 'Confirm system flags for dormant accounts are active'],
            ],
        ]);

        // Operations is mid-implementation: acknowledge + complete one task.
        $first = $distributions->firstWhere('entity_id', $ops->id);

        if ($first && $first->status === 'Pending') {
            $service->acknowledge($first, $owner1);
            $service->updateTask($first->tasks()->first(), ['status' => 'Complete'], $owner1);
        }

        return $group;
    }

    private function seedCsaCampaign(int $tid, User $cfh, User $officer, User $owner1, User $owner2): void
    {
        if (CsaCampaign::withoutGlobalScopes()->where('tenant_id', $tid)->where('campaign_type', 'csa')->exists()) {
            return;
        }

        $controls = Control::withoutGlobalScopes()
            ->where('tenant_id', $tid)
            ->whereIn('control_ref', ['CTL-001', 'CTL-002'])
            ->get()
            ->keyBy('control_ref');

        $service = app(CsaService::class);

        $campaign = $service->createCampaign([
            'tenant_id' => $tid,
            'name' => 'Q3 2026 Branch Control Self-Assessment',
            'description' => 'Quarterly self-assessment of key branch controls across payment and cash management.',
            'campaign_type' => 'csa',
            'period_label' => 'Q3 2026',
            'closes_at' => now()->addDays(21),
            'scoring_method' => 'weighted',
            'pass_threshold' => 70,
            'response_rate_target' => 90,
            'scope_definition' => [
                'assignments' => collect([
                    ['respondent_id' => $owner1->id, 'control_id' => $controls['CTL-001']->id ?? null],
                    ['respondent_id' => $owner2->id, 'control_id' => $controls['CTL-002']->id ?? null],
                ])->filter(fn ($a) => $a['control_id'])->values()->all(),
            ],
        ], $officer);

        $service->syncQuestions($campaign->activeQuestionnaire(), [
            [
                'section' => 'Design',
                'question_text' => 'Is the control documented in the current operating manual?',
                'response_type' => 'yes_no',
                'weight' => 1,
                'triggers_exception_on' => ['in' => ['No'], 'severity' => 'Medium'],
            ],
            [
                'section' => 'Design',
                'question_text' => 'Where is the documentation gap?',
                'response_type' => 'free_text',
                'is_required' => true,
                'weight' => 0,
            ],
            [
                'section' => 'Operation',
                'question_text' => 'Has the control operated without override in the period?',
                'response_type' => 'yes_no',
                'weight' => 2,
                'triggers_exception_on' => ['in' => ['No'], 'severity' => 'High'],
            ],
            [
                'section' => 'Operation',
                'question_text' => 'Rate the maturity of this control.',
                'response_type' => 'maturity_0_5',
                'weight' => 1,
            ],
        ]);

        $questions = $campaign->activeQuestionnaire()->questions()->get();
        $questions[1]->update(['condition' => ['question_id' => $questions[0]->id, 'in' => ['No']]]);

        $service->approveCampaign($campaign, $cfh);
        $service->open($campaign->fresh());

        // owner1 has already submitted a healthy response.
        $response = $campaign->responses()->where('respondent_id', $owner1->id)->first();

        if ($response) {
            $service->saveAnswers($response, [
                ['question_id' => $questions[0]->id, 'value' => 'Yes'],
                ['question_id' => $questions[2]->id, 'value' => 'Yes'],
                ['question_id' => $questions[3]->id, 'value' => 4, 'comment' => 'Dual control consistently applied.'],
            ], $owner1, submit: true);
        }
    }

    private function seedAnonymousSurvey(int $tid, User $cfh, User $officer): void
    {
        if (CsaCampaign::withoutGlobalScopes()->where('tenant_id', $tid)->where('campaign_type', 'survey')->exists()) {
            return;
        }

        $service = app(CsaService::class);

        $survey = $service->createCampaign([
            'tenant_id' => $tid,
            'name' => '2026 Risk Culture Pulse Survey',
            'description' => 'Anonymous pulse survey on risk culture and speak-up confidence.',
            'campaign_type' => 'survey',
            'is_anonymous' => true,
            'closes_at' => now()->addDays(30),
            'scope_definition' => ['invited_count' => 45],
        ], $officer);

        $service->syncQuestions($survey->activeQuestionnaire(), [
            ['question_text' => 'I feel safe reporting a control failure without fear of blame.', 'response_type' => 'scale_1_5'],
            ['question_text' => 'Management acts on control weaknesses we report.', 'response_type' => 'scale_1_5'],
            ['question_text' => 'What one thing would most improve our control culture?', 'response_type' => 'free_text', 'is_required' => false],
        ]);

        $service->approveCampaign($survey, $cfh);
        $service->open($survey->fresh());

        $questions = $survey->activeQuestionnaire()->questions()->get();
        $surveyService = app(SurveyService::class);

        foreach ([[4, 4, 'More timely feedback on reported issues.'], [3, 4, null], [5, 5, null]] as [$q1, $q2, $comment]) {
            $surveyService->submitAnonymous($survey->fresh(), array_values(array_filter([
                ['question_id' => $questions[0]->id, 'value' => $q1],
                ['question_id' => $questions[1]->id, 'value' => $q2],
                $comment ? ['question_id' => $questions[2]->id, 'value' => $comment] : null,
            ])));
        }
    }

    private function seedDocuments(int $tid, User $cfh, User $officer): ?Document
    {
        if (Document::withoutGlobalScopes()->where('tenant_id', $tid)->exists()) {
            return Document::withoutGlobalScopes()->where('tenant_id', $tid)->where('document_type', 'policy')->first();
        }

        $policies = DocumentFolder::firstOrCreate(['tenant_id' => $tid, 'parent_id' => null, 'name' => 'Policies'], ['path' => 'Policies']);
        $procedures = DocumentFolder::firstOrCreate(['tenant_id' => $tid, 'parent_id' => null, 'name' => 'Procedures'], ['path' => 'Procedures']);
        DocumentFolder::firstOrCreate(
            ['tenant_id' => $tid, 'parent_id' => $policies->id, 'name' => 'Conduct & Ethics'],
            ['path' => 'Policies / Conduct & Ethics'],
        );

        $codeOfConduct = Document::create([
            'tenant_id' => $tid,
            'folder_id' => $policies->id,
            'reference' => Document::nextReference('DOC'),
            'title' => 'Code of Conduct and Ethics Policy',
            'description' => 'All employees must act with integrity, avoid conflicts of interest, protect customer information, and report suspected fraud or control failures through the whistle-blowing channel without delay. Gifts above ₦50,000 in value must be declared. Breaches attract disciplinary action up to termination and referral to law enforcement.',
            'document_type' => 'policy',
            'status' => 'Published',
            'version_no' => 1,
            'owner_id' => $officer->id,
            'approver_id' => $cfh->id,
            'approved_at' => now()->subDays(20),
            'published_at' => now()->subDays(19),
            'review_due_at' => now()->addMonths(11)->toDateString(),
        ]);

        Document::create([
            'tenant_id' => $tid,
            'folder_id' => $procedures->id,
            'reference' => Document::nextReference('DOC'),
            'title' => 'Dormant Account Reactivation Procedure',
            'description' => 'Step-by-step procedure implementing the group dormant-account control at branch level.',
            'document_type' => 'procedure',
            'status' => 'Under Review',
            'version_no' => 1,
            'owner_id' => $officer->id,
        ]);

        Document::create([
            'tenant_id' => $tid,
            'folder_id' => $policies->id,
            'reference' => Document::nextReference('DOC'),
            'title' => 'Fraud Investigation Playbook',
            'description' => 'Confidential playbook for second-line fraud investigations.',
            'document_type' => 'guideline',
            'status' => 'Published',
            'version_no' => 1,
            'owner_id' => $officer->id,
            'approver_id' => $cfh->id,
            'approved_at' => now()->subDays(10),
            'published_at' => now()->subDays(9),
            'is_confidential' => true,
            'access_role_ids' => Role::whereIn('name', ['System Administrator', 'Control Function Head'])->pluck('id')->all(),
            'review_due_at' => now()->addYear()->toDateString(),
        ]);

        return $codeOfConduct;
    }

    private function seedAttestationCampaign(int $tid, User $cfh, User $officer, User $owner1, User $owner2, ?Document $policy): void
    {
        if (! $policy || CsaCampaign::withoutGlobalScopes()->where('tenant_id', $tid)->where('campaign_type', 'attestation')->exists()) {
            return;
        }

        $service = app(CsaService::class);

        $campaign = $service->createCampaign([
            'tenant_id' => $tid,
            'name' => '2026 Code of Conduct Attestation',
            'description' => 'Annual attestation to the Code of Conduct and Ethics Policy.',
            'campaign_type' => 'attestation',
            'closes_at' => now()->addDays(14),
            'scope_definition' => [
                'attestable_type' => 'document',
                'attestable_id' => $policy->id,
                'assignments' => [
                    ['respondent_id' => $owner1->id],
                    ['respondent_id' => $owner2->id],
                    ['respondent_id' => $officer->id],
                ],
            ],
        ], $officer);

        $service->syncQuestions($campaign->activeQuestionnaire(), [
            ['question_text' => 'I have read and attest to the Code of Conduct.', 'response_type' => 'yes_no'],
        ]);

        $service->approveCampaign($campaign, $cfh);
        $service->open($campaign->fresh());

        // owner1 has already signed.
        app(AttestationService::class)->attest($campaign->fresh(), $owner1, $policy);
    }

    private function seedImprovements(int $tid, User $cfh, User $officer, User $owner1, ?Control $group): void
    {
        if (ImprovementAction::withoutGlobalScopes()->where('tenant_id', $tid)->exists()) {
            return;
        }

        $service = app(ImprovementService::class);

        $automate = $service->propose([
            'tenant_id' => $tid,
            'title' => 'Automate suspense account ageing report',
            'description' => 'Replace the manual weekly ageing spreadsheet with a scheduled core-banking extract.',
            'priority' => 'High',
            'source_type' => 'test',
            'owner_id' => $owner1->id,
            'due_at' => now()->addDays(60)->toDateString(),
            'benefit_description' => 'Removes a manual failure point and frees ~4 hours weekly.',
            'effort_estimate' => '5 person-days',
            'control_id' => $group?->id,
        ], $officer);
        $service->approve($automate, $cfh);
        $service->start($automate->fresh());

        $service->propose([
            'tenant_id' => $tid,
            'title' => 'Add dormant-account flag to teller screen',
            'description' => 'Surface the dormancy flag on the teller posting screen to prevent accidental reactivation.',
            'priority' => 'Medium',
            'source_type' => 'csa',
            'owner_id' => $owner1->id,
            'due_at' => now()->addDays(90)->toDateString(),
            'control_id' => $group?->id,
        ], $officer);

        $verified = $service->propose([
            'tenant_id' => $tid,
            'title' => 'Standardise branch till-proof checklist',
            'description' => 'One checklist across all branches, embedded in the closing routine.',
            'priority' => 'Low',
            'source_type' => 'spot_check',
            'owner_id' => $owner1->id,
        ], $officer);
        $service->approve($verified, $cfh);
        $service->start($verified->fresh());
        $service->markImplemented($verified->fresh());
        $service->verify($verified->fresh(), $cfh);
    }
}
