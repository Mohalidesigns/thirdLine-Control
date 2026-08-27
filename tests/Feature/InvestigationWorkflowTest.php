<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\Investigation;
use App\Models\InvestigationActivity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvestigationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The workflow guarantees CR-04 exists to keep.
 *
 * The narrow transition map is the point of this module: an investigation
 * that can be marked finished without a rating, or closed while a named
 * person's position is unresolved, is worse than no investigation record
 * at all — it is a record that says a decision was taken when none was.
 */
class InvestigationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $head;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->head = $this->makeUser('head@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function service(): InvestigationService
    {
        return app(InvestigationService::class);
    }

    private function open(array $overrides = [], ?User $actor = null): Investigation
    {
        $actor ??= $this->officer;
        $this->actingAs($actor);

        return $this->service()->open([
            'title' => 'Suspected teller cash suppression, Branch 042',
            'category' => 'fraud',
            'source' => 'management_directive',
            'priority' => 'High',
            ...$overrides,
        ], $actor);
    }

    /** Drive a case to the point where it can be completed. */
    private function toPendingReview(Investigation $investigation): Investigation
    {
        $this->actingAs($this->officer);
        $investigation = $this->service()->transition($investigation, 'reported', $this->officer);
        $investigation = $this->service()->transition($investigation, 'under_investigation', $this->officer);

        return $this->service()->transition($investigation, 'pending_review', $this->officer);
    }

    /**
     * Spec §7.5 — completion now requires a finding and a conclusion, so
     * every test that only wanted "a completed case" has to supply the
     * substance one is made of. Kept in one helper so the gate can tighten
     * again without editing a dozen call sites.
     */
    private function completeWith(Investigation $investigation, string $rating): Investigation
    {
        if ($investigation->findings()->count() === 0) {
            $this->service()->addFinding($investigation, [
                'title' => 'Cash lodgements were not posted on the day they were received',
                'severity' => 'Moderate',
            ], $this->officer);
        }

        return $this->service()->complete($investigation, $this->officer, [
            'risk_rating' => $rating,
            'conclusion' => 'The suppression is established and the loss is quantified.',
        ]);
    }

    // ── Reference, defaults and the diary ────────────────────────────

    public function test_opening_an_investigation_stamps_the_house_reference_and_seeds_the_lead(): void
    {
        $investigation = $this->open();

        $this->assertSame('INV-'.now()->year.'-001', $investigation->reference);
        $this->assertSame('draft', $investigation->status);
        $this->assertSame($this->officer->id, $investigation->lead_investigator_id);
        $this->assertTrue(
            $investigation->teamMembers()->where('user_id', $this->officer->id)->where('role', 'lead')->exists(),
            'The lead investigator is on the team by definition, not by someone remembering to add them.',
        );
    }

    public function test_the_person_who_opens_a_case_is_on_its_team_even_when_someone_else_leads(): void
    {
        $investigation = $this->open(['lead_investigator_id' => $this->head->id]);

        $this->assertTrue(
            $investigation->teamMembers()->where('user_id', $this->officer->id)->exists(),
            'An officer who opens a confidential case and names another lead must not lose sight of it on save.',
        );
    }

    public function test_every_transition_writes_a_diary_row(): void
    {
        $investigation = $this->open();

        $this->assertSame(1, $investigation->activities()->where('activity_type', 'case_created')->count());

        $this->actingAs($this->officer);
        $this->service()->transition($investigation, 'reported', $this->officer);

        $this->assertSame(
            1,
            $investigation->activities()->where('activity_type', 'status_changed')->count(),
            'The chronology is a by-product of the workflow, not an extra step someone forgets.',
        );
    }

    // ── The transition map ───────────────────────────────────────────

    public function test_every_legal_transition_is_accepted(): void
    {
        $investigation = $this->open();
        $this->actingAs($this->officer);

        foreach (['reported', 'under_investigation', 'pending_review'] as $status) {
            $investigation = $this->service()->transition($investigation, $status, $this->officer);
            $this->assertSame($status, $investigation->status);
        }

        $investigation = $this->service()->transition($investigation, 'suspended', $this->officer);
        $this->assertSame('suspended', $investigation->status);

        $investigation = $this->service()->transition($investigation, 'under_investigation', $this->officer);
        $this->assertSame('under_investigation', $investigation->status);
    }

    public function test_an_illegal_transition_is_rejected(): void
    {
        $investigation = $this->open();
        $this->actingAs($this->officer);

        $this->expectException(ValidationException::class);
        $this->service()->transition($investigation, 'closed', $this->officer);
    }

    public function test_completed_is_unreachable_through_transition(): void
    {
        $investigation = $this->toPendingReview($this->open());

        try {
            $this->service()->transition($investigation, 'completed', $this->officer);
            $this->fail('transition() must refuse "completed" outright — completion carries obligations it cannot check.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('completion form', $e->getMessage());
        }

        $this->assertSame('pending_review', $investigation->refresh()->status);
    }

    public function test_commencing_stamps_the_commenced_date_once(): void
    {
        $investigation = $this->open();
        $this->actingAs($this->officer);

        $investigation = $this->service()->transition($investigation, 'reported', $this->officer);
        $investigation = $this->service()->transition($investigation, 'under_investigation', $this->officer);

        $commenced = $investigation->commenced_date;
        $this->assertNotNull($commenced, 'The clock starts when work starts.');

        $investigation = $this->service()->transition($investigation, 'suspended', $this->officer);
        $investigation = $this->service()->transition($investigation, 'under_investigation', $this->officer);

        $this->assertEquals(
            $commenced->toDateString(),
            $investigation->commenced_date->toDateString(),
            'Resuming a suspended case does not restart its clock.',
        );
    }

    // ── Completion ───────────────────────────────────────────────────

    public function test_completion_is_blocked_without_a_risk_rating(): void
    {
        $investigation = $this->toPendingReview($this->open());

        $this->expectException(ValidationException::class);
        $this->service()->complete($investigation, $this->officer, ['risk_rating' => null]);
    }

    public function test_completion_is_blocked_while_a_named_subject_is_unresolved(): void
    {
        $investigation = $this->toPendingReview($this->open());

        $this->service()->addSubject($investigation, [
            'subject_type' => 'staff',
            'name' => 'A. Teller',
            'role_in_case' => 'primary_subject',
        ], $this->officer);

        try {
            $this->service()->complete($investigation, $this->officer, ['risk_rating' => 'High']);
            $this->fail('An investigation must not close while a named person\'s position is unresolved.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('pending outcome', $e->getMessage());
        }

        $this->assertSame('pending_review', $investigation->refresh()->status);
    }

    public function test_completion_succeeds_once_every_subject_has_a_resolved_outcome(): void
    {
        $investigation = $this->toPendingReview($this->open());

        $subject = $this->service()->addSubject($investigation, [
            'subject_type' => 'staff',
            'name' => 'A. Teller',
            'role_in_case' => 'primary_subject',
        ], $this->officer);

        $this->service()->recordSubjectOutcome($subject, 'culpable', 'Admitted the suppression in interview.', $this->officer);

        $investigation = $this->completeWith($investigation, 'High');

        $this->assertSame('completed', $investigation->status);
        $this->assertSame('High', $investigation->risk_rating);
        $this->assertNotNull($investigation->completed_date);
        $this->assertTrue($investigation->activities()->where('activity_type', 'case_completed')->exists());
    }

    public function test_an_outcome_against_a_named_person_requires_a_rationale(): void
    {
        $investigation = $this->open();

        $subject = $this->service()->addSubject($investigation, [
            'subject_type' => 'staff',
            'name' => 'A. Teller',
            'role_in_case' => 'primary_subject',
        ], $this->officer);

        $this->expectException(ValidationException::class);
        $this->service()->recordSubjectOutcome($subject, 'culpable', '   ', $this->officer);
    }

    // ── Closure ──────────────────────────────────────────────────────

    public function test_closure_is_blocked_while_a_high_finding_has_no_improvement_action(): void
    {
        $investigation = $this->completedCase();

        $this->service()->addFinding($investigation, [
            'title' => 'Dual control over the vault was not operating',
            'severity' => 'High',
        ], $this->officer);

        try {
            $this->service()->close($investigation, $this->officer);
            $this->fail('CR-01 applies the same rule to exception closure — a High finding needs tracked remediation.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no improvement action', $e->getMessage());
        }
    }

    public function test_closure_succeeds_when_findings_are_low_severity(): void
    {
        $investigation = $this->completedCase();

        $this->service()->addFinding($investigation, [
            'title' => 'Register annotated in pencil',
            'severity' => 'Low',
        ], $this->officer);

        $investigation = $this->service()->close($investigation, $this->officer);

        $this->assertSame('closed', $investigation->status);
        $this->assertNotNull($investigation->closed_date);
    }

    // ── Archive ──────────────────────────────────────────────────────

    public function test_archiving_requires_a_completed_or_closed_case(): void
    {
        $investigation = $this->open();

        $this->expectException(ValidationException::class);
        $this->service()->archive($investigation, $this->head, 'Duplicate of INV-2026-004.');
    }

    public function test_archiving_requires_a_reason(): void
    {
        $investigation = $this->completedCase();

        $this->expectException(ValidationException::class);
        $this->service()->archive($investigation, $this->head, '   ');
    }

    public function test_an_archived_case_drops_out_of_the_active_register_and_refuses_changes(): void
    {
        $investigation = $this->completedCase();

        $this->service()->archive($investigation, $this->head, 'Superseded by the group investigation.');

        $this->assertSame(0, Investigation::query()->active()->count());
        $this->assertSame(1, Investigation::query()->count());

        $this->expectException(ValidationException::class);
        $this->service()->addFinding($investigation->refresh(), ['title' => 'Late finding', 'severity' => 'Low'], $this->officer);
    }

    public function test_unarchiving_restores_the_case_to_the_register(): void
    {
        $investigation = $this->completedCase();
        $this->service()->archive($investigation, $this->head, 'Filed in error by the duty officer.');

        $this->service()->unarchive($investigation->refresh(), $this->head);

        $this->assertSame(1, Investigation::query()->active()->count());
    }

    // ── The manual / system diary split ──────────────────────────────

    public function test_a_human_may_not_forge_a_system_diary_entry(): void
    {
        $investigation = $this->open();

        $this->expectException(ValidationException::class);
        $this->service()->recordActivity($investigation, [
            'activity_type' => 'case_completed',
            'title' => 'Completed (not really)',
        ], $this->officer);
    }

    public function test_the_six_manual_types_are_accepted(): void
    {
        $investigation = $this->open();

        foreach (InvestigationActivity::MANUAL_TYPES as $type) {
            $activity = $this->service()->recordActivity($investigation, [
                'activity_type' => $type,
                'title' => 'Logged '.$type,
            ], $this->officer);

            $this->assertSame($type, $activity->activity_type);
        }
    }

    // ── Findings and the control link ────────────────────────────────

    public function test_a_finding_names_the_control_that_failed(): void
    {
        $investigation = $this->open();

        $control = Control::create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-042',
            'title' => 'Dual control over vault access',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Daily',
            'status' => 'Active',
        ]);

        $finding = $this->service()->addFinding($investigation, [
            'title' => 'Dual control not operating',
            'severity' => 'Critical',
            'control_id' => $control->id,
        ], $this->officer);

        $this->assertSame('INVF-'.now()->year.'-001', $finding->reference);
        $this->assertSame($control->id, $finding->control_id);
        $this->assertTrue($investigation->activities()->where('activity_type', 'finding_added')->exists());
    }

    private function completedCase(): Investigation
    {
        $investigation = $this->toPendingReview($this->open());

        return $this->completeWith($investigation, 'Moderate');
    }
}
