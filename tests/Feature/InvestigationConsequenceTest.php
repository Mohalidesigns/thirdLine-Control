<?php

namespace Tests\Feature;

use App\Models\ConsequenceAction;
use App\Models\ImprovementAction;
use App\Models\Investigation;
use App\Models\InvestigationSubject;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ConsequenceService;
use App\Services\InvestigationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Consequence management (§E.2, §F.1).
 *
 * This is the half of the module a disciplinary appeal reads: who
 * recommended what against whom, who agreed, on what date, and — if it was
 * refused — why.
 */
class InvestigationConsequenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $head;

    private User $officer;

    private Investigation $investigation;

    private InvestigationSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->head = $this->makeUser('head@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');

        $this->actingAs($this->officer);

        $this->investigation = app(InvestigationService::class)->open([
            'title' => 'Unauthorised waiver of transfer charges',
            'category' => 'staff_misconduct',
            'source' => 'control_exception',
            'priority' => 'High',
        ], $this->officer);

        $this->subject = app(InvestigationService::class)->addSubject($this->investigation, [
            'subject_type' => 'staff',
            'name' => 'B. Officer',
            'role_in_case' => 'primary_subject',
        ], $this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function consequences(): ConsequenceService
    {
        return app(ConsequenceService::class);
    }

    private function recommend(array $overrides = []): ConsequenceAction
    {
        return $this->consequences()->recommend($this->investigation, [
            'action_type' => 'warning_letter',
            'investigation_subject_id' => $this->subject->id,
            'description' => 'Written warning for waiving charges without authority.',
            ...$overrides,
        ], $this->officer);
    }

    // ── The happy path ───────────────────────────────────────────────

    public function test_recommend_approve_implement(): void
    {
        $action = $this->recommend();

        $this->assertSame('CON-'.now()->year.'-001', $action->reference);
        $this->assertSame('recommended', $action->status);

        $action = $this->consequences()->approve($action, $this->head);
        $this->assertSame('approved', $action->status);
        $this->assertSame($this->head->id, $action->approved_by);

        $action = $this->consequences()->markInProgress($action, $this->officer);
        $this->assertSame('in_progress', $action->status);

        $action = $this->consequences()->implement($action, $this->officer, [
            'implementation_note' => 'Letter issued and acknowledged.',
        ]);
        $this->assertSame('implemented', $action->status);
    }

    public function test_the_diary_records_each_step(): void
    {
        $action = $this->recommend();
        $this->consequences()->approve($action, $this->head);

        $this->assertSame(
            2,
            $this->investigation->activities()->where('activity_type', 'action_recommended')->count(),
            'Recommending and approving are both events on the chronology.',
        );
    }

    // ── Rejection ────────────────────────────────────────────────────

    public function test_rejection_requires_a_reason(): void
    {
        $action = $this->recommend();

        $this->expectException(ValidationException::class);
        $this->consequences()->reject($action, $this->head, '   ');
    }

    public function test_a_rejection_keeps_its_reason_on_the_record(): void
    {
        $action = $this->recommend();

        $action = $this->consequences()->reject($action, $this->head, 'The waiver was within the branch manager\'s discretion.');

        $this->assertSame('rejected', $action->status);
        $this->assertStringContainsString('discretion', $action->rejection_reason);
    }

    // ── Naming the person ────────────────────────────────────────────

    public function test_an_action_bearing_on_employment_must_name_a_subject(): void
    {
        try {
            $this->consequences()->recommend($this->investigation, ['action_type' => 'dismissal'], $this->officer);
            $this->fail('A dismissal with nobody attached to it is not a record of anything.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must name the subject', $e->getMessage());
        }
    }

    public function test_a_process_change_needs_no_subject(): void
    {
        $action = $this->consequences()->recommend($this->investigation, [
            'action_type' => 'process_change',
            'description' => 'Require a second signature on charge waivers above ₦50,000.',
        ], $this->officer);

        $this->assertSame('recommended', $action->status);
        $this->assertNull($action->investigation_subject_id);
    }

    public function test_a_subject_from_another_investigation_is_refused(): void
    {
        $other = app(InvestigationService::class)->open([
            'title' => 'Unrelated matter', 'category' => 'other', 'source' => 'other', 'priority' => 'Low',
        ], $this->officer);

        $otherSubject = app(InvestigationService::class)->addSubject($other, [
            'subject_type' => 'staff', 'name' => 'C. Person', 'role_in_case' => 'witness',
        ], $this->officer);

        $this->expectException(ValidationException::class);
        $this->recommend(['investigation_subject_id' => $otherSubject->id]);
    }

    // ── Recovery roll-up ─────────────────────────────────────────────

    public function test_amount_recovered_rolls_up_to_the_investigation(): void
    {
        $first = $this->recommend(['action_type' => 'restitution_recovery']);
        $second = $this->recommend(['action_type' => 'restitution_recovery']);

        $this->consequences()->implement($this->consequences()->approve($first, $this->head), $this->officer, ['amount_recovered' => 250000]);
        $this->consequences()->implement($this->consequences()->approve($second, $this->head), $this->officer, ['amount_recovered' => 125500.50]);

        $this->assertEquals(
            375500.50,
            (float) $this->investigation->refresh()->amount_recovered,
            'The total is derived from what was actually recovered, never typed by hand.',
        );
    }

    public function test_a_recommended_but_unimplemented_recovery_does_not_count(): void
    {
        $action = $this->recommend(['action_type' => 'restitution_recovery']);
        $this->consequences()->approve($action, $this->head);

        $this->assertNull($this->investigation->refresh()->amount_recovered);
    }

    // ── §F.1 — the loop into remediation ─────────────────────────────

    public function test_approving_a_process_change_spawns_a_back_linked_improvement_action(): void
    {
        $action = $this->consequences()->recommend($this->investigation, [
            'action_type' => 'process_change',
            'description' => 'Second signature on charge waivers above ₦50,000.',
            'due_date' => now()->addMonth()->toDateString(),
        ], $this->officer);

        $action = $this->consequences()->approve($action, $this->head);

        $this->assertNotNull($action->improvement_action_id);

        $improvement = ImprovementAction::find($action->improvement_action_id);

        $this->assertSame('investigation', $improvement->source_type);
        $this->assertSame($this->investigation->id, $improvement->source_id);
        $this->assertSame('Proposed', $improvement->status);
    }

    public function test_a_finding_recommendation_becomes_tracked_work(): void
    {
        $finding = app(InvestigationService::class)->addFinding($this->investigation, [
            'title' => 'Charge waiver authority is undocumented',
            'severity' => 'High',
            'recommendation' => 'Publish a waiver authority matrix and train the branch network.',
        ], $this->officer);

        $improvement = $this->consequences()->raiseImprovementFromFinding($finding, [
            'owner_id' => $this->head->id,
            'due_at' => now()->addMonths(2)->toDateString(),
        ], $this->officer);

        $this->assertSame('investigation', $improvement->source_type);
        $this->assertSame($finding->id, $improvement->source_id);
        $this->assertSame('High', $improvement->priority, 'A High finding does not become a Low action.');
        $this->assertSame($improvement->id, $finding->refresh()->improvement_action_id, 'The link is written in both directions.');
    }

    public function test_a_finding_cannot_raise_two_improvement_actions(): void
    {
        $finding = app(InvestigationService::class)->addFinding($this->investigation, [
            'title' => 'Waiver authority undocumented', 'severity' => 'High',
        ], $this->officer);

        $this->consequences()->raiseImprovementFromFinding($finding, [], $this->officer);

        $this->expectException(ValidationException::class);
        $this->consequences()->raiseImprovementFromFinding($finding->refresh(), [], $this->officer);
    }

    // ── The transition map ───────────────────────────────────────────

    public function test_an_unapproved_consequence_cannot_be_implemented(): void
    {
        $action = $this->recommend();

        $this->expectException(ValidationException::class);
        $this->consequences()->implement($action, $this->officer, []);
    }

    public function test_an_implemented_consequence_is_final(): void
    {
        $action = $this->consequences()->implement(
            $this->consequences()->approve($this->recommend(), $this->head),
            $this->officer,
            [],
        );

        $this->expectException(ValidationException::class);
        $this->consequences()->reject($action, $this->head, 'Changed my mind.');
    }
}
