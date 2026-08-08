<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CsaService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CsaEngineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $owner;

    private Control $control;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-900',
            'title' => 'User access review',
            'type' => 'Detective',
            'nature' => 'Manual',
            'frequency' => 'Quarterly',
            'status' => 'Active',
            'owner_id' => $this->owner->id,
            'created_by' => $this->officer->id,
            'design_effectiveness' => 'Not Assessed',
        ]);

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * Campaign with a weighted questionnaire:
     *  Q1 yes_no (trigger on No, weight 1)
     *  Q2 yes_no shown only when Q1 = No
     *  Q3 scale_1_5 (weight 2)
     */
    private function makeOpenCampaign(array $campaignOverrides = []): array
    {
        $service = app(CsaService::class);

        $campaign = $service->createCampaign([
            'name' => 'Q3 2026 CSA',
            'campaign_type' => 'csa',
            'period_label' => 'Q3 2026',
            'scoring_method' => 'weighted',
            'pass_threshold' => 60,
            'scope_definition' => [
                'assignments' => [
                    ['respondent_id' => $this->owner->id, 'control_id' => $this->control->id],
                ],
            ],
            ...$campaignOverrides,
        ], $this->officer);

        $questionnaire = $campaign->activeQuestionnaire();

        $service->syncQuestions($questionnaire, [
            [
                'question_text' => 'Is the control operating as designed?',
                'response_type' => 'yes_no',
                'is_required' => true,
                'weight' => 1,
                'control_id' => $this->control->id,
                'triggers_exception_on' => ['in' => ['No'], 'severity' => 'High'],
            ],
            [
                'question_text' => 'Describe the failure mode.',
                'response_type' => 'free_text',
                'is_required' => true,
                'weight' => 0,
            ],
            [
                'question_text' => 'Rate the control maturity.',
                'response_type' => 'scale_1_5',
                'is_required' => true,
                'weight' => 2,
            ],
        ]);

        $questions = $questionnaire->questions()->get();

        // Q2 visible only when Q1 = No.
        $questions[1]->update(['condition' => ['question_id' => $questions[0]->id, 'in' => ['No']]]);

        $service->approveCampaign($campaign, $this->cfh);
        $service->open($campaign->fresh());

        return [$campaign->fresh(), $questionnaire, $questions];
    }

    // ── Maker-checker on campaigns ───────────────────────────────────

    public function test_creator_cannot_approve_their_own_campaign(): void
    {
        $campaign = app(CsaService::class)->createCampaign(
            ['name' => 'Self-approved?', 'campaign_type' => 'csa'], $this->officer,
        );

        $this->assertFalse($this->officer->can('approve', $campaign));

        $this->expectException(ValidationException::class);
        app(CsaService::class)->approveCampaign($campaign, $this->officer);
    }

    public function test_an_unapproved_campaign_cannot_open(): void
    {
        $campaign = app(CsaService::class)->createCampaign(
            ['name' => 'Unapproved', 'campaign_type' => 'csa'], $this->officer,
        );

        $this->expectException(ValidationException::class);
        app(CsaService::class)->open($campaign);
    }

    // ── Response generation ──────────────────────────────────────────

    public function test_opening_a_campaign_generates_scoped_responses(): void
    {
        [$campaign] = $this->makeOpenCampaign();

        $this->assertSame(1, $campaign->responses()->count());

        $response = $campaign->responses()->first();
        $this->assertSame($this->owner->id, $response->respondent_id);
        $this->assertSame($this->control->id, $response->control_id);
        $this->assertSame('Not Started', $response->status);
    }

    // ── Conditional logic ────────────────────────────────────────────

    public function test_hidden_conditional_question_is_not_required_and_not_stored(): void
    {
        [$campaign, , $questions] = $this->makeOpenCampaign();
        $response = $campaign->responses()->first();

        // Q1 = Yes → Q2 hidden; submitting without Q2 must pass.
        app(CsaService::class)->saveAnswers($response, [
            ['question_id' => $questions[0]->id, 'value' => 'Yes'],
            ['question_id' => $questions[1]->id, 'value' => 'should never persist'],
            ['question_id' => $questions[2]->id, 'value' => 5],
        ], $this->owner, submit: true);

        $response->refresh();
        $this->assertSame('Submitted', $response->status);
        $this->assertNull(
            $response->answers()->where('question_id', $questions[1]->id)->first(),
            'An answer to a hidden question must not be stored',
        );
    }

    public function test_visible_conditional_question_is_required(): void
    {
        [$campaign, , $questions] = $this->makeOpenCampaign();
        $response = $campaign->responses()->first();

        // Q1 = No → Q2 becomes visible and required.
        $this->expectException(ValidationException::class);

        app(CsaService::class)->saveAnswers($response, [
            ['question_id' => $questions[0]->id, 'value' => 'No'],
            ['question_id' => $questions[2]->id, 'value' => 3],
        ], $this->owner, submit: true);
    }

    // ── Exception triggers ───────────────────────────────────────────

    public function test_trigger_matching_answer_creates_exception_linked_to_control_and_campaign(): void
    {
        [$campaign, , $questions] = $this->makeOpenCampaign();
        $response = $campaign->responses()->first();

        app(CsaService::class)->saveAnswers($response, [
            ['question_id' => $questions[0]->id, 'value' => 'No'],
            ['question_id' => $questions[1]->id, 'value' => 'Reviews are not performed for contractors'],
            ['question_id' => $questions[2]->id, 'value' => 2],
        ], $this->owner, submit: true);

        $exception = ControlException::withoutGlobalScopes()
            ->where('source_type', 'CSA')
            ->where('source_id', $response->id)
            ->first();

        $this->assertNotNull($exception, 'A CSA-triggered exception must be raised');
        $this->assertSame($this->control->id, $exception->control_id);
        $this->assertSame('High', $exception->severity);
        $this->assertStringContainsString($campaign->reference, $exception->description);
    }

    public function test_non_matching_answer_creates_no_exception(): void
    {
        [$campaign, , $questions] = $this->makeOpenCampaign();
        $response = $campaign->responses()->first();

        app(CsaService::class)->saveAnswers($response, [
            ['question_id' => $questions[0]->id, 'value' => 'Yes'],
            ['question_id' => $questions[2]->id, 'value' => 5],
        ], $this->owner, submit: true);

        $this->assertSame(0, ControlException::withoutGlobalScopes()->where('source_type', 'CSA')->count());
    }

    // ── Scoring & proposed rating ────────────────────────────────────

    public function test_weighted_score_and_proposed_rating_are_computed_on_submit(): void
    {
        [$campaign, , $questions] = $this->makeOpenCampaign();
        $response = $campaign->responses()->first();

        // Q1 Yes (1.0 × w1) + Q3 5/5 (1.0 × w2) → 100%.
        app(CsaService::class)->saveAnswers($response, [
            ['question_id' => $questions[0]->id, 'value' => 'Yes'],
            ['question_id' => $questions[2]->id, 'value' => 5],
        ], $this->owner, submit: true);

        $response->refresh();
        $this->assertEquals(100.0, (float) $response->score);
        $this->assertSame('Adequate', $response->self_rating);
        $this->assertSame('Adequate', $response->proposed_design_rating);
    }

    public function test_csa_never_changes_the_control_rating_without_cfh_approval(): void
    {
        [$campaign, , $questions] = $this->makeOpenCampaign();
        $response = $campaign->responses()->first();

        app(CsaService::class)->saveAnswers($response, [
            ['question_id' => $questions[0]->id, 'value' => 'Yes'],
            ['question_id' => $questions[2]->id, 'value' => 5],
        ], $this->owner, submit: true);

        // Submitted + even reviewed: control rating still untouched.
        app(CsaService::class)->review($response->fresh(), $this->cfh, [
            'decision' => 'accept', 'reviewer_rating' => 'Adequate',
        ]);

        $this->assertSame('Not Assessed', $this->control->fresh()->design_effectiveness,
            'CSA must never auto-rate a control');

        // CFH applies the proposal — only now does the rating change.
        app(CsaService::class)->approveProposedRating($response->fresh(), $this->cfh);

        $this->assertSame('Adequate', $this->control->fresh()->design_effectiveness);
    }

    public function test_a_non_cfh_cannot_apply_a_proposed_rating(): void
    {
        [$campaign, , $questions] = $this->makeOpenCampaign();
        $response = $campaign->responses()->first();

        app(CsaService::class)->saveAnswers($response, [
            ['question_id' => $questions[0]->id, 'value' => 'Yes'],
            ['question_id' => $questions[2]->id, 'value' => 5],
        ], $this->owner, submit: true);

        app(CsaService::class)->review($response->fresh(), $this->cfh, [
            'decision' => 'accept', 'reviewer_rating' => 'Adequate',
        ]);

        $this->assertFalse($this->officer->can('approveRating', $response->fresh()));
        $this->assertFalse($this->owner->can('approveRating', $response->fresh()));

        $this->expectException(ValidationException::class);
        app(CsaService::class)->approveProposedRating($response->fresh(), $this->officer);
    }

    // ── Reviewer workflow ────────────────────────────────────────────

    public function test_respondent_cannot_review_their_own_response(): void
    {
        [$campaign, , $questions] = $this->makeOpenCampaign();
        $response = $campaign->responses()->first();

        app(CsaService::class)->saveAnswers($response, [
            ['question_id' => $questions[0]->id, 'value' => 'Yes'],
            ['question_id' => $questions[2]->id, 'value' => 4],
        ], $this->owner, submit: true);

        $this->assertFalse($this->owner->can('review', $response->fresh()));

        $this->expectException(ValidationException::class);
        app(CsaService::class)->review($response->fresh(), $this->owner, ['decision' => 'accept']);
    }

    public function test_rating_variance_beyond_threshold_is_flagged(): void
    {
        [$campaign, , $questions] = $this->makeOpenCampaign();
        $response = $campaign->responses()->first();

        app(CsaService::class)->saveAnswers($response, [
            ['question_id' => $questions[0]->id, 'value' => 'Yes'],
            ['question_id' => $questions[2]->id, 'value' => 5],
        ], $this->owner, submit: true);

        // Self says Adequate (2 on the ordinal scale); reviewer says
        // Inadequate (0). Gap 2 > threshold 1 → variance.
        $reviewed = app(CsaService::class)->review($response->fresh(), $this->cfh, [
            'decision' => 'accept', 'reviewer_rating' => 'Inadequate',
        ]);

        $this->assertTrue($reviewed->variance_flag);
    }
}
