<?php

namespace Tests\Feature;

use App\Models\SsoConfiguration;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SsoService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\NotificationEventSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SsoConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $adminA;

    private User $adminB;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(NotificationEventSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->adminA = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->adminA->assignRole('System Administrator');

        $this->adminB = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->adminB->assignRole('System Administrator');

        $this->officer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->officer->assignRole('Control Officer');
    }

    private function oidcPayload(array $overrides = []): array
    {
        return [
            'protocol' => 'oidc',
            'display_name' => 'Sign in with Microsoft',
            'oidc_issuer' => 'https://login.microsoftonline.com/tid/v2.0',
            'oidc_client_id' => 'client-123',
            'oidc_client_secret' => 'secret-456',
            'jit_provisioning' => true,
            'is_enabled' => true,
            ...$overrides,
        ];
    }

    private function makeConfig(User $creator, array $overrides = []): SsoConfiguration
    {
        return app(SsoService::class)->create($creator, $this->oidcPayload($overrides));
    }

    public function test_a_new_configuration_is_inactive_until_approved(): void
    {
        $this->actingAs($this->adminA)
            ->post(route('admin.sso.store'), $this->oidcPayload())
            ->assertRedirect();

        $config = SsoConfiguration::withoutGlobalScope('tenant')->firstOrFail();

        $this->assertNull($config->approved_at);
        $this->assertFalse($config->isActive());

        // Login flow refuses an unapproved configuration.
        $this->post(route('logout'));
        $this->get(route('sso.redirect', $config))->assertNotFound();
    }

    public function test_the_maker_cannot_approve_their_own_configuration(): void
    {
        $config = $this->makeConfig($this->adminA);

        $this->actingAs($this->adminA)
            ->post(route('admin.sso.approve', $config))
            ->assertForbidden();

        $this->assertNull($config->fresh()->approved_at);
    }

    public function test_a_second_administrator_can_approve_and_activate(): void
    {
        $config = $this->makeConfig($this->adminA);

        $this->actingAs($this->adminB)
            ->post(route('admin.sso.approve', $config))
            ->assertRedirect();

        $config->refresh();
        $this->assertTrue($config->isActive());
        $this->assertSame($this->adminB->id, $config->approved_by);
        $this->assertDatabaseHas('audit_trails', [
            'entity_type' => SsoConfiguration::class,
            'entity_id' => $config->id,
            'action' => 'sso_config_approved',
        ]);
    }

    public function test_a_non_administrator_cannot_approve(): void
    {
        $config = $this->makeConfig($this->adminA);

        $this->actingAs($this->officer)
            ->post(route('admin.sso.approve', $config))
            ->assertForbidden();
    }

    public function test_edits_to_an_active_configuration_are_staged_not_applied(): void
    {
        $config = $this->makeConfig($this->adminA);
        app(SsoService::class)->approve($this->adminB, $config);

        $this->actingAs($this->adminA)
            ->put(route('admin.sso.update', $config), $this->oidcPayload([
                'display_name' => 'Renamed IdP',
                'oidc_client_secret' => '',
            ]))
            ->assertRedirect();

        $config->refresh();
        // Live value unchanged; the change is pending.
        $this->assertSame('Sign in with Microsoft', $config->display_name);
        $this->assertTrue($config->hasPendingChanges());
        $this->assertSame($this->adminA->id, $config->pending_changes_by);
    }

    public function test_the_proposer_of_changes_cannot_approve_them(): void
    {
        $config = $this->makeConfig($this->adminA);
        app(SsoService::class)->approve($this->adminB, $config);

        // adminB proposes; adminB may not approve their own proposal —
        // even though adminB approved the original config.
        app(SsoService::class)->proposeUpdate($this->adminB, $config->fresh(), ['display_name' => 'Changed']);

        $this->actingAs($this->adminB)
            ->post(route('admin.sso.approve', $config))
            ->assertForbidden();

        // adminA (a different administrator) can.
        $this->actingAs($this->adminA)
            ->post(route('admin.sso.approve', $config))
            ->assertRedirect();

        $this->assertSame('Changed', $config->fresh()->display_name);
    }

    public function test_jit_provisioning_maps_group_claims_to_the_correct_role(): void
    {
        $config = $this->makeConfig($this->adminA, [
            'attribute_map' => [
                'email' => 'email',
                'name' => 'name',
                'groups' => 'groups',
                'role_map' => ['GRC-Admins' => 'Control Function Head'],
            ],
        ]);
        app(SsoService::class)->approve($this->adminB, $config);

        $user = app(SsoService::class)->resolveUser($config->fresh(), [
            'email' => 'ada@testbank.ng',
            'name' => 'Ada Obi',
            'groups' => ['Staff', 'GRC-Admins'],
        ]);

        $this->assertTrue($user->hasRole('Control Function Head'));
        $this->assertSame($this->tenant->id, $user->tenant_id);
    }

    public function test_jit_provisioning_falls_back_to_the_default_role_for_unmapped_claims(): void
    {
        $role = Role::where('name', 'Line Manager')->first();

        $config = $this->makeConfig($this->adminA, [
            'attribute_map' => ['role_map' => ['GRC-Admins' => 'Control Function Head']],
            'default_role_id' => $role->id,
        ]);
        app(SsoService::class)->approve($this->adminB, $config);

        $user = app(SsoService::class)->resolveUser($config->fresh(), [
            'email' => 'uche@testbank.ng',
            'name' => 'Uche Eze',
            'groups' => ['Staff'],   // matches nothing in role_map
        ]);

        $this->assertTrue($user->hasRole('Line Manager'));
    }

    public function test_jit_rejects_a_disallowed_email_domain(): void
    {
        $config = $this->makeConfig($this->adminA, [
            'allowed_email_domains' => ['testbank.ng'],
        ]);
        app(SsoService::class)->approve($this->adminB, $config);

        $this->expectException(ValidationException::class);

        app(SsoService::class)->resolveUser($config->fresh(), [
            'email' => 'intruder@attacker.example',
            'name' => 'X',
        ]);
    }

    public function test_password_login_is_break_glass_only_once_sso_is_active(): void
    {
        $config = $this->makeConfig($this->adminA);
        app(SsoService::class)->approve($this->adminB, $config);

        // Ordinary user: password login refused.
        $this->post(route('logout'));
        $this->post('/login', ['email' => $this->officer->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        // Break-glass user: allowed, audited.
        $this->adminA->forceFill(['is_break_glass' => true])->save();

        $this->post('/login', ['email' => $this->adminA->email, 'password' => 'password'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($this->adminA);
        $this->assertDatabaseHas('audit_trails', [
            'entity_type' => User::class,
            'entity_id' => $this->adminA->id,
            'action' => 'break_glass_login',
        ]);
    }

    public function test_break_glass_logins_are_capped_per_day(): void
    {
        $this->tenant->forceFill(['break_glass_daily_cap' => 1])->save();

        $config = $this->makeConfig($this->adminA);
        app(SsoService::class)->approve($this->adminB, $config);
        $this->adminA->forceFill(['is_break_glass' => true])->save();

        $this->post(route('logout'));
        $this->post('/login', ['email' => $this->adminA->email, 'password' => 'password'])->assertRedirect();

        // Cap of one is now spent — a second break-glass login is refused.
        $this->post(route('logout'));
        $this->post('/login', ['email' => $this->adminA->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
