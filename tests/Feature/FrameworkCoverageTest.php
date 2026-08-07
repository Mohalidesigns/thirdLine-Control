<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\ControlRequirementMap;
use App\Models\EffectivenessRating;
use App\Models\Framework;
use App\Models\FrameworkMapping;
use App\Models\FrameworkRequirement;
use App\Models\OrganisationUnit;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\TestScript;
use App\Models\User;
use App\Services\FrameworkService;
use App\Services\MappingService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FrameworkCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private FrameworkService $frameworks;

    private MappingService $mappings;

    private Framework $framework;

    private FrameworkRequirement $requirement;

    private User $officer;

    private User $head;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->frameworks = app(FrameworkService::class);
        $this->mappings = app(MappingService::class);

        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->head = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->admin = $this->makeUser('admin@test.local', 'System Administrator');

        $this->framework = Framework::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'code' => 'TEST-FW',
            'name' => 'Test framework',
            'jurisdiction' => 'NG',
            'version' => '1.0.0',
            'category' => 'internal_control',
            'verification_status' => 'verified',
        ]);

        $this->requirement = FrameworkRequirement::create([
            'framework_id' => $this->framework->id,
            'ref_code' => 'R1',
            'title' => 'Access rights are reviewed periodically',
            'requirement_type' => 'principle',
            'is_testable' => true,
            'verification_status' => 'verified',
        ]);

        FrameworkRequirement::create([
            'framework_id' => $this->framework->id,
            'ref_code' => 'R2',
            'title' => 'Payments carry independent authorisation',
            'requirement_type' => 'principle',
            'is_testable' => true,
            'verification_status' => 'verified',
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function control(array $overrides = []): Control
    {
        return Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-'.fake()->unique()->numberBetween(100, 999),
            'title' => 'Quarterly user access recertification',
            'description' => 'Line managers recertify application access rights each quarter.',
            'type' => 'Detective',
            'nature' => 'Manual',
            'frequency' => 'Quarterly',
            'status' => 'Active',
            'created_by' => $this->officer->id,
            ...$overrides,
        ]);
    }

    private function publishEffectiveRating(Control $control): void
    {
        $script = TestScript::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $control->id,
            'title' => 'Access recertification test',
            'version_no' => 1,
            'status' => 'Active',
            'created_by' => $this->officer->id,
        ]);

        $instance = TestInstance::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_id' => $control->id,
            'test_script_id' => $script->id,
            'reference' => 'TST-2026-001',
            'period_label' => 'Q1 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'due_date' => '2026-04-15',
            'status' => 'Reviewed',
        ]);

        EffectivenessRating::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'test_instance_id' => $instance->id,
            'control_id' => $control->id,
            'period_label' => 'Q1 2026',
            'design_effectiveness' => 'Effective',
            'operating_effectiveness' => 'Effective',
            'overall_rating' => 'Effective',
            'status' => 'Published',
            'approved_by' => $this->head->id,
            'approved_at' => now()->subDays(10),
        ]);
    }

    public function test_an_unapproved_mapping_does_not_count_toward_coverage(): void
    {
        $control = $this->control();
        $this->mappings->mapControl($control, $this->requirement, $this->officer);

        $coverage = $this->frameworks->coverage($this->framework);

        $this->assertSame(0, $coverage['mapped_requirements']);
        $this->assertSame(2, $coverage['unmapped_requirements']);
    }

    public function test_an_approved_mapping_counts_toward_coverage(): void
    {
        $control = $this->control();
        $map = $this->mappings->mapControl($control, $this->requirement, $this->officer);
        $this->mappings->approveMapping($map, $this->head);

        $coverage = $this->frameworks->coverage($this->framework);

        $this->assertSame(1, $coverage['mapped_requirements']);
        $this->assertSame(0, $coverage['effective_requirements'], 'Mapping alone is not effectiveness.');
        $this->assertSame(50, $coverage['mapped_percentage']);
    }

    public function test_a_retired_control_stops_counting_toward_coverage(): void
    {
        $control = $this->control();
        $map = $this->mappings->mapControl($control, $this->requirement, $this->officer);
        $this->mappings->approveMapping($map, $this->head);

        $control->update(['status' => 'Retired']);

        $this->assertSame(0, $this->frameworks->coverage($this->framework)['mapped_requirements']);
    }

    public function test_a_currently_effective_control_lifts_the_requirement_to_effective(): void
    {
        $control = $this->control();
        $map = $this->mappings->mapControl($control, $this->requirement, $this->officer);
        $this->mappings->approveMapping($map, $this->head);
        $this->publishEffectiveRating($control);

        $coverage = $this->frameworks->coverage($this->framework);

        $this->assertSame(1, $coverage['effective_requirements']);
        $this->assertSame(50, $coverage['effective_percentage']);
    }

    // ── Maker-checker on the mapping itself ──────────────────────────

    public function test_the_officer_who_created_a_mapping_cannot_approve_it(): void
    {
        $map = $this->mappings->mapControl($this->control(), $this->requirement, $this->officer);

        $this->expectException(ValidationException::class);

        $this->mappings->approveMapping($map, $this->officer);
    }

    public function test_a_system_administrator_gets_no_bypass_on_their_own_mapping(): void
    {
        $map = $this->mappings->mapControl($this->control(), $this->requirement, $this->admin);

        $this->assertFalse($this->admin->can('approve', $map), 'There is no administrator bypass on this gate.');

        $this->expectException(ValidationException::class);

        $this->mappings->approveMapping($map, $this->admin);
    }

    public function test_the_same_control_cannot_be_mapped_to_the_same_requirement_twice(): void
    {
        $control = $this->control();
        $this->mappings->mapControl($control, $this->requirement, $this->officer);

        $this->expectException(ValidationException::class);

        $this->mappings->mapControl($control, $this->requirement, $this->officer);
    }

    // ── Cross-framework query ────────────────────────────────────────

    public function test_one_control_returns_every_requirement_it_satisfies_across_frameworks(): void
    {
        $other = Framework::withoutGlobalScopes()->create([
            'tenant_id' => null, 'code' => 'OTHER-FW', 'name' => 'Other framework',
            'jurisdiction' => 'INTL', 'version' => '1.0.0', 'category' => 'security',
            'verification_status' => 'unverified',
        ]);

        $otherRequirement = FrameworkRequirement::create([
            'framework_id' => $other->id,
            'ref_code' => 'A.5.18',
            'title' => 'Access rights',
            'requirement_type' => 'control',
            'verification_status' => 'unverified',
        ]);

        FrameworkMapping::create([
            'source_requirement_id' => $this->requirement->id,
            'target_requirement_id' => $otherRequirement->id,
            'relationship' => 'equivalent',
            'coverage_percentage' => 100,
        ]);

        $control = $this->control();
        $map = $this->mappings->mapControl($control, $this->requirement, $this->officer);
        $this->mappings->approveMapping($map, $this->head);

        $rows = $this->mappings->crossFrameworkCoverage($control->fresh());

        $this->assertCount(2, $rows, 'One approved mapping plus one inherited equivalence.');
        $this->assertSame(['OTHER-FW', 'TEST-FW'], collect($rows)->pluck('framework')->all());
        $this->assertTrue(collect($rows)->every(fn ($row) => $row['approved']));
    }

    public function test_an_inherited_requirement_does_not_count_while_its_mapping_is_unapproved(): void
    {
        $other = Framework::withoutGlobalScopes()->create([
            'tenant_id' => null, 'code' => 'OTHER-FW', 'name' => 'Other framework',
            'jurisdiction' => 'INTL', 'version' => '1.0.0', 'category' => 'security',
            'verification_status' => 'unverified',
        ]);

        $otherRequirement = FrameworkRequirement::create([
            'framework_id' => $other->id, 'ref_code' => 'A.5.18', 'title' => 'Access rights',
            'requirement_type' => 'control', 'verification_status' => 'unverified',
        ]);

        FrameworkMapping::create([
            'source_requirement_id' => $this->requirement->id,
            'target_requirement_id' => $otherRequirement->id,
            'relationship' => 'equivalent',
        ]);

        $control = $this->control();
        $this->mappings->mapControl($control, $this->requirement, $this->officer);

        $rows = $this->mappings->crossFrameworkCoverage($control->fresh());

        $this->assertCount(2, $rows);
        $this->assertTrue(collect($rows)->every(fn ($row) => $row['approved'] === false));
    }

    // ── Coverage matrix ──────────────────────────────────────────────

    public function test_the_coverage_matrix_is_scoped_per_entity(): void
    {
        $lagos = OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Lagos', 'type' => 'Branch',
        ]);

        $abuja = OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Abuja', 'type' => 'Branch',
        ]);

        $control = $this->control(['unit_id' => $lagos->id]);
        $map = $this->mappings->mapControl($control, $this->requirement, $this->officer);
        $this->mappings->approveMapping($map, $this->head);

        $matrix = $this->frameworks->coverageMatrix(
            $this->framework,
            OrganisationUnit::withoutGlobalScopes()->whereIn('id', [$lagos->id, $abuja->id])->get(),
        );

        $this->assertSame('mapped', $matrix['cells'][$this->requirement->id][$lagos->id]);
        $this->assertSame('unmapped', $matrix['cells'][$this->requirement->id][$abuja->id]);
    }

    public function test_mapping_suggestions_are_deterministic_and_explainable(): void
    {
        $this->control(['title' => 'Quarterly user access recertification', 'description' => 'Managers recertify application access rights quarterly.']);

        $suggestions = $this->mappings->suggestControlsForRequirement($this->requirement->fresh());

        $this->assertNotEmpty($suggestions);
        $this->assertSame(
            $suggestions,
            $this->mappings->suggestControlsForRequirement($this->requirement->fresh()),
            'The same inputs must always produce the same suggestions.',
        );
        $this->assertArrayHasKey('score', $suggestions[0]);
    }

    public function test_an_unapproved_mapping_is_visible_in_the_maker_checker_queue(): void
    {
        $this->seed(FeatureFlagSeeder::class);

        $this->mappings->mapControl($this->control(), $this->requirement, $this->officer);

        $this->actingAs($this->head)
            ->get(route('control-mappings.index'))
            ->assertOk();

        $this->assertSame(1, ControlRequirementMap::withoutGlobalScopes()->whereNull('approved_at')->count());
    }
}
