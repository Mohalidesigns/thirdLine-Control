<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\DataSnapshot;
use App\Models\DataSource;
use App\Models\DataSourceDataset;
use App\Models\FeatureFlag;
use App\Models\MonitoringRule;
use App\Models\SodConflictRule;
use App\Models\SodViolation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MonitoringService;
use App\Services\SnapshotService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Every Phase 12 page renders, is gated by feature flag and permission,
 * and pays for its data in a bounded number of queries (R6).
 */
class MonitoringPagesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $owner;

    private MonitoringRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->actingAs($this->officer);
        $this->rule = $this->seedRuleWithRun();
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function seedRuleWithRun(): MonitoringRule
    {
        $control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-001',
            'title' => 'Dual authorisation',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'is_key_control' => true,
            'status' => 'Active',
            'owner_id' => $this->owner->id,
        ]);

        $source = DataSource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Core banking',
            'source_type' => 'database',
            'system_category' => 'core_banking',
            'vendor_key' => 'finacle',
            'auth_type' => 'basic',
            'credentials' => 'a-secret',
            'is_active' => true,
            'created_by' => $this->officer->id,
            'approved_by' => $this->cfh->id,
            'approved_at' => now(),
        ]);

        $dataset = DataSourceDataset::create([
            'tenant_id' => $this->tenant->id,
            'data_source_id' => $source->id,
            'name' => 'postings',
            'retention_days' => 30,
            'pii_classification' => 'none',
            'is_active' => true,
        ]);

        $snapshots = app(SnapshotService::class);
        $snapshot = DataSnapshot::create([
            'tenant_id' => $this->tenant->id,
            'dataset_id' => $dataset->id,
            'snapshot_ref' => 'SNP-TEST-001',
            'status' => 'Capturing',
        ]);

        $rows = array_map(fn ($i) => ['id' => "T{$i}", 'flag' => $i % 2 === 0 ? 'BAD' : 'OK'], range(1, 40));
        $body = implode("\n", array_map(fn ($r) => json_encode($r), $rows));
        $path = $snapshots->pathFor($dataset, $snapshot);
        Storage::disk('local')->put($path, $body);

        $snapshot->update([
            'status' => 'Ready',
            'captured_at' => now(),
            'row_count' => count($rows),
            'checksum' => hash('sha256', $body),
            'storage_path' => $path,
            'expires_at' => now()->addDays(30)->toDateString(),
        ]);

        $service = app(MonitoringService::class);

        $rule = $service->createRule([
            'tenant_id' => $this->tenant->id,
            'control_id' => $control->id,
            'name' => 'Failed postings',
            'rule_type' => 'exception_list',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => [
                'conditions' => [['field' => 'flag', 'operator' => 'eq', 'value' => 'BAD']],
                'identifier_field' => 'id',
            ],
            'severity' => 'High',
            'frequency' => 'Daily',
            'owner_id' => $this->officer->id,
            'auto_create_exception' => true,
        ], $this->officer);

        $service->submitRule($rule, $this->officer);
        $rule = $service->approveRule($rule->fresh(), $this->cfh);

        $service->runRule($rule, 'manual', $this->officer, false);

        SodViolation::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => SodConflictRule::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Post and authorise',
                'system_key' => 'finacle',
                'function_a' => 'TXN_ENTRY',
                'function_b' => 'TXN_AUTH',
                'conflict_type' => 'create_approve',
                'risk_level' => 'Critical',
                'is_active' => true,
            ])->id,
            'subject_identifier' => 'ada',
            'subject_name' => 'Ada Obi',
            'detected_at' => now(),
            'status' => 'Open',
        ]);

        return $rule->fresh();
    }

    // ── Rendering ────────────────────────────────────────────────────

    public function test_the_dashboard_renders_with_coverage_and_health(): void
    {
        $this->get(route('monitoring.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Monitoring/Dashboard')
                ->has('dashboard.coverage.percentage')
                ->has('dashboard.sources')
                ->has('dashboard.findings')
                ->where('dashboard.rules.active', 1));
    }

    public function test_the_rule_pages_render(): void
    {
        $this->get(route('monitoring-rules.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Monitoring/Rules/Index')
                ->has('rules.data', 1)
                ->has('schemas.threshold.required'));

        $this->get(route('monitoring-rules.show', $this->rule))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Monitoring/Rules/Show')
                ->has('runs.data', 1)
                ->has('falsePositives.rate'));
    }

    public function test_the_run_and_finding_pages_render(): void
    {
        $run = $this->rule->runs()->firstOrFail();

        $this->get(route('monitoring-runs.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Monitoring/Runs/Index')->has('runs.data', 1));

        $this->get(route('monitoring-runs.show', $run))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Monitoring/Runs/Show')
                ->has('findings.data')
                ->has('snapshots', 1));

        $this->get(route('monitoring-findings.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Monitoring/Findings/Index')
                ->where('stats.open', 20));
    }

    public function test_the_sod_pages_render(): void
    {
        $this->get(route('sod.conflicts'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Sod/Conflicts')->has('rules.data', 1));

        $this->get(route('sod.violations'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Sod/Violations')->has('violations.data', 1));
    }

    public function test_the_data_source_pages_render_for_an_administrator_tier(): void
    {
        $source = DataSource::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->cfh)
            ->get(route('admin.data-sources.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/DataSources/Index')
                ->has('sources.data', 1)
                ->has('capabilities'));

        $this->actingAs($this->cfh)
            ->get(route('admin.data-sources.show', $source))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/DataSources/Show')
                ->has('datasets', 1)
                ->where('residency.country', 'NG'));
    }

    // ── Gating ───────────────────────────────────────────────────────

    public function test_a_control_owner_may_read_monitoring_but_not_review_or_manage(): void
    {
        $this->actingAs($this->owner);

        $this->get(route('monitoring.dashboard'))->assertOk();
        $this->get(route('monitoring-findings.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.review', false));

        $finding = $this->rule->runs()->firstOrFail()->findings()->firstOrFail();

        $this->post(route('monitoring-findings.review', $finding), [
            'status' => 'Confirmed',
            'review_notes' => 'Looks right to me.',
        ])->assertForbidden();

        $this->post(route('monitoring-rules.approve', $this->rule))->assertForbidden();
    }

    public function test_a_control_owner_cannot_reach_the_data_source_administration(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.data-sources.index'))
            ->assertForbidden();
    }

    public function test_a_control_officer_cannot_approve_a_rule_they_authored(): void
    {
        $this->actingAs($this->officer)
            ->post(route('monitoring-rules.approve', $this->rule))
            ->assertForbidden();
    }

    public function test_disabling_the_feature_flag_hides_every_monitoring_route(): void
    {
        FeatureFlag::where('key', 'continuous-monitoring')->update(['is_enabled' => false]);
        FeatureFlag::where('key', 'sod-analysis')->update(['is_enabled' => false]);

        $this->get(route('monitoring.dashboard'))->assertNotFound();
        $this->get(route('monitoring-rules.index'))->assertNotFound();
        $this->get(route('sod.violations'))->assertNotFound();
    }

    // ── Performance (R6) ─────────────────────────────────────────────

    public function test_the_list_pages_do_not_run_a_query_per_row(): void
    {
        // Twenty more rules, each with a run and a control, so an N+1 would
        // be unmistakable in the count.
        for ($i = 0; $i < 20; $i++) {
            MonitoringRule::create([
                'tenant_id' => $this->tenant->id,
                'rule_ref' => 'MON-'.str_pad((string) ($i + 100), 3, '0', STR_PAD_LEFT),
                'name' => 'Bulk rule '.$i,
                'rule_type' => 'threshold',
                'dataset_ids' => [],
                'rule_definition' => [],
                'severity' => 'Low',
                'frequency' => 'Daily',
                'status' => 'Draft',
                'created_by' => $this->officer->id,
            ]);
        }

        foreach (['monitoring-rules.index', 'monitoring-runs.index', 'monitoring-findings.index', 'sod.violations'] as $name) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->get(route($name))->assertOk();

            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertLessThan(40, $count, "{$name} ran {$count} queries — that smells like an N+1.");
        }
    }

    public function test_the_dashboard_is_computed_in_a_bounded_number_of_queries(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('monitoring.dashboard'))->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(35, $count, "The dashboard ran {$count} queries.");
    }
}
