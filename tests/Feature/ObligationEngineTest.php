<?php

namespace Tests\Feature;

use App\Models\ObligationAssignment;
use App\Models\ObligationInstance;
use App\Models\OrganisationUnit;
use App\Models\Regulator;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ObligationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ObligationEngineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ObligationService $service;

    private Regulator $regulator;

    private User $owner;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->service = app(ObligationService::class);
        $this->regulator = Regulator::create(['code' => 'CBN', 'name' => 'Central Bank of Nigeria', 'jurisdiction' => 'NG']);

        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');
        $this->head = $this->makeUser('cfh@test.local', 'Control Function Head');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function entity(array $overrides = []): OrganisationUnit
    {
        return OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Entity '.fake()->unique()->numberBetween(1, 9999),
            'type' => 'Head Office',
            'is_legal_entity' => true,
            'entity_type' => 'commercial_bank',
            'licence_categories' => ['national'],
            'jurisdiction' => 'NG',
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
            ...$overrides,
        ]);
    }

    private function obligation(array $overrides = []): RegulatoryObligation
    {
        return RegulatoryObligation::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'regulator_id' => $this->regulator->id,
            'obligation_ref' => 'OB-'.fake()->unique()->numberBetween(1, 99999),
            'title' => 'Annual filing',
            'obligation_type' => 'filing',
            'trigger_type' => 'calendar',
            'frequency' => 'annual',
            'due_rule' => ['type' => 'fixed_date', 'month' => 3, 'day' => 31],
            'verification_status' => 'unverified',
            ...$overrides,
        ]);
    }

    private function assign(RegulatoryObligation $obligation, OrganisationUnit $entity): ObligationAssignment
    {
        return ObligationAssignment::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'obligation_id' => $obligation->id,
            'entity_id' => $entity->id,
            'owner_id' => $this->owner->id,
            'reviewer_id' => $this->head->id,
        ]);
    }

    // ── Applicability ────────────────────────────────────────────────

    public function test_an_obligation_with_no_restrictions_applies_to_every_entity(): void
    {
        $obligation = $this->obligation(['applies_to' => null]);

        $this->assertTrue($this->service->appliesTo($obligation, $this->entity(['entity_type' => null])));
    }

    public function test_entity_type_restricts_applicability(): void
    {
        $obligation = $this->obligation(['applies_to' => ['entity_types' => ['commercial_bank']]]);

        $this->assertTrue($this->service->appliesTo($obligation, $this->entity()));
        $this->assertFalse($this->service->appliesTo($obligation, $this->entity(['entity_type' => 'insurer'])));
    }

    public function test_licence_category_restricts_applicability(): void
    {
        $obligation = $this->obligation(['applies_to' => ['licence_categories' => ['quoted']]]);

        $this->assertFalse($this->service->appliesTo($obligation, $this->entity(['licence_categories' => ['national']])));
        $this->assertTrue($this->service->appliesTo($obligation, $this->entity(['licence_categories' => ['national', 'quoted']])));
    }

    public function test_entity_type_and_licence_category_must_both_match(): void
    {
        $obligation = $this->obligation(['applies_to' => [
            'entity_types' => ['commercial_bank'],
            'licence_categories' => ['quoted'],
        ]]);

        $this->assertFalse(
            $this->service->appliesTo($obligation, $this->entity(['licence_categories' => ['national']])),
            'Matching the entity type alone is not enough when a licence category is also specified.',
        );

        $this->assertFalse(
            $this->service->appliesTo($obligation, $this->entity(['entity_type' => 'insurer', 'licence_categories' => ['quoted']])),
        );

        $this->assertTrue(
            $this->service->appliesTo($obligation, $this->entity(['licence_categories' => ['quoted']])),
        );
    }

    public function test_an_entity_with_no_profile_only_matches_unrestricted_obligations(): void
    {
        $bare = $this->entity(['entity_type' => null, 'licence_categories' => null, 'jurisdiction' => null]);

        $this->assertTrue($this->service->appliesTo($this->obligation(['applies_to' => []]), $bare));
        $this->assertFalse($this->service->appliesTo($this->obligation(['applies_to' => ['entity_types' => ['commercial_bank']]]), $bare));
    }

    // ── Instance generation ──────────────────────────────────────────

    public function test_running_the_generator_twice_creates_no_duplicates(): void
    {
        $assignment = $this->assign($this->obligation(), $this->entity());

        $first = $this->service->generateInstances($assignment);
        $countAfterFirst = ObligationInstance::withoutGlobalScopes()->count();

        $second = $this->service->generateInstances($assignment);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, 'A second run must create nothing.');
        $this->assertSame($countAfterFirst, ObligationInstance::withoutGlobalScopes()->count());
    }

    public function test_the_scheduled_command_is_idempotent(): void
    {
        $this->assign($this->obligation(), $this->entity());

        $this->artisan('atheris:generate-obligation-instances', ['--skip-reminders' => true])->assertSuccessful();
        $afterFirst = ObligationInstance::withoutGlobalScopes()->count();

        $this->artisan('atheris:generate-obligation-instances', ['--skip-reminders' => true])->assertSuccessful();

        $this->assertGreaterThan(0, $afterFirst);
        $this->assertSame($afterFirst, ObligationInstance::withoutGlobalScopes()->count());
    }

    public function test_no_instance_is_generated_outside_the_obligations_effective_window(): void
    {
        $obligation = $this->obligation([
            'effective_from' => now()->addYears(5)->toDateString(),
        ]);

        $created = $this->service->generateInstances($this->assign($obligation, $this->entity()));

        $this->assertSame(0, $created);
    }

    public function test_an_event_driven_obligation_generates_no_calendar_entries(): void
    {
        $obligation = $this->obligation([
            'trigger_type' => 'event',
            'frequency' => 'event_driven',
            'due_rule' => ['type' => 'event_relative', 'event' => 'breach_detected', 'offset_hours' => 72],
        ]);

        $this->assertSame(0, $this->service->generateInstances($this->assign($obligation, $this->entity())));
    }

    public function test_an_overdue_instance_accrues_penalty_exposure_when_refreshed(): void
    {
        $obligation = $this->obligation([
            'penalty_amount_minor' => 50000000,
            'penalty_currency' => 'NGN',
            'penalty_basis' => 'per_week',
        ]);

        $assignment = $this->assign($obligation, $this->entity());

        $instance = ObligationInstance::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'obligation_id' => $obligation->id,
            'assignment_id' => $assignment->id,
            'period_label' => 'FY2025',
            'due_at' => now()->subDays(10),
            'status' => 'Not Started',
            'penalty_currency' => 'NGN',
        ]);

        $this->service->refreshInstance($instance->load('obligation'));

        $this->assertSame('Overdue', $instance->status);
        $this->assertSame(100000000, $instance->penalty_exposure_minor, 'Ten days late is two whole weeks.');
    }

    // ── Non-applicability declaration (maker-checker) ────────────────

    public function test_an_unapproved_non_applicability_declaration_keeps_generating_instances(): void
    {
        $assignment = $this->assign($this->obligation(), $this->entity());

        $this->service->declareNonApplicable($assignment, $this->owner, 'This entity holds no card issuing licence.');

        $this->assertTrue($assignment->fresh()->generatesInstances());
        $this->assertGreaterThan(0, $this->service->generateInstances($assignment->fresh()));
    }

    public function test_the_owner_cannot_approve_their_own_non_applicability_declaration(): void
    {
        $assignment = $this->assign($this->obligation(), $this->entity());
        $this->service->declareNonApplicable($assignment, $this->owner, 'This entity holds no card issuing licence.');

        $this->expectException(ValidationException::class);

        $this->service->approveNonApplicability($assignment->fresh(), $this->owner);
    }

    public function test_an_approved_non_applicability_declaration_stops_generation(): void
    {
        $assignment = $this->assign($this->obligation(), $this->entity());
        $this->service->declareNonApplicable($assignment, $this->owner, 'This entity holds no card issuing licence.');
        $this->service->approveNonApplicability($assignment->fresh(), $this->head);

        $this->assertFalse($assignment->fresh()->generatesInstances());
        $this->assertSame(0, $this->service->generateInstances($assignment->fresh()));
    }

    // ── R10: unverified content never reaches a regulator ────────────

    public function test_an_unverified_obligation_cannot_enter_a_submission_pack(): void
    {
        $unverified = $this->obligation(['verification_status' => 'unverified']);
        $draft = $this->obligation(['verification_status' => 'draft']);
        $verified = $this->obligation(['verification_status' => 'verified']);

        foreach ([$unverified, $draft, $verified] as $obligation) {
            $this->service->generateInstances($this->assign($obligation, $this->entity()));
        }

        $eligible = $this->service->submissionEligibleInstances($this->tenant->id)->get();

        $this->assertGreaterThan(0, $eligible->count());
        $this->assertSame(
            [$verified->id],
            $eligible->pluck('obligation_id')->unique()->values()->all(),
            'Only obligations verified against the regulator’s primary document may be filed.',
        );
    }
}
