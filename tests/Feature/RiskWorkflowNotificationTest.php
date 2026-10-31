<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\EffectivenessRating;
use App\Models\Risk;
use App\Models\RiskAppetite;
use App\Models\RiskCategory;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\User;
use App\Notifications\RiskAppetiteNotification;
use App\Notifications\RiskAssessmentNotification;
use App\Notifications\RiskTreatmentNotification;
use App\Services\AppetiteService;
use App\Services\RiskAssessmentService;
use App\Services\TreatmentService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RiskTaxonomySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Risk assessments, treatment plans and appetite statements reach the
 * person who has to act at each hand-off; a risk's owner and named
 * second-line reviewer can act on it; and a published control rating
 * re-scores a risk without trampling a manual residual.
 */
class RiskWorkflowNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $reviewer;

    private User $owner;

    private User $otherOwner;

    private Risk $risk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(NotificationEventSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->seed(RiskTaxonomySeeder::class);

        $this->cfh = $this->makeUser('Control Function Head');
        $this->officer = $this->makeUser('Control Officer');
        $this->reviewer = $this->makeUser('Control Officer');
        $this->owner = $this->makeUser('Control Owner');
        $this->otherOwner = $this->makeUser('Control Owner');

        $this->risk = Risk::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'RSK-900',
            'title' => 'Unauthorised payment release',
            'risk_category_id' => RiskCategory::withoutGlobalScopes()->where('code', 'OPS-FRD')->value('id'),
            'inherent_likelihood' => 4,
            'inherent_impact' => 4,
            'inherent_rating' => 16,
            'residual_rating' => 12,
            'residual_likelihood' => 3,
            'residual_impact' => 4,
            'current_rating_band' => 'Moderate',
            'risk_owner_id' => $this->owner->id,
            'second_line_reviewer_id' => $this->reviewer->id,
            'status' => 'active',
        ]);

        Notification::fake();
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function recordHighScore(?User $assessor = null): void
    {
        $this->actingAs($assessor ?? $this->officer)
            ->post(route('risks.assessments.store', $this->risk), [
                'assessment_type' => 'residual',
                'likelihood_level' => 4,
                'impact_level' => 4,
                'likelihood_rationale' => 'Two dual-release bypasses in the sample.',
                'likelihood_rationale_rich' => [
                    'time' => 1,
                    'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Two dual-release bypasses in the sample.']]],
                    'version' => '2.30.0',
                ],
            ])
            ->assertSessionHasNoErrors();
    }

    private function pending()
    {
        return $this->risk->assessments()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    // ── Who can reach and act on a risk ──────────────────────────────

    public function test_a_control_owner_can_open_the_risk_they_own_but_not_another(): void
    {
        $this->actingAs($this->owner)->get(route('risks.show', $this->risk))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Risks/Show')
                ->where('can.assess', true)
                ->where('can.map', false)
                ->where('can.register', false));

        $this->actingAs($this->otherOwner)->get(route('risks.show', $this->risk))->assertForbidden();
    }

    public function test_a_treatment_owner_can_open_and_progress_their_plan(): void
    {
        $treatment = app(TreatmentService::class)->propose($this->risk, [
            'strategy' => 'Reduce',
            'title' => 'Dual release above the limit',
            'owner_id' => $this->owner->id,
            'due_at' => now()->addMonth()->toDateString(),
        ], $this->officer);
        app(TreatmentService::class)->approve($treatment, $this->cfh);

        $this->actingAs($this->owner)->get(route('treatments.show', $treatment))->assertOk();
        $this->actingAs($this->owner)
            ->post(route('treatments.progress', $treatment), ['action' => 'start'])
            ->assertSessionHasNoErrors();

        $this->assertSame('In Progress', $treatment->fresh()->status);
    }

    public function test_the_named_second_line_reviewer_can_publish_and_another_officer_cannot(): void
    {
        $this->recordHighScore();
        $assessment = $this->pending();
        $this->assertSame('Pending Review', $assessment->status);

        $outsider = $this->makeUser('Control Officer');
        $this->actingAs($outsider)
            ->post(route('risks.assessments.publish', [$this->risk, $assessment]))
            ->assertForbidden();

        $this->actingAs($this->reviewer)
            ->post(route('risks.assessments.publish', [$this->risk, $assessment]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Published', $assessment->fresh()->status);
        $this->assertSame(16.0, (float) $this->risk->fresh()->residual_rating);
    }

    // ── Assessment hand-offs ─────────────────────────────────────────

    public function test_submitting_for_review_tells_the_reviewers_but_not_the_assessor(): void
    {
        $this->recordHighScore();

        Notification::assertSentTo([$this->cfh, $this->reviewer], RiskAssessmentNotification::class,
            fn (RiskAssessmentNotification $n) => $n->kind === RiskAssessmentNotification::SUBMITTED);
        Notification::assertNotSentTo([$this->officer, $this->owner], RiskAssessmentNotification::class);
    }

    public function test_the_reviewer_is_not_told_to_review_their_own_assessment(): void
    {
        $this->recordHighScore($this->reviewer);

        Notification::assertSentTo($this->cfh, RiskAssessmentNotification::class);
        Notification::assertNotSentTo($this->reviewer, RiskAssessmentNotification::class);
    }

    public function test_returning_keeps_the_reason_and_tells_the_assessor(): void
    {
        $this->recordHighScore();
        $assessment = $this->pending();

        $this->actingAs($this->cfh)
            ->post(route('risks.assessments.reject', [$this->risk, $assessment]), ['reason' => 'Only one bypass was confirmed.'])
            ->assertSessionHasNoErrors();

        $assessment->refresh();
        $this->assertSame('Rejected', $assessment->status);
        $this->assertSame('Only one bypass was confirmed.', $assessment->return_reason);
        $this->assertSame($this->cfh->id, $assessment->returned_by);
        $this->assertNull($assessment->approved_by);

        Notification::assertSentTo($this->officer, RiskAssessmentNotification::class,
            fn (RiskAssessmentNotification $n) => $n->kind === RiskAssessmentNotification::RETURNED
                && $n->note === 'Only one bypass was confirmed.');
    }

    public function test_the_assessor_cannot_return_their_own_assessment(): void
    {
        $this->recordHighScore($this->cfh);
        $assessment = $this->pending();

        $this->actingAs($this->cfh)
            ->post(route('risks.assessments.reject', [$this->risk, $assessment]), ['reason' => 'Changed my mind.'])
            ->assertSessionHasErrors('approval');

        $this->assertSame('Pending Review', $assessment->fresh()->status);
    }

    public function test_publishing_tells_the_assessor_and_the_risk_owner(): void
    {
        $this->recordHighScore();
        $assessment = $this->pending();

        $this->actingAs($this->cfh)->post(route('risks.assessments.publish', [$this->risk, $assessment]));

        Notification::assertSentTo([$this->officer, $this->owner], RiskAssessmentNotification::class,
            fn (RiskAssessmentNotification $n) => $n->kind === RiskAssessmentNotification::PUBLISHED);
        Notification::assertNotSentTo($this->cfh, RiskAssessmentNotification::class,
            fn (RiskAssessmentNotification $n) => $n->kind === RiskAssessmentNotification::PUBLISHED);
    }

    public function test_the_rich_text_rationale_is_kept(): void
    {
        $this->recordHighScore();

        $this->assertSame(
            'Two dual-release bypasses in the sample.',
            $this->pending()->likelihood_rationale_rich['blocks'][0]['data']['text'] ?? null,
        );
    }

    // ── Residual recomputation ───────────────────────────────────────

    private function ratedControl(string $rating): Control
    {
        $control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-900',
            'title' => 'Maker-checker on transfers',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
            'owner_id' => $this->owner->id,
        ]);

        $instance = TestInstance::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $control->id,
            'reference' => 'TST-2026-900',
            'period_label' => '2026-Q4',
            'period_start' => now()->startOfQuarter()->toDateString(),
            'period_end' => now()->endOfQuarter()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
            'status' => 'Reviewed',
        ]);

        EffectivenessRating::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $control->id,
            'test_instance_id' => $instance->id,
            'period_label' => '2026-Q4',
            'design_effectiveness' => $rating,
            'operating_effectiveness' => $rating,
            'overall_rating' => $rating,
            'status' => 'Published',
            'rated_by' => $this->officer->id,
            'approved_by' => $this->cfh->id,
            'approved_at' => now(),
        ]);

        $this->risk->controls()->attach($control->id, ['contribution_weight' => 1, 'mapped_at' => now()]);

        return $control;
    }

    public function test_a_control_rating_re_scores_the_risk_grid_cell_and_band(): void
    {
        $control = $this->ratedControl('Effective');

        app(RiskAssessmentService::class)->recomputeForControl($control);

        $risk = $this->risk->fresh();
        $this->assertSame(8.0, (float) $risk->residual_rating);
        $this->assertSame(2, $risk->residual_likelihood);
        $this->assertSame(
            app(RiskAssessmentService::class)->bandFor(8, $this->tenant->id),
            $risk->current_rating_band,
        );
    }

    public function test_a_control_rating_does_not_overwrite_a_published_manual_residual(): void
    {
        $this->recordHighScore();
        $this->actingAs($this->cfh)->post(route('risks.assessments.publish', [$this->risk, $this->pending()]));
        $control = $this->ratedControl('Effective');

        app(RiskAssessmentService::class)->recomputeForControl($control);

        $risk = $this->risk->fresh();
        $this->assertSame(16.0, (float) $risk->residual_rating);
        $this->assertSame(4, $risk->residual_likelihood);
    }

    // ── Appetite ─────────────────────────────────────────────────────

    private function appetite(?User $author = null): RiskAppetite
    {
        return app(AppetiteService::class)->create([
            'tenant_id' => $this->tenant->id,
            'risk_category_id' => RiskCategory::withoutGlobalScopes()->where('code', 'OPS')->value('id'),
            'statement' => 'We accept a low level of operational risk.',
            'appetite_level' => 'Cautious',
            'tolerance_lower' => 4,
            'tolerance_upper' => 12,
            'capacity' => 18,
        ], $author ?? $this->officer);
    }

    public function test_an_appetite_breach_tells_the_risk_owner(): void
    {
        $exec = $this->makeUser('Executive Viewer');
        $appetite = $this->appetite();
        app(AppetiteService::class)->submitForApproval($appetite, $this->officer);
        app(AppetiteService::class)->approve($appetite->fresh(), $exec);

        $this->recordHighScore();
        $this->actingAs($this->cfh)->post(route('risks.assessments.publish', [$this->risk, $this->pending()]));

        $this->assertTrue($this->risk->fresh()->appetite_breached);
        Notification::assertSentTo($this->owner, RiskAppetiteNotification::class,
            fn (RiskAppetiteNotification $n) => $n->kind === RiskAppetiteNotification::BREACHED
                && $n->risk->is($this->risk));
    }

    public function test_an_appetite_statement_reaches_its_approvers_and_back_to_its_author(): void
    {
        $exec = $this->makeUser('Executive Viewer');
        $admin = $this->makeUser('System Administrator');
        $appetite = $this->appetite($admin);

        $this->actingAs($admin)->post(route('appetite.submit', $appetite))->assertRedirect();
        $this->assertSame('Pending Approval', $appetite->fresh()->status);

        Notification::assertSentTo([$this->cfh, $exec], RiskAppetiteNotification::class,
            fn (RiskAppetiteNotification $n) => $n->kind === RiskAppetiteNotification::SUBMITTED);
        Notification::assertNotSentTo($admin, RiskAppetiteNotification::class);

        app(AppetiteService::class)->approve($appetite->fresh(), $exec);

        Notification::assertSentTo($admin, RiskAppetiteNotification::class,
            fn (RiskAppetiteNotification $n) => $n->kind === RiskAppetiteNotification::APPROVED);
    }

    // ── Treatment plans ──────────────────────────────────────────────

    public function test_a_treatment_plan_reaches_the_right_person_at_each_hand_off(): void
    {
        $service = app(TreatmentService::class);

        $treatment = $service->propose($this->risk, [
            'strategy' => 'Reduce',
            'title' => 'Dual release above the limit',
            'owner_id' => $this->owner->id,
            'due_at' => now()->addMonth()->toDateString(),
        ], $this->officer);

        Notification::assertSentTo($this->cfh, RiskTreatmentNotification::class,
            fn (RiskTreatmentNotification $n) => $n->kind === RiskTreatmentNotification::PROPOSED);
        Notification::assertNotSentTo([$this->owner, $this->officer], RiskTreatmentNotification::class);

        $service->approve($treatment, $this->cfh);
        Notification::assertSentTo($this->owner, RiskTreatmentNotification::class,
            fn (RiskTreatmentNotification $n) => $n->kind === RiskTreatmentNotification::APPROVED);

        $service->start($treatment->fresh());
        $service->markImplemented($treatment->fresh(), $this->owner);
        Notification::assertSentTo($this->cfh, RiskTreatmentNotification::class,
            fn (RiskTreatmentNotification $n) => $n->kind === RiskTreatmentNotification::IMPLEMENTED);

        $service->verify($treatment->fresh(), $this->cfh);
        Notification::assertSentTo($this->owner, RiskTreatmentNotification::class,
            fn (RiskTreatmentNotification $n) => $n->kind === RiskTreatmentNotification::VERIFIED);
    }

    public function test_a_risk_acceptance_goes_only_to_the_control_function_heads(): void
    {
        $admin = $this->makeUser('System Administrator');

        app(TreatmentService::class)->propose($this->risk, [
            'strategy' => 'Accept',
            'title' => 'Accept the residual exposure',
            'owner_id' => $this->owner->id,
            'due_at' => now()->addMonth()->toDateString(),
        ], $this->officer);

        Notification::assertSentTo($this->cfh, RiskTreatmentNotification::class);
        Notification::assertNotSentTo($admin, RiskTreatmentNotification::class);
    }

    public function test_cancelling_a_plan_tells_its_owner_with_the_reason(): void
    {
        $treatment = app(TreatmentService::class)->propose($this->risk, [
            'strategy' => 'Reduce',
            'title' => 'Dual release above the limit',
            'owner_id' => $this->owner->id,
            'due_at' => now()->addMonth()->toDateString(),
        ], $this->officer);

        app(TreatmentService::class)->cancel($treatment, $this->cfh, 'Superseded by the payments re-platform.');

        Notification::assertSentTo($this->owner, RiskTreatmentNotification::class,
            fn (RiskTreatmentNotification $n) => $n->kind === RiskTreatmentNotification::CANCELLED
                && $n->note === 'Superseded by the payments re-platform.');
    }

    public function test_every_new_event_key_is_seeded(): void
    {
        foreach ([
            'risk.assessment.review.requested', 'risk.assessment.returned', 'risk.assessment.published',
            'risk.appetite.breached', 'risk.appetite.approval.requested', 'risk.appetite.approved',
            'treatment.approval.requested', 'treatment.approved', 'treatment.verification.requested',
            'treatment.verified', 'treatment.cancelled',
        ] as $key) {
            $this->assertDatabaseHas('notification_events', ['key' => $key]);
        }
    }
}
