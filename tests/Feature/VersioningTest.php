<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\Tenant;
use App\Models\TestScript;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersioningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->officer = User::factory()->create(['email' => 'officer@test.local', 'tenant_id' => $this->tenant->id]);
        $this->officer->assignRole('Control Officer');
        $this->owner = User::factory()->create(['email' => 'owner@test.local', 'tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('Control Owner');

        $this->actingAs($this->officer);
    }

    private function makeTestScript(): TestScript
    {
        $control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-970',
            'title' => 'Reconciliations',
            'type' => 'Detective',
            'nature' => 'Manual',
            'frequency' => 'Daily',
            'status' => 'Active',
            'created_by' => $this->officer->id,
        ]);

        return TestScript::create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $control->id,
            'title' => 'Daily recon test',
            'objective' => 'Check completeness',
            'version_no' => 1,
            'status' => 'Draft',
            'created_by' => $this->officer->id,
        ]);
    }

    public function test_versions_are_written_on_create_and_change(): void
    {
        $script = $this->makeTestScript();

        $this->assertSame(1, $script->objectVersions()->count());
        $this->assertSame('Initial version', $script->objectVersions()->first()->change_reason);

        $script->update(['objective' => 'Check completeness AND accuracy']);

        $this->assertSame(2, $script->objectVersions()->count());
    }

    public function test_untracked_changes_do_not_create_versions(): void
    {
        $script = $this->makeTestScript();

        // created_by is excluded from versioned attributes by default.
        $script->update(['created_by' => $this->owner->id]);

        $this->assertSame(1, $script->objectVersions()->count());
    }

    public function test_diff_returns_field_level_changes_with_attribution(): void
    {
        $script = $this->makeTestScript();
        $script->update(['objective' => 'New objective', 'title' => 'Daily recon test v2']);

        $diff = $script->diffVersions(1, 2);

        $this->assertArrayHasKey('objective', $diff);
        $this->assertSame('Check completeness', $diff['objective']['from']);
        $this->assertSame('New objective', $diff['objective']['to']);
        $this->assertArrayHasKey('title', $diff);
        $this->assertArrayNotHasKey('status', $diff, 'Unchanged fields stay out of the diff');
    }

    public function test_diff_endpoint_returns_comparison_and_respects_permissions(): void
    {
        $script = $this->makeTestScript();
        $script->update(['objective' => 'Adjusted']);

        $this->getJson(route('versions.diff', ['test-script', $script->id]))
            ->assertOk()
            ->assertJsonPath('to', 2)
            ->assertJsonPath('diff.objective.to', 'Adjusted');

        // A Control Owner holds 'view controls' but not 'view tests'… they do
        // hold 'view tests'? No — owners lack it, so the endpoint is closed.
        $this->actingAs($this->owner)
            ->getJson(route('versions.diff', ['test-script', $script->id]))
            ->assertForbidden();
    }

    public function test_unknown_alias_is_rejected(): void
    {
        $this->getJson(route('versions.diff', ['nonsense', 1]))->assertNotFound();
    }
}
