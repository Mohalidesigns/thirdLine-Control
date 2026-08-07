<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Models\Framework;
use App\Models\ObligationAssignment;
use App\Models\ObligationInstance;
use App\Models\OrganisationUnit;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContentPackInstaller;
use App\Services\ObligationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PublicHolidaySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Every Phase 8 page renders, is gated by role and feature flag, and pays
 * for its data in a bounded number of queries.
 */
class FrameworkAndObligationPagesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $head;

    private User $owner;

    private Framework $coso;

    private OrganisationUnit $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(PublicHolidaySeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->head = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $installer = app(ContentPackInstaller::class);
        $installer->install('COSO-IC-2013');
        $installer->install('CBN-CG-2023');

        $this->coso = Framework::withoutGlobalScopes()->where('code', 'COSO-IC-2013')->firstOrFail();

        $this->entity = OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Head Office',
            'type' => 'Head Office',
            'is_legal_entity' => true,
            'entity_type' => 'commercial_bank',
            'licence_categories' => ['national'],
            'jurisdiction' => 'NG',
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
        ]);

        $obligation = RegulatoryObligation::withoutGlobalScopes()
            ->whereNull('tenant_id')->where('obligation_ref', 'CBN-CG-2023-BOARD-EVAL')->firstOrFail();

        $assignment = ObligationAssignment::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'obligation_id' => $obligation->id,
            'entity_id' => $this->entity->id,
            'owner_id' => $this->owner->id,
            'reviewer_id' => $this->head->id,
        ]);

        app(ObligationService::class)->generateInstances($assignment);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    public function test_the_framework_index_renders(): void
    {
        $this->actingAs($this->head)
            ->get(route('frameworks.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Frameworks/Index')
                ->has('frameworks.data')
                ->where('stats.verified', 1));
    }

    public function test_the_framework_tree_renders_with_coverage(): void
    {
        $this->actingAs($this->head)
            ->get(route('frameworks.show', $this->coso->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Frameworks/Show')
                ->has('tree', 5)
                ->where('coverage.testable_requirements', 17));
    }

    public function test_the_coverage_heatmap_renders(): void
    {
        $this->actingAs($this->head)
            ->get(route('frameworks.coverage', $this->coso->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Frameworks/Coverage')
                ->has('matrix.requirements', 17)
                ->has('matrix.entities', 1));
    }

    public function test_the_framework_export_streams_a_content_pack(): void
    {
        $response = $this->actingAs($this->head)->get(route('frameworks.export', $this->coso->id));

        $response->assertOk();

        $payload = json_decode($response->streamedContent(), true);

        $this->assertSame('COSO-IC-2013', $payload['framework']['code']);
        $this->assertCount(22, $payload['requirements']);
    }

    public function test_the_obligation_register_renders_with_live_exposure(): void
    {
        $this->actingAs($this->head)
            ->get(route('obligations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Obligations/Index')
                ->has('obligations.data')
                ->has('exposure'));
    }

    public function test_the_compliance_calendar_renders_for_each_view(): void
    {
        foreach (['month', 'quarter', 'year'] as $view) {
            $this->actingAs($this->head)
                ->get(route('obligations.calendar', ['view' => $view]))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Obligations/Calendar')
                    ->where('view', $view)
                    ->has('density'));
        }
    }

    public function test_the_applicability_screen_lists_what_binds_the_entity(): void
    {
        $this->actingAs($this->head)
            ->get(route('entities.obligations', $this->entity->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Obligations/Applicability')
                ->has('obligations'));
    }

    public function test_the_instance_list_and_detail_render(): void
    {
        $instance = ObligationInstance::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->head)
            ->get(route('obligations.instances.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Obligations/Instances'));

        $this->actingAs($this->head)
            ->get(route('obligations.instances.show', $instance->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Obligations/InstanceShow')
                ->where('can.review', false));
    }

    public function test_the_regulatory_change_feed_renders(): void
    {
        $this->actingAs($this->head)
            ->get(route('regulatory-changes.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('RegulatoryChanges/Index'));
    }

    public function test_the_content_pack_admin_screen_renders(): void
    {
        $admin = $this->makeUser('admin@test.local', 'System Administrator');

        $this->actingAs($admin)
            ->get(route('admin.content-packs'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/ContentPacks')
                ->has('packs')
                ->where('can.install', true));
    }

    public function test_a_control_owner_cannot_reach_the_obligation_register_edit_screens(): void
    {
        $obligation = RegulatoryObligation::withoutGlobalScopes()->whereNull('tenant_id')->firstOrFail();

        $this->actingAs($this->owner)->get(route('obligations.create'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('obligations.edit', $obligation->id))->assertForbidden();
    }

    public function test_a_control_owner_cannot_install_content_packs(): void
    {
        $this->actingAs($this->owner)
            ->post(route('admin.content-packs.install'), ['code' => 'COSO-IC-2013'])
            ->assertForbidden();
    }

    public function test_the_pages_disappear_when_the_feature_flag_is_off(): void
    {
        FeatureFlag::where('key', 'obligations')->update(['is_enabled' => false]);

        $this->actingAs($this->head)->get(route('obligations.index'))->assertNotFound();
        $this->actingAs($this->head)->get(route('obligations.calendar'))->assertNotFound();
    }

    public function test_the_calendar_does_not_issue_a_query_per_row(): void
    {
        DB::enableQueryLog();

        $this->actingAs($this->head)->get(route('obligations.calendar', ['view' => 'year']))->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            40,
            $queries,
            'The calendar eager-loads its relations; a per-row query would blow this budget.',
        );
    }

    public function test_the_framework_tree_does_not_issue_a_query_per_requirement(): void
    {
        DB::enableQueryLog();

        $this->actingAs($this->head)->get(route('frameworks.show', $this->coso->id))->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            30,
            $queries,
            'Twenty-two requirements must not mean twenty-two coverage queries.',
        );
    }
}
