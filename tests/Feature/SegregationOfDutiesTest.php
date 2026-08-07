<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\User;
use App\Services\ControlService;
use App\Services\ExceptionService;
use App\Services\TestingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SegregationOfDutiesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $secondCfh;

    private User $officer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->secondCfh = $this->makeUser('cfh2@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeControl(array $overrides = []): Control
    {
        return Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-'.fake()->unique()->numberBetween(100, 999),
            'title' => 'Test control',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
            'owner_id' => $this->owner->id,
            'created_by' => $this->officer->id,
            ...$overrides,
        ]);
    }

    private function makeException(array $overrides = []): ControlException
    {
        return ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'EXC-'.fake()->unique()->numberBetween(1000, 9999),
            'source_type' => 'Manual',
            'title' => 'Test exception',
            'severity' => 'High',
            'owner_id' => $this->owner->id,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays(14)->toDateString(),
            'status' => 'Remediated',
            ...$overrides,
        ]);
    }

    // ── FR-1.8: maker-checker on controls ────────────────────────────

    public function test_creator_cannot_approve_their_own_control(): void
    {
        $control = $this->makeControl(['status' => 'Pending Approval', 'created_by' => $this->cfh->id]);

        $this->assertFalse($this->cfh->can('approve', $control));

        $this->expectException(ValidationException::class);
        app(ControlService::class)->approve($control, $this->cfh);
    }

    public function test_a_different_control_function_head_can_approve(): void
    {
        $control = $this->makeControl(['status' => 'Pending Approval', 'created_by' => $this->officer->id]);

        $this->assertTrue($this->cfh->can('approve', $control));

        app(ControlService::class)->approve($control, $this->cfh);

        $this->assertSame('Active', $control->fresh()->status);
    }

    public function test_control_officer_cannot_approve_controls(): void
    {
        $control = $this->makeControl(['status' => 'Pending Approval', 'created_by' => $this->cfh->id]);

        $this->assertFalse($this->officer->can('approve', $control));
    }

    // ── §4 SoD: tester ≠ reviewer ────────────────────────────────────

    public function test_tester_cannot_review_their_own_test(): void
    {
        $control = $this->makeControl();
        $instance = TestInstance::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $control->id,
            'reference' => 'TST-001',
            'period_label' => 'Jul-2026',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_date' => now()->addDays(5),
            'assigned_tester_id' => $this->cfh->id,
            'status' => 'Submitted',
        ]);

        $this->assertFalse($this->cfh->can('review', $instance));

        $this->expectException(ValidationException::class);
        app(TestingService::class)->review($instance, $this->cfh, true);
    }

    // ── FR-5.4: verification-based closure ───────────────────────────

    public function test_control_owner_cannot_close_an_exception_on_their_own_control(): void
    {
        $exception = $this->makeException(['control_id' => $this->makeControl()->id]);

        $this->assertFalse($this->owner->can('close', $exception));

        $this->expectException(ValidationException::class);
        app(ExceptionService::class)->verifyAndClose($exception, $this->owner, [
            'verification_method' => 'Attempted self-closure',
        ]);
    }

    public function test_tester_who_performed_the_source_test_cannot_close_the_resulting_exception(): void
    {
        $control = $this->makeControl();
        $instance = TestInstance::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $control->id,
            'reference' => 'TST-002',
            'period_label' => 'Jul-2026',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_date' => now()->addDays(5),
            'assigned_tester_id' => $this->cfh->id,
            'status' => 'Submitted',
        ]);

        $exception = $this->makeException([
            'control_id' => $control->id,
            'source_type' => 'Test',
            'source_id' => $instance->id,
        ]);

        $this->assertFalse($this->cfh->can('close', $exception));

        $this->expectException(ValidationException::class);
        app(ExceptionService::class)->verifyAndClose($exception, $this->cfh, [
            'verification_method' => 'Attempted closure by original tester',
        ]);
    }

    public function test_independent_control_function_head_can_verify_and_close(): void
    {
        $exception = $this->makeException(['control_id' => $this->makeControl()->id]);

        $this->assertTrue($this->secondCfh->can('close', $exception));

        app(ExceptionService::class)->verifyAndClose($exception, $this->secondCfh, [
            'verification_method' => 'Re-performance of the control',
        ]);

        $fresh = $exception->fresh();
        $this->assertSame('Verified-Closed', $fresh->status);
        $this->assertSame($this->secondCfh->id, $fresh->verified_by);
        $this->assertNotNull($fresh->verified_at);
    }

    public function test_exception_cannot_be_closed_before_remediation(): void
    {
        $exception = $this->makeException(['status' => 'In Progress']);

        $this->assertFalse($this->secondCfh->can('close', $exception));

        $this->expectException(ValidationException::class);
        app(ExceptionService::class)->verifyAndClose($exception, $this->secondCfh, [
            'verification_method' => 'Premature closure attempt',
        ]);
    }

    public function test_officer_role_cannot_close_exceptions_at_all(): void
    {
        $exception = $this->makeException();

        $this->assertFalse($this->officer->can('close', $exception));

        $this->expectException(ValidationException::class);
        app(ExceptionService::class)->verifyAndClose($exception, $this->officer, [
            'verification_method' => 'Officer attempting closure',
        ]);
    }

    public function test_closure_route_is_forbidden_for_the_control_owner(): void
    {
        $exception = $this->makeException(['control_id' => $this->makeControl()->id]);

        $this->actingAs($this->owner)
            ->post(route('exceptions.close', $exception), [
                'verification_method' => 'Owner bypass attempt via HTTP',
            ])
            ->assertForbidden();

        $this->assertSame('Remediated', $exception->fresh()->status);
    }
}
