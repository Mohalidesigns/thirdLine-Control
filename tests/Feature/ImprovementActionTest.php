<?php

namespace Tests\Feature;

use App\Models\ImprovementAction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImprovementService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImprovementActionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function propose(array $overrides = []): ImprovementAction
    {
        return app(ImprovementService::class)->propose([
            'tenant_id' => $this->tenant->id,
            'title' => 'Automate the daily recon',
            'priority' => 'High',
            'source_type' => 'test',
            'owner_id' => $this->owner->id,
            ...$overrides,
        ], $this->officer);
    }

    public function test_full_lifecycle_proposed_to_verified(): void
    {
        $action = $this->propose();
        $service = app(ImprovementService::class);

        $this->assertSame('Proposed', $action->status);
        $this->assertStringStartsWith('IMP-', $action->reference);

        $service->approve($action, $this->cfh);
        $service->start($action->fresh());
        $service->markImplemented($action->fresh());
        $service->verify($action->fresh(), $this->cfh);

        $action->refresh();
        $this->assertSame('Verified', $action->status);
        $this->assertSame($this->cfh->id, $action->verified_by);
        $this->assertNotNull($action->verified_at);
    }

    public function test_owner_cannot_verify_their_own_improvement(): void
    {
        $action = $this->propose(['owner_id' => $this->cfh->id]);
        $service = app(ImprovementService::class);

        $service->approve($action, $this->cfh);
        $service->start($action->fresh());
        $service->markImplemented($action->fresh());

        $this->assertFalse($this->cfh->can('verify', $action->fresh()),
            'The implementing owner must never verify their own improvement');

        $this->expectException(ValidationException::class);
        $service->verify($action->fresh(), $this->cfh);
    }

    public function test_only_approvers_can_approve_and_status_gates_hold(): void
    {
        $action = $this->propose();

        $this->assertFalse($this->owner->can('approve', $action), 'A Control Owner cannot approve improvements');
        $this->assertTrue($this->cfh->can('approve', $action));

        // Cannot skip straight to Verified.
        $this->expectException(ValidationException::class);
        app(ImprovementService::class)->verify($action, $this->cfh);
    }

    public function test_rejected_improvement_is_terminal(): void
    {
        $action = $this->propose();
        $service = app(ImprovementService::class);

        $service->reject($action, $this->cfh, 'Duplicate of IMP-001');

        $this->assertSame('Rejected', $action->fresh()->status);

        $this->expectException(ValidationException::class);
        $service->start($action->fresh());
    }
}
