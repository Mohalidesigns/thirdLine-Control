<?php

namespace Tests\Feature;

use App\Models\EscalationMatrix;
use App\Models\OrganisationUnit;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AccessChangeAlertNotification;
use App\Notifications\AccountChangedNotification;
use App\Notifications\PasswordResetLinkNotification;
use App\Notifications\UserInvitedNotification;
use App\Services\EscalationService;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Segregation of duties on access itself: nobody changes their own roles,
 * a System Administrator never picks up the exception-handling rights the
 * role is denied, outstanding set-password links die with an email change
 * or a re-sent invite, and every access change is told to the person it
 * affects (and administrator grants to the other administrators).
 */
class UserAccessSeparationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $secondAdmin;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationEventSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->admin = $this->makeUser('admin@test.local', 'System Administrator');
        $this->secondAdmin = $this->makeUser('admin2@test.local', 'System Administrator');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');

        Notification::fake();
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function editPayload(User $user, array $overrides = []): array
    {
        [$first, $last] = array_pad(explode(' ', $user->name, 2), 2, 'X');

        return array_merge([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->all(),
        ], $overrides);
    }

    // ── Nobody changes their own roles ───────────────────────────────

    public function test_an_administrator_cannot_grant_themselves_a_role(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.users.roles.update', $this->admin), ['roles' => ['System Administrator', 'Control Officer']])
            ->assertSessionHasErrors('roles');

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->admin),
                $this->editPayload($this->admin, ['roles' => ['System Administrator', 'Control Officer']]))
            ->assertSessionHasErrors('roles');

        $this->assertSame(['System Administrator'], $this->admin->fresh()->roles->pluck('name')->all());
    }

    public function test_an_administrator_can_still_edit_their_own_details(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->admin), $this->editPayload($this->admin, ['position' => 'Head of IT']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Head of IT', $this->admin->fresh()->position);
    }

    public function test_bulk_assignment_skips_the_acting_administrator(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.bulk'), [
                'action' => 'assign-role',
                'ids' => [$this->admin->id, $this->officer->id],
                'role' => 'Line Manager',
            ]);

        $this->assertFalse($this->admin->fresh()->hasRole('Line Manager'));
        $this->assertTrue($this->officer->fresh()->hasRole('Line Manager'));
    }

    // ── The administrator never holds the withheld rights ────────────

    public function test_a_system_administrator_cannot_also_hold_the_control_function_head_role(): void
    {
        $this->actingAs($this->secondAdmin)
            ->put(route('admin.users.roles.update', $this->admin), ['roles' => ['System Administrator', 'Control Function Head']])
            ->assertSessionHasErrors('roles');

        $this->assertFalse($this->admin->fresh()->can('respond exceptions'));
    }

    public function test_a_new_administrator_cannot_be_created_with_a_conflicting_role(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [
                'first_name' => 'Ola', 'last_name' => 'Ade', 'email' => 'ola@test.local',
                'roles' => ['System Administrator', 'Control Function Head'],
                'password_mode' => 'invite',
            ])
            ->assertSessionHasErrors('roles');

        $this->assertDatabaseMissing('users', ['email' => 'ola@test.local']);
    }

    public function test_a_role_held_by_an_administrator_cannot_gain_a_withheld_right(): void
    {
        $role = Role::create(['name' => 'IT Support', 'guard_name' => 'web']);
        $role->givePermissionTo('view controls');
        $this->secondAdmin->assignRole('IT Support');

        $this->actingAs($this->admin)
            ->put(route('admin.roles.update', $role), ['permissions' => ['view controls', 'respond exceptions']])
            ->assertSessionHasErrors('permissions');

        $this->assertFalse($this->secondAdmin->fresh()->can('respond exceptions'));
    }

    // ── Who is told ──────────────────────────────────────────────────

    public function test_a_role_change_is_told_to_the_user(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.users.roles.update', $this->officer), ['roles' => ['Line Manager']])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->officer, AccountChangedNotification::class,
            fn (AccountChangedNotification $n) => $n->kind === AccountChangedNotification::ROLES_CHANGED
                && $n->roles === ['Line Manager']);
    }

    public function test_making_someone_an_administrator_alerts_the_other_administrators(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.users.roles.update', $this->officer), ['roles' => ['System Administrator']])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->secondAdmin, AccessChangeAlertNotification::class,
            fn (AccessChangeAlertNotification $n) => $n->kind === AccessChangeAlertNotification::ADMIN_GRANTED);
        Notification::assertNotSentTo($this->admin, AccessChangeAlertNotification::class);
    }

    public function test_editing_a_roles_permissions_alerts_the_other_administrators(): void
    {
        $role = Role::findByName('Line Manager');

        $this->actingAs($this->admin)
            ->put(route('admin.roles.update', $role), ['permissions' => ['view controls']])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->secondAdmin, AccessChangeAlertNotification::class,
            fn (AccessChangeAlertNotification $n) => $n->kind === AccessChangeAlertNotification::ROLE_PERMISSIONS_CHANGED);
    }

    public function test_suspension_and_reactivation_are_told_to_the_user(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.suspend', $this->officer));
        $this->actingAs($this->admin)->post(route('admin.users.reactivate', $this->officer));

        Notification::assertSentTo($this->officer, AccountChangedNotification::class,
            fn (AccountChangedNotification $n) => $n->kind === AccountChangedNotification::SUSPENDED);
        Notification::assertSentTo($this->officer, AccountChangedNotification::class,
            fn (AccountChangedNotification $n) => $n->kind === AccountChangedNotification::REACTIVATED);
    }

    public function test_an_email_change_is_told_to_both_addresses(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->officer),
                $this->editPayload($this->officer, ['email' => 'new-officer@test.local']))
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($this->officer->fresh(), AccountChangedNotification::class,
            fn (AccountChangedNotification $n) => $n->kind === AccountChangedNotification::EMAIL_CHANGED);
        Notification::assertSentOnDemand(AccountChangedNotification::class,
            fn ($n, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'officer@test.local');
    }

    // ── Set-password links ───────────────────────────────────────────

    private function inviteLinkFor(User $user): string
    {
        return URL::temporarySignedRoute('invite.accept', now()->addDay(), [
            'user' => $user->id,
            'h' => $user->credentialFingerprint(),
        ]);
    }

    private function invite(): User
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'first_name' => 'Amina', 'last_name' => 'Bello', 'email' => 'amina@wrong.test',
            'roles' => ['Control Owner'], 'password_mode' => 'invite',
        ])->assertSessionHasNoErrors();

        return User::where('email', 'amina@wrong.test')->firstOrFail();
    }

    public function test_correcting_an_invited_email_kills_the_link_sent_to_the_old_address(): void
    {
        $user = $this->invite();
        $oldLink = $this->inviteLinkFor($user);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $user), $this->editPayload($user, ['email' => 'amina@right.test']))
            ->assertSessionHasNoErrors();

        auth()->logout();
        $this->get($oldLink)->assertForbidden();
        $this->get($this->inviteLinkFor($user->fresh()))->assertOk();
    }

    public function test_resending_an_invite_kills_the_earlier_link(): void
    {
        $user = $this->invite();
        $oldLink = $this->inviteLinkFor($user);

        $this->actingAs($this->admin)->post(route('admin.users.resend-invite', $user))->assertSessionHasNoErrors();

        auth()->logout();
        $this->get($oldLink)->assertForbidden();
    }

    public function test_invite_and_reset_emails_are_queued(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new UserInvitedNotification('https://x', 'A', 'today'));
        $this->assertInstanceOf(ShouldQueue::class, new PasswordResetLinkNotification('https://x', 'today'));
    }

    // ── MFA reset ────────────────────────────────────────────────────

    public function test_an_administrator_can_reset_another_users_mfa(): void
    {
        $this->officer->forceFill([
            'mfa_secret' => 'JBSWY3DPEHPK3PXP',
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => ['x'],
        ])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.users.reset-mfa', $this->officer))
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->officer->fresh()->hasMfaEnabled());
        Notification::assertSentTo($this->officer, AccountChangedNotification::class,
            fn (AccountChangedNotification $n) => $n->kind === AccountChangedNotification::MFA_RESET);
    }

    public function test_an_administrator_cannot_reset_their_own_mfa_from_the_admin_screen(): void
    {
        $this->admin->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.users.reset-mfa', $this->admin))
            ->assertSessionHasErrors('user');

        $this->assertTrue($this->admin->fresh()->hasMfaEnabled());
    }

    // ── Tenant isolation and stale recipients ────────────────────────

    public function test_a_user_cannot_be_placed_in_another_tenants_unit(): void
    {
        $other = Tenant::create(['name' => 'Other Bank', 'status' => 'active']);
        $foreignUnit = OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'name' => 'Foreign branch', 'type' => 'Branch',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->officer), $this->editPayload($this->officer, ['unit_id' => $foreignUnit->id]))
            ->assertSessionHasErrors('unit_id');
    }

    public function test_a_suspended_or_deleted_user_is_never_an_escalation_recipient(): void
    {
        $suspended = $this->makeUser('cfh-gone@test.local', 'Control Function Head');
        $suspended->update(['is_active' => false]);
        $active = $this->makeUser('cfh@test.local', 'Control Function Head');

        $rule = new EscalationMatrix(['tenant_id' => $this->tenant->id, 'recipient_role' => 'Control Function Head']);
        $method = new \ReflectionMethod(EscalationService::class, 'roleRecipient');

        $this->assertTrue($method->invoke(app(EscalationService::class), $rule, null)->is($active));
    }
}
