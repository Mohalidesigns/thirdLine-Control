<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\DataSnapshot;
use App\Models\DataSource;
use App\Models\DataSourceDataset;
use App\Models\MonitoringFinding;
use App\Models\MonitoringRule;
use App\Models\SodConflictRule;
use App\Models\SodViolation;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\User;
use App\Services\MonitoringService;
use App\Services\RuleEngineService;
use App\Services\SamplingService;
use App\Services\SnapshotService;
use App\Services\SodService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContinuousMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $secondOfficer;

    private User $owner;

    private Control $control;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->secondOfficer = $this->makeUser('officer2@test.local', 'Control Officer');
        $this->owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->actingAs($this->officer);

        $this->control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-001',
            'title' => 'Dual authorisation of GL postings',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'is_key_control' => true,
            'status' => 'Active',
            'owner_id' => $this->owner->id,
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function makeSource(array $overrides = []): DataSource
    {
        return DataSource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Core banking '.uniqid(),
            'source_type' => 'database',
            'system_category' => 'core_banking',
            'vendor_key' => 'finacle',
            'auth_type' => 'basic',
            'is_active' => true,
            'approved_by' => $this->cfh->id,
            'approved_at' => now(),
            'created_by' => $this->officer->id,
            'failure_threshold' => 3,
            ...$overrides,
        ]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function makeDataset(array $rows, array $overrides = [], ?DataSource $source = null): DataSourceDataset
    {
        $source ??= $this->makeSource();

        $dataset = DataSourceDataset::create([
            'tenant_id' => $this->tenant->id,
            'data_source_id' => $source->id,
            'name' => $overrides['name'] ?? 'postings',
            'retention_days' => 30,
            'pii_classification' => 'none',
            'is_active' => true,
            ...$overrides,
        ]);

        $this->writeSnapshot($dataset, $rows);

        return $dataset->fresh();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function writeSnapshot(DataSourceDataset $dataset, array $rows): DataSnapshot
    {
        $snapshots = app(SnapshotService::class);

        $snapshot = DataSnapshot::create([
            'tenant_id' => $dataset->tenant_id,
            'dataset_id' => $dataset->id,
            'snapshot_ref' => DataSnapshot::nextReference('SNP', true, 'snapshot_ref'),
            'status' => 'Capturing',
        ]);

        $path = $snapshots->pathFor($dataset, $snapshot);
        $body = '';

        foreach ($rows as $row) {
            $body .= json_encode($snapshots->redactRow($row, $dataset))."\n";
        }

        Storage::disk('local')->put($path, $body);

        $snapshot->update([
            'status' => 'Ready',
            'captured_at' => now(),
            'row_count' => count($rows),
            'size_bytes' => strlen($body),
            'checksum' => hash('sha256', $body),
            'storage_path' => $path,
            'expires_at' => now()->addDays($dataset->retention_days)->toDateString(),
        ]);

        return $snapshot->fresh();
    }

    private function makeRule(array $attributes): MonitoringRule
    {
        return app(MonitoringService::class)->createRule([
            'tenant_id' => $this->tenant->id,
            'name' => 'Rule '.uniqid(),
            'severity' => 'High',
            'frequency' => 'Daily',
            'owner_id' => $this->officer->id,
            'auto_create_exception' => false,
            ...$attributes,
        ], $this->officer);
    }

    private function activate(MonitoringRule $rule): MonitoringRule
    {
        $service = app(MonitoringService::class);
        $service->submitRule($rule, $this->officer);

        return $service->approveRule($rule->fresh(), $this->cfh);
    }

    private function evaluate(MonitoringRule $rule, DataSourceDataset ...$datasets): array
    {
        $snapshots = app(SnapshotService::class);
        $rowsByDataset = [];
        $models = [];

        foreach ($datasets as $dataset) {
            $rowsByDataset[$dataset->id] = iterator_to_array($snapshots->rows($snapshots->latestReady($dataset)), false);
            $models[$dataset->id] = $dataset;
        }

        return app(RuleEngineService::class)->evaluate($rule, $rowsByDataset, $models);
    }

    // ── Rule types against fixtures with known outcomes ──────────────

    public function test_threshold_rule_flags_only_the_groups_over_the_limit(): void
    {
        $dataset = $this->makeDataset([
            ['branch' => 'BR-001', 'amount' => 400],
            ['branch' => 'BR-001', 'amount' => 700],
            ['branch' => 'BR-002', 'amount' => 100],
        ]);

        $rule = $this->makeRule([
            'rule_type' => 'threshold',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => ['field' => 'amount', 'aggregate' => 'sum', 'operator' => 'lte', 'value' => 500, 'group_by' => 'branch'],
        ]);

        $result = $this->evaluate($rule, $dataset);

        $this->assertSame(2, $result['evaluated']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('BR-001', $result['findings'][0]['record_identifier']);
        $this->assertStringContainsString('1,100', $result['findings'][0]['failure_reason']);
    }

    public function test_reconciliation_reports_unmatched_items_and_differences_beyond_tolerance(): void
    {
        $source = $this->makeSource();

        $left = $this->makeDataset([
            ['ref' => 'A', 'amount' => 100],
            ['ref' => 'B', 'amount' => 200],
            ['ref' => 'C', 'amount' => 300],
        ], ['name' => 'ledger'], $source);

        $right = $this->makeDataset([
            ['ref' => 'A', 'amount' => 100.4],
            ['ref' => 'B', 'amount' => 250],
            ['ref' => 'D', 'amount' => 400],
        ], ['name' => 'statement'], $source);

        $rule = $this->makeRule([
            'rule_type' => 'reconciliation',
            'dataset_ids' => [$left->id, $right->id],
            'rule_definition' => [
                'left_dataset' => $left->id,
                'right_dataset' => $right->id,
                'key_fields' => ['ref'],
                'compare_fields' => [['left' => 'amount', 'right' => 'amount']],
                'tolerance' => ['absolute' => 1],
            ],
        ]);

        $result = $this->evaluate($rule, $left, $right);

        $identifiers = array_column($result['findings'], 'record_identifier');

        // A is within the ±1 tolerance and is not reported. B differs by 50,
        // C exists only on the left, D only on the right.
        $this->assertEqualsCanonicalizing(['B', 'C', 'D'], $identifiers);
        $this->assertSame(4, $result['evaluated']);
    }

    public function test_duplicate_rule_normalises_before_comparing(): void
    {
        $dataset = $this->makeDataset([
            ['account' => '0123456789', 'name' => 'Ada Obi'],
            ['account' => '012-345-6789', 'name' => 'Ada Obi'],
            ['account' => '9999999999', 'name' => 'Other'],
        ]);

        $rule = $this->makeRule([
            'rule_type' => 'duplicate',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => ['key_fields' => ['account'], 'normalise' => ['digits_only']],
        ]);

        $result = $this->evaluate($rule, $dataset);

        $this->assertCount(1, $result['findings']);
        $this->assertSame(2, $result['failed']);
        $this->assertSame('0123456789', $result['findings'][0]['record_identifier']);
    }

    public function test_gap_rule_finds_missing_sequence_numbers(): void
    {
        $dataset = $this->makeDataset([
            ['cheque' => 1001], ['cheque' => 1002], ['cheque' => 1005],
        ]);

        $rule = $this->makeRule([
            'rule_type' => 'gap',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => ['mode' => 'sequence', 'field' => 'cheque'],
        ]);

        $result = $this->evaluate($rule, $dataset);

        $this->assertEqualsCanonicalizing(['1003', '1004'], array_column($result['findings'], 'record_identifier'));
    }

    public function test_exception_list_matches_all_conditions(): void
    {
        $dataset = $this->makeDataset([
            ['id' => 'T1', 'amount' => 6000, 'channel' => 'BRANCH'],
            ['id' => 'T2', 'amount' => 6000, 'channel' => 'DIGITAL'],
            ['id' => 'T3', 'amount' => 100, 'channel' => 'BRANCH'],
        ]);

        $rule = $this->makeRule([
            'rule_type' => 'exception_list',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => [
                'conditions' => [
                    ['field' => 'amount', 'operator' => 'gt', 'value' => 5000],
                    ['field' => 'channel', 'operator' => 'eq', 'value' => 'BRANCH'],
                ],
                'match' => 'all',
                'identifier_field' => 'id',
            ],
        ]);

        $result = $this->evaluate($rule, $dataset);

        $this->assertSame(['T1'], array_column($result['findings'], 'record_identifier'));
    }

    public function test_completeness_reports_blank_required_fields_and_a_short_extract(): void
    {
        $dataset = $this->makeDataset([
            ['id' => 'A', 'bvn' => '221'],
            ['id' => 'B', 'bvn' => ''],
        ]);

        $rule = $this->makeRule([
            'rule_type' => 'completeness',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => ['required_fields' => ['bvn'], 'min_row_count' => 10],
        ]);

        $result = $this->evaluate($rule, $dataset);

        $reasons = array_column($result['findings'], 'record_identifier');
        $this->assertContains('row_count_minimum', $reasons);
        $this->assertSame(2, $result['failed']);
    }

    public function test_timeliness_reports_records_outside_the_window(): void
    {
        $dataset = $this->makeDataset([
            ['id' => 'A', 'raised' => '2026-01-01 00:00:00', 'closed' => '2026-01-01 12:00:00'],
            ['id' => 'B', 'raised' => '2026-01-01 00:00:00', 'closed' => '2026-01-05 00:00:00'],
        ]);

        $rule = $this->makeRule([
            'rule_type' => 'timeliness',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => [
                'event_field' => 'raised',
                'reference_field' => 'closed',
                'max_hours' => 72,
                'identifier_field' => 'id',
            ],
        ]);

        $result = $this->evaluate($rule, $dataset);

        $this->assertSame(['B'], array_column($result['findings'], 'record_identifier'));
    }

    public function test_trend_reports_movement_beyond_tolerance(): void
    {
        $dataset = $this->makeDataset([
            ['period' => '2026-01', 'value' => 100],
            ['period' => '2026-02', 'value' => 105],
            ['period' => '2026-03', 'value' => 400],
        ]);

        $rule = $this->makeRule([
            'rule_type' => 'trend',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => [
                'field' => 'value',
                'period_field' => 'period',
                'aggregate' => 'sum',
                'tolerance_percentage' => 20,
            ],
        ]);

        $result = $this->evaluate($rule, $dataset);

        $this->assertSame(['2026-03'], array_column($result['findings'], 'record_identifier'));
    }

    public function test_pattern_same_day_maker_checker_catches_self_approval(): void
    {
        $dataset = $this->makeDataset([
            ['id' => 'T1', 'maker' => 'ada', 'checker' => 'ada', 'created' => '2026-01-01 09:00:00', 'approved' => '2026-01-01 09:00:10'],
            ['id' => 'T2', 'maker' => 'ada', 'checker' => 'bola', 'created' => '2026-01-01 09:00:00', 'approved' => '2026-01-01 09:00:05'],
            ['id' => 'T3', 'maker' => 'ada', 'checker' => 'bola', 'created' => '2026-01-01 09:00:00', 'approved' => '2026-01-01 15:00:00'],
        ]);

        $rule = $this->makeRule([
            'rule_type' => 'pattern',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => [
                'pattern' => 'same_day_maker_checker',
                'maker_field' => 'maker',
                'checker_field' => 'checker',
                'created_at_field' => 'created',
                'approved_at_field' => 'approved',
                'identifier_field' => 'id',
                'min_seconds' => 60,
            ],
        ]);

        $result = $this->evaluate($rule, $dataset);

        $this->assertEqualsCanonicalizing(['T1', 'T2'], array_column($result['findings'], 'record_identifier'));
        $this->assertStringContainsString('same user', $result['findings'][0]['failure_reason']);
    }

    public function test_pattern_off_hours_flags_weekend_and_night_activity(): void
    {
        $dataset = $this->makeDataset([
            // 2026-01-05 is a Monday.
            ['id' => 'day', 'at' => '2026-01-05 11:00:00'],
            ['id' => 'night', 'at' => '2026-01-05 23:30:00'],
            ['id' => 'weekend', 'at' => '2026-01-10 11:00:00'],
        ]);

        $rule = $this->makeRule([
            'rule_type' => 'pattern',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => [
                'pattern' => 'off_hours',
                'timestamp_field' => 'at',
                'start_hour' => 8,
                'end_hour' => 18,
                'identifier_field' => 'id',
            ],
        ]);

        $result = $this->evaluate($rule, $dataset);

        $this->assertEqualsCanonicalizing(['night', 'weekend'], array_column($result['findings'], 'record_identifier'));
    }

    public function test_custom_sql_runs_in_the_sandbox_and_returns_rows(): void
    {
        $dataset = $this->makeDataset([
            ['id' => 'T1', 'amount' => 900],
            ['id' => 'T2', 'amount' => 100],
        ], ['name' => 'postings']);

        $rule = $this->makeRule([
            'rule_type' => 'custom_sql',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => [
                'query' => 'select id, amount from postings where amount > :floor',
                'params' => ['floor' => 500],
                'identifier_column' => 'id',
            ],
        ]);

        $result = $this->evaluate($rule, $dataset);

        $this->assertSame(['T1'], array_column($result['findings'], 'record_identifier'));
    }

    public function test_custom_sql_that_writes_is_refused_at_authoring_time(): void
    {
        $dataset = $this->makeDataset([['id' => 'T1']], ['name' => 'postings']);

        $this->expectException(ValidationException::class);

        $this->makeRule([
            'rule_type' => 'custom_sql',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => ['query' => 'delete from postings'],
        ]);
    }

    public function test_custom_sql_cannot_reach_the_application_database(): void
    {
        $dataset = $this->makeDataset([['id' => 'T1']], ['name' => 'postings']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/not one of/');

        $this->makeRule([
            'rule_type' => 'custom_sql',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => ['query' => 'select * from users'],
        ]);
    }

    // ── Sampling ─────────────────────────────────────────────────────

    public function test_sampling_is_reproducible_with_a_fixed_seed(): void
    {
        $sampling = app(SamplingService::class);
        $population = array_map(fn ($i) => ['id' => $i, 'amount' => $i * 10], range(1, 200));

        $first = $sampling->draw($population, ['method' => 'random', 'size' => 20], 4242);
        $second = $sampling->draw($population, ['method' => 'random', 'size' => 20], 4242);
        $different = $sampling->draw($population, ['method' => 'random', 'size' => 20], 9999);

        $this->assertSame(array_column($first['rows'], 'id'), array_column($second['rows'], 'id'));
        $this->assertSame(20, $first['sample_size']);
        $this->assertSame(200, $first['population_size']);
        $this->assertNotSame(array_column($first['rows'], 'id'), array_column($different['rows'], 'id'));
    }

    public function test_stratified_sampling_covers_every_stratum(): void
    {
        $population = [
            ...array_fill(0, 90, ['branch' => 'BR-001', 'amount' => 10]),
            ...array_fill(0, 7, ['branch' => 'BR-002', 'amount' => 10]),
            ...array_fill(0, 3, ['branch' => 'BR-003', 'amount' => 10]),
        ];

        $drawn = app(SamplingService::class)->draw(
            $population,
            ['method' => 'stratified', 'size' => 20, 'stratum_field' => 'branch'],
            7,
        );

        // Proportional allocation with a floor of one, so the three-item
        // branch is not invisible.
        $this->assertEqualsCanonicalizing(['BR-001', 'BR-002', 'BR-003'], array_keys($drawn['strata']));
        $this->assertGreaterThanOrEqual(1, $drawn['strata']['BR-003']);
    }

    public function test_monetary_unit_sampling_always_selects_an_item_above_the_interval(): void
    {
        $population = [
            ...array_fill(0, 50, ['amount' => 100]),
            ['amount' => 1000000],
        ];

        $drawn = app(SamplingService::class)->draw(
            $population,
            ['method' => 'monetary', 'size' => 5, 'value_field' => 'amount'],
            11,
        );

        $this->assertContains(1000000, array_column($drawn['rows'], 'amount'));
        $this->assertNotNull($drawn['interval']);
    }

    public function test_the_run_records_the_seed_so_the_sample_can_be_reproduced(): void
    {
        $dataset = $this->makeDataset(array_map(fn ($i) => ['id' => "T{$i}", 'flag' => 'BAD'], range(1, 100)));

        $rule = $this->activate($this->makeRule([
            'rule_type' => 'exception_list',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => [
                'conditions' => [['field' => 'flag', 'operator' => 'eq', 'value' => 'BAD']],
                'identifier_field' => 'id',
            ],
            'sample_config' => ['method' => 'random', 'size' => 25],
        ]));

        $run = app(MonitoringService::class)->runRule($rule, 'manual', $this->officer, false);

        $this->assertNotNull($run->sampling_seed);
        $this->assertSame(25, $run->records_evaluated);
    }

    // ── Continuous testing feeds the existing machinery ──────────────

    public function test_a_rule_linked_to_a_control_produces_a_test_instance_that_a_human_must_still_review(): void
    {
        $dataset = $this->makeDataset([['id' => 'T1', 'flag' => 'BAD'], ['id' => 'T2', 'flag' => 'OK']]);

        $rule = $this->activate($this->makeRule([
            'rule_type' => 'exception_list',
            'control_id' => $this->control->id,
            'dataset_ids' => [$dataset->id],
            'rule_definition' => [
                'conditions' => [['field' => 'flag', 'operator' => 'eq', 'value' => 'BAD']],
                'identifier_field' => 'id',
            ],
        ]));

        $run = app(MonitoringService::class)->runRule($rule, 'manual', $this->officer, false);

        $instance = TestInstance::withoutGlobalScopes()->find($run->test_instance_id);

        $this->assertNotNull($instance);
        $this->assertSame($this->control->id, $instance->control_id);
        $this->assertSame('automated', $instance->source);
        $this->assertSame($rule->id, $instance->monitoring_rule_id);

        // The machine gathers evidence; it never signs off (R4).
        $this->assertSame('Submitted', $instance->status);
        $this->assertNull($instance->reviewed_at);
        $this->assertNull($instance->reviewer_id);
    }

    public function test_a_failing_run_raises_one_exception_against_the_control(): void
    {
        $dataset = $this->makeDataset(array_map(fn ($i) => ['id' => "T{$i}", 'flag' => 'BAD'], range(1, 25)));

        $rule = $this->activate($this->makeRule([
            'rule_type' => 'exception_list',
            'control_id' => $this->control->id,
            'dataset_ids' => [$dataset->id],
            'auto_create_exception' => true,
            'severity' => 'Critical',
            'rule_definition' => [
                'conditions' => [['field' => 'flag', 'operator' => 'eq', 'value' => 'BAD']],
                'identifier_field' => 'id',
            ],
        ]));

        $run = app(MonitoringService::class)->runRule($rule, 'manual', $this->officer, false);

        $exceptions = ControlException::withoutGlobalScopes()
            ->where('source_type', 'Monitoring')
            ->where('source_id', $run->id)
            ->get();

        $this->assertCount(1, $exceptions, 'A run raises one summarising exception, not one per row.');
        $this->assertSame($this->control->id, $exceptions->first()->control_id);
        $this->assertSame('Critical', $exceptions->first()->severity);
        $this->assertSame($this->owner->id, $exceptions->first()->owner_id);

        // Every finding points at the same exception.
        $this->assertSame(
            25,
            MonitoringFinding::withoutGlobalScopes()->where('exception_id', $exceptions->first()->id)->count(),
        );
    }

    public function test_confirming_a_finding_raises_an_exception_and_a_false_positive_does_not(): void
    {
        $dataset = $this->makeDataset([['id' => 'T1', 'flag' => 'BAD'], ['id' => 'T2', 'flag' => 'BAD']]);

        $rule = $this->activate($this->makeRule([
            'rule_type' => 'exception_list',
            'control_id' => $this->control->id,
            'dataset_ids' => [$dataset->id],
            'rule_definition' => [
                'conditions' => [['field' => 'flag', 'operator' => 'eq', 'value' => 'BAD']],
                'identifier_field' => 'id',
            ],
        ]));

        $run = app(MonitoringService::class)->runRule($rule, 'manual', $this->officer, false);
        [$first, $second] = $run->findings()->orderBy('id')->get()->all();

        $service = app(MonitoringService::class);
        $service->reviewFinding($first, $this->cfh, 'Confirmed', 'Checked against the branch register.');
        $service->reviewFinding($second, $this->cfh, 'False Positive', 'The extract double-counts reversals.');

        $this->assertNotNull($first->fresh()->exception_id);
        $this->assertNull($second->fresh()->exception_id);
        $this->assertSame($this->control->id, $first->fresh()->exception->control_id);

        $rate = $service->falsePositiveRate($rule->fresh());
        $this->assertSame(0.5, $rate['rate']);
    }

    public function test_dismissing_a_finding_without_a_reason_is_refused(): void
    {
        $dataset = $this->makeDataset([['id' => 'T1', 'flag' => 'BAD']]);

        $rule = $this->activate($this->makeRule([
            'rule_type' => 'exception_list',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => [
                'conditions' => [['field' => 'flag', 'operator' => 'eq', 'value' => 'BAD']],
                'identifier_field' => 'id',
            ],
        ]));

        $run = app(MonitoringService::class)->runRule($rule, 'manual', $this->officer, false);

        $this->expectException(ValidationException::class);

        app(MonitoringService::class)->reviewFinding($run->findings()->first(), $this->cfh, 'False Positive', '  ');
    }

    // ── Approval gates (R2) ──────────────────────────────────────────

    public function test_a_rule_cannot_run_until_it_is_approved(): void
    {
        $dataset = $this->makeDataset([['id' => 'T1', 'flag' => 'BAD']]);

        $rule = $this->makeRule([
            'rule_type' => 'exception_list',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => ['conditions' => [['field' => 'flag', 'operator' => 'eq', 'value' => 'BAD']]],
        ]);

        $this->expectException(ValidationException::class);

        app(MonitoringService::class)->runRule($rule, 'manual', $this->officer, false);
    }

    public function test_the_author_of_a_rule_cannot_approve_it(): void
    {
        $dataset = $this->makeDataset([['id' => 'T1']]);

        $rule = $this->makeRule([
            'rule_type' => 'exception_list',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => ['conditions' => [['field' => 'id', 'operator' => 'is_not_null']]],
        ]);

        app(MonitoringService::class)->submitRule($rule, $this->officer);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/cannot be approved by the person who wrote it/');

        app(MonitoringService::class)->approveRule($rule->fresh(), $this->officer);
    }

    public function test_the_owner_of_a_rule_cannot_approve_it_either(): void
    {
        $dataset = $this->makeDataset([['id' => 'T1']]);

        $rule = $this->makeRule([
            'rule_type' => 'exception_list',
            'dataset_ids' => [$dataset->id],
            'owner_id' => $this->cfh->id,
            'rule_definition' => ['conditions' => [['field' => 'id', 'operator' => 'is_not_null']]],
        ]);

        app(MonitoringService::class)->submitRule($rule, $this->officer);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/owner of a rule cannot also be its approver/');

        app(MonitoringService::class)->approveRule($rule->fresh(), $this->cfh);
    }

    public function test_there_is_no_administrator_bypass_on_rule_approval(): void
    {
        $admin = $this->makeUser('admin@test.local', 'System Administrator');
        $dataset = $this->makeDataset([['id' => 'T1']]);

        $this->actingAs($admin);

        $rule = app(MonitoringService::class)->createRule([
            'tenant_id' => $this->tenant->id,
            'name' => 'Administrator-authored rule',
            'rule_type' => 'exception_list',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => ['conditions' => [['field' => 'id', 'operator' => 'is_not_null']]],
            'severity' => 'Low',
            'frequency' => 'Daily',
        ], $admin);

        app(MonitoringService::class)->submitRule($rule, $admin);

        $this->assertFalse($admin->can('approve', $rule->fresh()), 'A System Administrator must not approve their own rule.');

        $this->expectException(ValidationException::class);

        app(MonitoringService::class)->approveRule($rule->fresh(), $admin);
    }

    public function test_editing_what_an_active_rule_tests_invalidates_its_approval(): void
    {
        $dataset = $this->makeDataset([['id' => 'T1', 'flag' => 'BAD']]);

        $rule = $this->activate($this->makeRule([
            'rule_type' => 'exception_list',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => ['conditions' => [['field' => 'flag', 'operator' => 'eq', 'value' => 'BAD']]],
        ]));

        $this->assertSame('Active', $rule->status);

        // A cosmetic edit leaves the approval alone…
        $renamed = app(MonitoringService::class)->updateRule($rule, ['name' => 'Renamed'], $this->officer);
        $this->assertSame('Active', $renamed->status);

        // …but changing the test itself does not.
        $retuned = app(MonitoringService::class)->updateRule($renamed, [
            'rule_type' => 'exception_list',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => ['conditions' => [['field' => 'flag', 'operator' => 'eq', 'value' => 'WORSE']]],
        ], $this->officer);

        $this->assertSame('Pending Approval', $retuned->status);
        $this->assertNull($retuned->approved_at);
        $this->assertSame(2, $retuned->version_no);
    }

    // ── Segregation of duties in the client's systems ────────────────

    public function test_sod_rule_finds_a_subject_holding_both_halves_of_a_toxic_pair(): void
    {
        SodConflictRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Post and authorise',
            'system_key' => 'finacle',
            'function_a' => 'TXN_ENTRY',
            'function_b' => 'TXN_AUTH',
            'conflict_type' => 'create_approve',
            'risk_level' => 'Critical',
            'is_active' => true,
        ]);

        $dataset = $this->makeDataset([
            ['user' => 'ada', 'name' => 'Ada Obi', 'fn' => 'TXN_ENTRY'],
            ['user' => 'ada', 'name' => 'Ada Obi', 'fn' => 'TXN_AUTH'],
            ['user' => 'bola', 'name' => 'Bola Ade', 'fn' => 'TXN_ENTRY'],
        ]);

        $rule = $this->activate($this->makeRule([
            'rule_type' => 'sod_conflict',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => [
                'subject_field' => 'user',
                'function_field' => 'fn',
                'name_field' => 'name',
                'system_key' => 'finacle',
            ],
        ]));

        app(MonitoringService::class)->runRule($rule, 'manual', $this->officer, false);

        $violations = SodViolation::withoutGlobalScopes()->get();

        $this->assertCount(1, $violations);
        $this->assertSame('ada', $violations->first()->subject_identifier);
        $this->assertSame('Open', $violations->first()->status);
    }

    public function test_re_detecting_a_known_violation_does_not_duplicate_it(): void
    {
        SodConflictRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Post and authorise',
            'system_key' => 'finacle',
            'function_a' => 'TXN_ENTRY',
            'function_b' => 'TXN_AUTH',
            'conflict_type' => 'create_approve',
            'risk_level' => 'Critical',
            'is_active' => true,
        ]);

        $dataset = $this->makeDataset([
            ['user' => 'ada', 'name' => 'Ada Obi', 'fn' => 'TXN_ENTRY'],
            ['user' => 'ada', 'name' => 'Ada Obi', 'fn' => 'TXN_AUTH'],
        ]);

        $rule = $this->activate($this->makeRule([
            'rule_type' => 'sod_conflict',
            'dataset_ids' => [$dataset->id],
            'rule_definition' => ['subject_field' => 'user', 'function_field' => 'fn', 'system_key' => 'finacle'],
        ]));

        app(MonitoringService::class)->runRule($rule->fresh(), 'manual', $this->officer, false);
        app(MonitoringService::class)->runRule($rule->fresh(), 'manual', $this->officer, false);

        $this->assertSame(1, SodViolation::withoutGlobalScopes()->count());
    }

    public function test_accepting_a_sod_conflict_needs_the_permission_and_an_expiry(): void
    {
        $conflict = SodConflictRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Post and authorise',
            'function_a' => 'A',
            'function_b' => 'B',
            'conflict_type' => 'create_approve',
            'risk_level' => 'High',
            'is_active' => true,
        ]);

        $violation = SodViolation::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $conflict->id,
            'subject_identifier' => 'ada',
            'detected_at' => now(),
            'status' => 'Open',
        ]);

        $sod = app(SodService::class);

        // A Control Officer holds 'manage sod-rules' but not 'accept sod-violations'.
        try {
            $sod->accept($violation, $this->officer, 'We are comfortable.', now()->addMonth()->toDateString());
            $this->fail('A Control Officer must not be able to accept a segregation-of-duties conflict.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('senior approval', $e->validator->errors()->first());
        }

        // Even the Control Function Head cannot accept it open-endedly.
        try {
            $sod->accept($violation, $this->cfh, 'We are comfortable.', now()->subDay()->toDateString());
            $this->fail('An acceptance must expire in the future.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('future date', $e->validator->errors()->first());
        }

        $accepted = $sod->accept($violation, $this->cfh, 'Compensated by daily review.', now()->addMonth()->toDateString());
        $this->assertSame('Accepted', $accepted->status);

        // And it reopens when it lapses.
        $accepted->update(['accepted_until' => now()->subDay()->toDateString()]);
        $this->assertSame(1, $sod->expireAcceptances($this->tenant->id));
        $this->assertSame('Open', $accepted->fresh()->status);
    }

    public function test_an_unmitigated_conflict_raises_an_exception_on_the_mitigating_control(): void
    {
        $conflict = SodConflictRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Post and authorise',
            'function_a' => 'A',
            'function_b' => 'B',
            'conflict_type' => 'create_approve',
            'risk_level' => 'Critical',
            'mitigating_control_id' => $this->control->id,
            'is_active' => true,
        ]);

        $violation = SodViolation::create([
            'tenant_id' => $this->tenant->id,
            'rule_id' => $conflict->id,
            'subject_identifier' => 'ada',
            'subject_name' => 'Ada Obi',
            'detected_at' => now(),
            'status' => 'Open',
        ]);

        $exception = app(SodService::class)->raiseException($violation);

        $this->assertSame('SoD', $exception->source_type);
        $this->assertSame($this->control->id, $exception->control_id);
        $this->assertSame('Critical', $exception->severity);
        $this->assertSame($exception->id, $violation->fresh()->exception_id);

        // Idempotent — escalating twice does not produce a second exception.
        $this->assertSame($exception->id, app(SodService::class)->raiseException($violation->fresh())->id);
    }
}
