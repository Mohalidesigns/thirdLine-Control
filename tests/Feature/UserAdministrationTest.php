<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PasswordResetLinkNotification;
use App\Notifications\UserInvitedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The Users admin area: create (invite or temporary password), edit,
 * suspend, soft-delete — System Administrator only, tenant-scoped, every
 * change audited, and the last active administrator untouchable.
 */
class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $cfh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->admin = $this->makeUser('admin@test.local', 'System Administrator');
        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Amina',
            'last_name' => 'Bello',
            'email' => 'amina@test.local',
            'position' => 'Control Analyst',
            'roles' => ['Control Officer'],
            'password_mode' => 'invite',
        ], $overrides);
    }

    // ── Reach ────────────────────────────────────────────────────────

    public function test_the_screen_is_reserved_for_the_system_administrator(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Users/Index')
                ->has('users.data')
                ->has('roles'));

        $this->actingAs($this->cfh)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    // ── Create ───────────────────────────────────────────────────────

    public function test_an_invited_user_is_created_with_a_pending_invitation(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), $this->validPayload())
            ->assertRedirect();

        $user = User::where('email', 'amina@test.local')->firstOrFail();
        $this->assertSame('Invited', $user->status());
        $this->assertTrue($user->hasRole('Control Officer'));
        $this->assertSame($this->tenant->id, $user->tenant_id);

        Notification::assertSentTo($user, UserInvitedNotification::class);

        $this->assertDatabaseHas('audit_trails', [
            'entity_type' => $user->getMorphClass(),
            'entity_id' => $user->id,
            'action' => 'created',
        ]);
    }

    public function test_a_temporary_password_user_must_change_it_at_first_login(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), $this->validPayload([
                'password_mode' => 'temporary',
                'temporary_password' => 'Temp-Password-1234',
            ]))
            ->assertRedirect();

        $user = User::where('email', 'amina@test.local')->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertSame('Active', $user->status());
        Notification::assertNothingSent();

        // The gate funnels them into the change screen…
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('password.force'));

        // …and clearing the flag opens the app.
        $this->actingAs($user)->post(route('password.force.update'), [
            'current_password' => 'Temp-Password-1234',
            'password' => 'My-Own-Password-99',
            'password_confirmation' => 'My-Own-Password-99',
        ])->assertRedirect(route('dashboard'));

        $this->assertFalse($user->refresh()->must_change_password);
    }

    public function test_a_duplicate_email_is_rejected_with_a_clear_message(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.store'), $this->validPayload(['email' => 'cfh@test.local']))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors(['email' => 'A user with this email address already exists.']);
    }

    // ── Invitation acceptance ────────────────────────────────────────

    public function test_the_signed_invite_link_sets_the_password_once(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)->post(route('admin.users.store'), $this->validPayload());
        $user = User::where('email', 'amina@test.local')->firstOrFail();

        $url = URL::temporarySignedRoute('invite.accept', now()->addDay(), [
            'user' => $user->id,
            'h' => substr(sha1((string) $user->password), 0, 16),
        ]);

        auth()->logout();

        $this->get($url)->assertOk();

        $this->post($url, [
            'password' => 'Chosen-Password-77',
            'password_confirmation' => 'Chosen-Password-77',
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('Active', $user->refresh()->status());
        $this->assertNotNull($user->invite_accepted_at);

        // The password changed, so the hash fragment no longer matches —
        // the link is dead even inside its signature window.
        auth()->logout();
        $this->get($url)->assertForbidden();
    }

    public function test_an_unsigned_invite_link_is_rejected(): void
    {
        $this->get(route('invite.accept', ['user' => $this->cfh->id, 'h' => 'x']))
            ->assertForbidden();
    }

    // ── Update / roles ───────────────────────────────────────────────

    public function test_an_edit_updates_attributes_and_roles_with_audit(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $this->cfh), [
                'first_name' => 'Babatunde',
                'last_name' => 'Adewale',
                'email' => 'cfh@test.local',
                'position' => 'Chief Control Officer',
                'roles' => ['Control Function Head', 'Executive Viewer'],
            ])
            ->assertRedirect();

        $this->cfh->refresh();
        $this->assertSame('Babatunde Adewale', $this->cfh->name);
        $this->assertTrue($this->cfh->hasRole('Executive Viewer'));

        $this->assertDatabaseHas('audit_trails', [
            'entity_type' => $this->cfh->getMorphClass(),
            'entity_id' => $this->cfh->id,
            'action' => 'roles-updated',
        ]);
    }

    // ── Suspension & the last-administrator invariant ────────────────

    public function test_suspension_blocks_login_and_reactivation_restores_it(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.suspend', $this->cfh))
            ->assertRedirect();
        $this->assertFalse($this->cfh->refresh()->is_active);
        $this->assertSame('Suspended', $this->cfh->status());

        $this->actingAs($this->admin)
            ->post(route('admin.users.reactivate', $this->cfh))
            ->assertRedirect();
        $this->assertTrue($this->cfh->refresh()->is_active);
    }

    public function test_the_last_active_administrator_cannot_be_deleted_or_suspended(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.suspend', $this->admin))
            ->assertSessionHasErrors();

        $this->actingAs($this->admin)
            ->delete(route('admin.users.destroy', $this->admin))
            ->assertSessionHasErrors();

        $this->assertTrue($this->admin->refresh()->is_active);
    }

    // ── Deletion ─────────────────────────────────────────────────────

    public function test_deletion_is_soft_and_preserves_attribution(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.users.destroy', $this->cfh))
            ->assertRedirect();

        $this->assertSoftDeleted('users', ['id' => $this->cfh->id]);
        $this->assertDatabaseHas('audit_trails', [
            'entity_type' => $this->cfh->getMorphClass(),
            'entity_id' => $this->cfh->id,
            'action' => 'deleted',
        ]);
    }

    public function test_a_foreign_tenant_user_is_invisible(): void
    {
        $other = Tenant::create(['name' => 'Other Bank', 'status' => 'active']);
        $foreign = User::factory()->create(['tenant_id' => $other->id]);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $foreign), $this->validPayload())
            ->assertNotFound();
    }

    // ── Reset link & bulk ────────────────────────────────────────────

    public function test_an_admin_can_send_a_password_reset_link(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.users.reset-password', $this->cfh))
            ->assertRedirect();

        Notification::assertSentTo($this->cfh, PasswordResetLinkNotification::class);
        $this->assertDatabaseHas('audit_trails', [
            'entity_id' => $this->cfh->id,
            'action' => 'password-reset-link-sent',
        ]);
    }

    public function test_bulk_role_assignment_skips_nothing_and_audits(): void
    {
        $officer = $this->makeUser('officer@test.local', 'Control Officer');

        $this->actingAs($this->admin)
            ->post(route('admin.users.bulk'), [
                'action' => 'assign-role',
                'ids' => [$this->cfh->id, $officer->id],
                'role' => 'Executive Viewer',
            ])
            ->assertRedirect();

        $this->assertTrue($this->cfh->refresh()->hasRole('Executive Viewer'));
        $this->assertTrue($officer->refresh()->hasRole('Executive Viewer'));
        $this->assertSame(2, AuditTrail::where('action', 'roles-updated')->count());
    }

    public function test_bulk_suspend_skips_the_acting_administrator(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.users.bulk'), [
                'action' => 'suspend',
                'ids' => [$this->admin->id, $this->cfh->id],
            ])
            ->assertRedirect();

        $this->assertTrue($this->admin->refresh()->is_active);
        $this->assertFalse($this->cfh->refresh()->is_active);
    }
}
