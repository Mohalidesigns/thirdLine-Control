<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlUnit;
use App\Models\Investigation;
use App\Models\SodViolation;
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
 * Three segregation-of-duties rules the source module does not make, and
 * internal control cannot do without (§D.4).
 *
 * Two are hard blocks and one is deliberately not: in a four-person branch
 * the officer who owns the control sometimes has to be the one who looks
 * into it. What is unacceptable is that being invisible.
 */
class InvestigationSodTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $head;

    private User $officer;

    private User $branchOfficer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->head = $this->makeUser('head@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->branchOfficer = $this->makeUser('branch@test.local', 'Control Officer');
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

    private function open(?User $lead = null): Investigation
    {
        $this->actingAs($this->officer);

        return $this->service()->open([
            'title' => 'Vault cash difference at Branch 042',
            'category' => 'asset_misappropriation',
            'source' => 'control_exception',
            'priority' => 'High',
            'lead_investigator_id' => ($lead ?? $this->officer)->id,
        ], $this->officer);
    }

    // ── Rule 1: a subject may never be on the team ───────────────────

    public function test_a_named_subject_cannot_be_assigned_to_the_team(): void
    {
        $investigation = $this->open();

        $this->service()->addSubject($investigation, [
            'subject_type' => 'staff',
            'name' => $this->branchOfficer->name,
            'user_id' => $this->branchOfficer->id,
            'role_in_case' => 'primary_subject',
        ], $this->officer);

        try {
            $this->service()->assignTeamMember($investigation, $this->branchOfficer, 'investigator', $this->officer);
            $this->fail('The person under investigation cannot be one of the investigators.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be assigned to its team', $e->getMessage());
        }

        $this->assertFalse($investigation->teamMembers()->where('user_id', $this->branchOfficer->id)->exists());
    }

    public function test_a_team_member_cannot_be_named_as_a_subject(): void
    {
        $investigation = $this->open();

        $this->service()->assignTeamMember($investigation, $this->branchOfficer, 'investigator', $this->officer);

        try {
            $this->service()->addSubject($investigation, [
                'subject_type' => 'staff',
                'name' => $this->branchOfficer->name,
                'user_id' => $this->branchOfficer->id,
                'role_in_case' => 'primary_subject',
            ], $this->officer);
            $this->fail('The conflict can arrive from either direction and must be refused from both.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot also be named as a subject', $e->getMessage());
        }

        $this->assertSame(0, $investigation->subjects()->count());
    }

    // ── Rule 2: the recommender never approves ───────────────────────

    public function test_the_recommender_cannot_approve_their_own_consequence(): void
    {
        $investigation = $this->open();

        $subject = $this->service()->addSubject($investigation, [
            'subject_type' => 'staff',
            'name' => 'A. Teller',
            'role_in_case' => 'primary_subject',
        ], $this->officer);

        $action = app(ConsequenceService::class)->recommend($investigation, [
            'action_type' => 'warning_letter',
            'investigation_subject_id' => $subject->id,
        ], $this->officer);

        try {
            app(ConsequenceService::class)->approve($action, $this->officer);
            $this->fail('It is the same hand twice, whatever permissions the officer holds.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be approved by the person who recommended it', $e->getMessage());
        }

        $this->assertSame('recommended', $action->refresh()->status);

        // A second person can.
        $approved = app(ConsequenceService::class)->approve($action, $this->head);
        $this->assertSame('approved', $approved->status);
    }

    // ── Rule 3: control owner as lead — flagged, not blocked ─────────

    public function test_the_officer_who_owns_the_failed_control_is_flagged_as_lead_not_blocked(): void
    {
        $unit = ControlUnit::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'ICU',
            'name' => 'Internal Control Unit',
            'domain' => 'branch',
        ]);

        $entity = ControlEntity::create([
            'tenant_id' => $this->tenant->id,
            'control_unit_id' => $unit->id,
            'reference' => 'CE-001',
            'name' => 'Branch 042',
            'entity_kind' => 'branch',
            'default_officer_id' => $this->branchOfficer->id,
        ]);

        $control = Control::create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-042',
            'title' => 'Daily vault count',
            'type' => 'Detective',
            'nature' => 'Manual',
            'frequency' => 'Daily',
            'status' => 'Active',
            'control_entity_id' => $entity->id,
        ]);

        // The branch officer both performs the control and leads the case.
        $investigation = $this->open($this->branchOfficer);

        $this->service()->addFinding($investigation, [
            'title' => 'The daily vault count was not performed for eleven days',
            'severity' => 'High',
            'control_id' => $control->id,
        ], $this->branchOfficer);

        $investigation->refresh();

        $this->assertTrue(
            $investigation->has_sod_conflict,
            'A conflict the organisation decided to live with still has to be visible.',
        );
        $this->assertStringContainsString('CTL-042', $investigation->sod_conflict_note);
        $this->assertStringContainsString($this->branchOfficer->name, $investigation->sod_conflict_note);

        // Flagged, never blocked.
        $this->assertSame('draft', $investigation->status);
        $this->assertSame(1, $investigation->findings()->count());
    }

    public function test_the_flag_does_not_borrow_the_entitlement_shaped_sod_tables(): void
    {
        $investigation = $this->open();

        $this->service()->addFinding($investigation, [
            'title' => 'Reconciliation not evidenced',
            'severity' => 'Moderate',
        ], $this->officer);

        $this->assertSame(
            0,
            SodViolation::query()->count(),
            'sod_violations.subject_identifier is a source-system staff id, its rule_id is not nullable and its '
            .'unique key would stop the same officer being flagged on two investigations — the flag belongs on the case.',
        );
    }

    public function test_the_flag_clears_when_the_conflict_goes_away(): void
    {
        $unit = ControlUnit::create([
            'tenant_id' => $this->tenant->id, 'code' => 'ICU2', 'name' => 'ICU', 'domain' => 'branch',
        ]);

        $entity = ControlEntity::create([
            'tenant_id' => $this->tenant->id, 'control_unit_id' => $unit->id, 'reference' => 'CE-002',
            'name' => 'Branch 043', 'entity_kind' => 'branch', 'default_officer_id' => $this->branchOfficer->id,
        ]);

        $control = Control::create([
            'tenant_id' => $this->tenant->id, 'control_ref' => 'CTL-043', 'title' => 'Vault count',
            'type' => 'Detective', 'nature' => 'Manual', 'frequency' => 'Daily', 'status' => 'Active',
            'control_entity_id' => $entity->id,
        ]);

        $investigation = $this->open($this->branchOfficer);
        $finding = $this->service()->addFinding($investigation, [
            'title' => 'Count not performed', 'severity' => 'High', 'control_id' => $control->id,
        ], $this->branchOfficer);

        $this->assertTrue($investigation->refresh()->has_sod_conflict);

        // Hand the case to someone with no stake in the control.
        $this->service()->assignTeamMember($investigation, $this->head, 'lead', $this->head);

        $this->assertFalse(
            $investigation->refresh()->has_sod_conflict,
            'A conflict that has been resolved must stop being reported, or the flag stops meaning anything.',
        );
    }
}
