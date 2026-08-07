<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MfaPolicyChangedNotification;
use App\Services\MfaService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(NotificationEventSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('Control Officer');

        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('System Administrator');
    }

    private function enrol(User $user): array
    {
        $service = app(MfaService::class);
        $enrolment = $service->beginEnrolment($user);
        $code = app(Google2FA::class)->getCurrentOtp($enrolment['secret']);

        return $service->confirmEnrolment($user->fresh(), $code);
    }

    public function test_enrolment_confirms_with_a_valid_totp_and_returns_recovery_codes(): void
    {
        $codes = $this->enrol($this->user);

        $this->assertCount(MfaService::RECOVERY_CODE_COUNT, $codes);
        $this->assertTrue($this->user->fresh()->hasMfaEnabled());
    }

    public function test_enrolment_rejects_an_invalid_code(): void
    {
        $service = app(MfaService::class);
        $service->beginEnrolment($this->user);

        $this->expectException(ValidationException::class);

        $service->confirmEnrolment($this->user->fresh(), '000000');
    }

    public function test_user_with_mfa_is_challenged_before_reaching_the_app(): void
    {
        $this->enrol($this->user);

        $this->actingAs($this->user->fresh())
            ->get(route('dashboard'))
            ->assertRedirect(route('mfa.challenge'));
    }

    public function test_valid_totp_passes_the_challenge(): void
    {
        $this->enrol($this->user);
        $user = $this->user->fresh();
        $code = app(Google2FA::class)->getCurrentOtp($user->mfa_secret);

        $this->actingAs($user)
            ->post(route('mfa.verify'), ['code' => $code])
            ->assertRedirect(route('dashboard'));

        // Past the challenge, the app is reachable again.
        $this->actingAs($user)->get(route('notifications.index'))->assertOk();
    }

    public function test_recovery_code_is_single_use(): void
    {
        $codes = $this->enrol($this->user);
        $user = $this->user->fresh();
        $service = app(MfaService::class);

        $this->assertTrue($service->verifyChallenge($user, $codes[0]));
        // Second use of the same code must fail.
        $this->assertFalse($service->verifyChallenge($user->fresh(), $codes[0]));
    }

    public function test_enforced_user_past_grace_period_is_hard_blocked(): void
    {
        $this->tenant->forceFill([
            'mfa_enforced_roles' => ['Control Officer'],
            'mfa_grace_period_days' => 7,
            'mfa_enforced_at' => now()->subDays(30),
        ])->save();

        // The account predates the policy, so grace runs from enforcement.
        $this->user->forceFill(['created_at' => now()->subDays(60)])->save();

        $this->actingAs($this->user->fresh())
            ->get(route('dashboard'))
            ->assertRedirect(route('mfa.setup'));
    }

    public function test_enforced_user_within_grace_period_is_not_blocked(): void
    {
        $this->tenant->forceFill([
            'mfa_enforced_roles' => ['Control Officer'],
            'mfa_grace_period_days' => 7,
            'mfa_enforced_at' => now()->subDays(2),
        ])->save();

        $this->actingAs($this->user->fresh())->get(route('notifications.index'))->assertOk();
    }

    public function test_enforced_user_cannot_disable_their_own_mfa(): void
    {
        $this->enrol($this->user);
        $this->tenant->forceFill([
            'mfa_enforced_roles' => ['Control Officer'],
            'mfa_enforced_at' => now(),
        ])->save();

        $user = $this->user->fresh();
        $code = app(Google2FA::class)->getCurrentOtp($user->mfa_secret);
        $this->actingAs($user)->post(route('mfa.verify'), ['code' => $code]);

        $this->actingAs($user)
            ->from(route('mfa.setup'))
            ->delete(route('mfa.disable'), ['password' => 'password'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->hasMfaEnabled());
    }

    public function test_removing_mfa_enforcement_audits_high_severity_and_notifies_admins(): void
    {
        Notification::fake();

        $this->tenant->forceFill([
            'mfa_enforced_roles' => ['Control Officer', 'System Administrator'],
            'mfa_enforced_at' => now(),
        ])->save();

        $this->actingAs($this->admin)
            ->put(route('admin.security.update'), [
                'mfa_enforced_roles' => ['System Administrator'],
                'mfa_grace_period_days' => 7,
                'mfa_sms_opt_in' => false,
                'break_glass_daily_cap' => 3,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_trails', [
            'entity_type' => Tenant::class,
            'entity_id' => $this->tenant->id,
            'action' => 'mfa_enforcement_removed',
        ]);

        Notification::assertSentTo($this->admin, MfaPolicyChangedNotification::class);
    }

    public function test_non_admin_cannot_change_the_security_policy(): void
    {
        $this->actingAs($this->user)
            ->put(route('admin.security.update'), [
                'mfa_enforced_roles' => [],
                'mfa_grace_period_days' => 7,
                'mfa_sms_opt_in' => false,
                'break_glass_daily_cap' => 3,
            ])
            ->assertForbidden();
    }
}
