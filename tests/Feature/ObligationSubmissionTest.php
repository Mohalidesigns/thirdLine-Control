<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\ObligationAssignment;
use App\Models\ObligationInstance;
use App\Models\OrganisationUnit;
use App\Models\Regulator;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ObligationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The approval gate on a regulatory filing: it cannot be marked submitted
 * without proof, and it cannot be accepted by the person who filed it —
 * administrator or not (R2).
 */
class ObligationSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ObligationService $service;

    private User $owner;

    private User $head;

    private User $admin;

    private ObligationInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->service = app(ObligationService::class);

        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');
        $this->head = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->admin = $this->makeUser('admin@test.local', 'System Administrator');

        $regulator = Regulator::create(['code' => 'CBN', 'name' => 'Central Bank of Nigeria', 'jurisdiction' => 'NG']);

        $entity = OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Head Office', 'type' => 'Head Office',
            'entity_type' => 'commercial_bank', 'jurisdiction' => 'NG',
        ]);

        $obligation = RegulatoryObligation::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'regulator_id' => $regulator->id,
            'obligation_ref' => 'CBN-TEST-001',
            'title' => 'Board evaluation report',
            'obligation_type' => 'filing',
            'frequency' => 'annual',
            'due_rule' => ['type' => 'fixed_date', 'month' => 5, 'day' => 31],
            'verification_status' => 'verified',
        ]);

        $assignment = ObligationAssignment::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'obligation_id' => $obligation->id,
            'entity_id' => $entity->id,
            'owner_id' => $this->owner->id,
            'reviewer_id' => $this->head->id,
        ]);

        $this->instance = ObligationInstance::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'obligation_id' => $obligation->id,
            'assignment_id' => $assignment->id,
            'period_label' => 'FY2026',
            'due_at' => now()->addDays(20),
            'status' => 'In Progress',
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function attachEvidence(): Evidence
    {
        return Evidence::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'linked_type' => ObligationInstance::class,
            'linked_id' => $this->instance->id,
            'file_name' => 'board-evaluation-2026.pdf',
            'storage_path' => 'evidence/2026/05/board-evaluation.pdf',
            'uploaded_by' => $this->owner->id,
            'uploaded_at' => now(),
        ]);
    }

    public function test_a_submission_without_a_reference_is_refused(): void
    {
        $this->attachEvidence();

        $this->expectException(ValidationException::class);

        $this->service->submit($this->instance, $this->owner, ['submission_reference' => '   ']);
    }

    public function test_a_submission_without_evidence_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->submit($this->instance, $this->owner, ['submission_reference' => 'CBN/2026/00871']);
    }

    public function test_a_submission_with_a_reference_and_evidence_is_recorded(): void
    {
        $this->attachEvidence();

        $this->service->submit($this->instance, $this->owner, [
            'submission_reference' => 'CBN/2026/00871',
            'acknowledgement_ref' => 'ACK-9912',
        ]);

        $this->assertSame('Submitted', $this->instance->fresh()->status);
        $this->assertSame('CBN/2026/00871', $this->instance->fresh()->submission_reference);
        $this->assertSame(1, $this->instance->fresh()->evidence_count);
    }

    public function test_the_person_who_recorded_the_submission_cannot_accept_it(): void
    {
        $this->attachEvidence();
        $this->service->submit($this->instance, $this->owner, ['submission_reference' => 'CBN/2026/00871']);

        $this->expectException(ValidationException::class);

        $this->service->review($this->instance->fresh(), $this->owner, 'Accepted');
    }

    public function test_a_system_administrator_gets_no_bypass_on_their_own_submission(): void
    {
        $this->attachEvidence();
        $this->service->submit($this->instance, $this->admin, ['submission_reference' => 'CBN/2026/00871']);

        $this->expectException(ValidationException::class);

        $this->service->review($this->instance->fresh(), $this->admin, 'Accepted');
    }

    public function test_a_second_person_can_accept_the_submission(): void
    {
        $this->attachEvidence();
        $this->service->submit($this->instance, $this->owner, ['submission_reference' => 'CBN/2026/00871']);

        $this->service->review($this->instance->fresh(), $this->head, 'Accepted', 'Filed and acknowledged.');

        $this->assertSame('Accepted', $this->instance->fresh()->status);
        $this->assertSame($this->head->id, $this->instance->fresh()->reviewed_by);
    }

    public function test_the_policy_refuses_review_by_the_submitter(): void
    {
        $this->attachEvidence();
        $this->service->submit($this->instance, $this->owner, ['submission_reference' => 'CBN/2026/00871']);

        $instance = $this->instance->fresh();

        $this->assertFalse($this->owner->can('review', $instance), 'The person who filed cannot accept their own filing.');
        $this->assertTrue($this->head->can('review', $instance));
    }

    public function test_the_policy_gives_a_system_administrator_no_bypass_on_their_own_submission(): void
    {
        $this->attachEvidence();
        $this->service->submit($this->instance, $this->admin, ['submission_reference' => 'CBN/2026/00871']);

        $this->assertFalse(
            $this->admin->can('review', $this->instance->fresh()),
            'There is no administrator bypass on this gate.',
        );
    }

    public function test_an_accepted_filing_cannot_be_reopened_by_a_status_change(): void
    {
        $this->attachEvidence();
        $this->service->submit($this->instance, $this->owner, ['submission_reference' => 'CBN/2026/00871']);
        $this->service->review($this->instance->fresh(), $this->head, 'Accepted');

        $this->expectException(ValidationException::class);

        $this->service->transition($this->instance->fresh(), 'In Progress');
    }

    public function test_the_http_endpoint_enforces_the_same_gate(): void
    {
        $this->attachEvidence();
        $this->service->submit($this->instance, $this->owner, ['submission_reference' => 'CBN/2026/00871']);

        $this->actingAs($this->owner)
            ->post(route('obligations.instances.review', $this->instance->id), ['decision' => 'Accepted'])
            ->assertForbidden();

        $this->actingAs($this->head)
            ->post(route('obligations.instances.review', $this->instance->id), ['decision' => 'Accepted'])
            ->assertRedirect();

        $this->assertSame('Accepted', $this->instance->fresh()->status);
    }
}
