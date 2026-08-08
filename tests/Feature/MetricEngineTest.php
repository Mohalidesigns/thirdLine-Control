<?php

namespace Tests\Feature;

use App\Models\ControlException;
use App\Models\EntityLink;
use App\Models\EscalationEvent;
use App\Models\Metric;
use App\Models\MetricBreach;
use App\Models\MetricThreshold;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MetricService;
use Carbon\CarbonImmutable;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RiskTaxonomySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MetricEngineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->seed(RiskTaxonomySeeder::class);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeMetric(array $thresholds = [], array $overrides = []): Metric
    {
        $metric = Metric::create([
            'tenant_id' => $this->tenant->id,
            'metric_type' => 'KRI',
            'code' => 'KRI-'.strtoupper(substr(md5((string) mt_rand()), 0, 6)),
            'name' => 'Attempted internal fraud cases',
            'unit' => 'cases',
            'data_type' => 'count',
            'direction' => 'lower_is_better',
            'frequency' => 'Monthly',
            'owner_id' => $this->cfh->id,
            'source' => 'manual',
            'aggregation' => 'last',
            'is_active' => true,
            ...$overrides,
        ]);

        $default = [
            ['Critical', 'gt', 10, null],
            ['Red', 'gt', 5, null],
            ['Amber', 'between', 3, 5],
            ['Green', 'lte', 2, null],
        ];

        foreach ($thresholds ?: $default as $index => [$level, $operator, $from, $to]) {
            $metric->thresholds()->create([
                'tenant_id' => $this->tenant->id,
                'level' => $level,
                'operator' => $operator,
                'value_from' => $from,
                'value_to' => $to,
                'colour_hex' => '#0ca30c',
                'sort_order' => $index,
            ]);
        }

        return $metric->fresh();
    }

    // ── Threshold operators ──────────────────────────────────────────

    public function test_every_operator_evaluates_correctly(): void
    {
        $cases = [
            // operator, from, to, value, expected match
            ['gt', 5, null, 6, true],
            ['gt', 5, null, 5, false],
            ['gte', 5, null, 5, true],
            ['gte', 5, null, 4.999, false],
            ['lt', 5, null, 4, true],
            ['lt', 5, null, 5, false],
            ['lte', 5, null, 5, true],
            ['lte', 5, null, 5.001, false],
            ['between', 3, 5, 3, true],
            ['between', 3, 5, 4, true],
            ['between', 3, 5, 5, true],
            ['between', 3, 5, 2.99, false],
            ['between', 3, 5, 5.01, false],
            ['outside', 3, 5, 2.99, true],
            ['outside', 3, 5, 5.01, true],
            ['outside', 3, 5, 4, false],
            ['outside', 3, 5, 3, false],
            ['outside', 3, 5, 5, false],
        ];

        foreach ($cases as [$operator, $from, $to, $value, $expected]) {
            $threshold = new MetricThreshold([
                'level' => 'Red',
                'operator' => $operator,
                'value_from' => $from,
                'value_to' => $to,
            ]);

            $this->assertSame(
                $expected,
                $threshold->matches($value),
                "{$operator}({$from}, ".var_export($to, true).") against {$value}",
            );
        }
    }

    public function test_between_and_outside_are_exact_complements(): void
    {
        $between = new MetricThreshold(['level' => 'Amber', 'operator' => 'between', 'value_from' => 10, 'value_to' => 20]);
        $outside = new MetricThreshold(['level' => 'Red', 'operator' => 'outside', 'value_from' => 10, 'value_to' => 20]);

        foreach ([0, 9.99, 10, 15, 20, 20.01, 100] as $value) {
            $this->assertNotSame(
                $between->matches($value),
                $outside->matches($value),
                "No value may match both bands, or neither — checked {$value}",
            );
        }
    }

    public function test_the_first_matching_band_wins_in_configured_order(): void
    {
        $metric = $this->makeMetric();
        $service = app(MetricService::class);

        $this->assertSame('Critical', $service->evaluateThreshold($metric, 12)->level);
        $this->assertSame('Red', $service->evaluateThreshold($metric, 7)->level);
        $this->assertSame('Amber', $service->evaluateThreshold($metric, 4)->level);
        $this->assertSame('Green', $service->evaluateThreshold($metric, 1)->level);
    }

    // ── Breach handling ──────────────────────────────────────────────

    public function test_a_red_reading_opens_a_breach_with_a_linked_exception(): void
    {
        $metric = $this->makeMetric();

        $value = app(MetricService::class)->capture($metric, [
            'value' => 7,
            'period_label' => '2026-07',
        ], $this->officer);

        $this->assertTrue($value->breach_flag);
        $this->assertSame('Red', $value->threshold_level_hit);

        $breach = MetricBreach::where('metric_value_id', $value->id)->first();
        $this->assertNotNull($breach);
        $this->assertSame('Red', $breach->level);

        $exception = ControlException::find($breach->exception_id);
        $this->assertNotNull($exception, 'A Red KRI breach must raise an exception.');
        $this->assertSame('KRI', $exception->source_type);
        $this->assertSame('High', $exception->severity);
        $this->assertStringContainsString($metric->code, $exception->title);

        // Linked both ways through the graph, not only by foreign key.
        $this->assertTrue(
            EntityLink::query()
                ->where('source_type', 'metric')->where('source_id', $metric->id)
                ->where('target_type', 'exception')->where('target_id', $exception->id)
                ->exists(),
            'The breach must be navigable from the metric to the exception.',
        );
    }

    public function test_a_red_breach_escalates_through_the_escalation_matrix(): void
    {
        $metric = $this->makeMetric();

        app(MetricService::class)->capture($metric, ['value' => 7, 'period_label' => '2026-07'], $this->officer);

        $this->assertTrue(
            EscalationEvent::withoutGlobalScopes()->where('metric_id', $metric->id)->exists(),
            'A Red KRI breach must fire the configured kri_breach escalation.',
        );
    }

    public function test_a_green_reading_opens_nothing(): void
    {
        $metric = $this->makeMetric();

        $value = app(MetricService::class)->capture($metric, ['value' => 1, 'period_label' => '2026-07'], $this->officer);

        $this->assertFalse($value->breach_flag);
        $this->assertSame(0, MetricBreach::count());
        $this->assertSame(0, ControlException::where('source_type', 'KRI')->count());
    }

    public function test_recapturing_a_period_updates_it_rather_than_double_counting(): void
    {
        $metric = $this->makeMetric();
        $service = app(MetricService::class);

        $service->capture($metric, ['value' => 7, 'period_label' => '2026-07'], $this->officer);
        $service->capture($metric, ['value' => 2, 'period_label' => '2026-07'], $this->officer);

        $this->assertSame(1, $metric->values()->count());
        $this->assertSame(2.0, (float) $metric->values()->first()->value);
    }

    public function test_a_breach_must_be_acknowledged_before_it_can_be_resolved(): void
    {
        $metric = $this->makeMetric();
        $service = app(MetricService::class);

        $value = $service->capture($metric, ['value' => 12, 'period_label' => '2026-07'], $this->officer);
        $breach = MetricBreach::where('metric_value_id', $value->id)->firstOrFail();

        $this->expectException(ValidationException::class);
        $service->resolveBreach($breach, $this->cfh, 'Fixed');
    }

    // ── Calculated metrics ───────────────────────────────────────────

    public function test_a_calculated_metric_computes_from_its_inputs(): void
    {
        $service = app(MetricService::class);

        $loss = $this->makeMetric([['Green', 'gte', 0, null]], ['code' => 'KRI-OPS-LOSS', 'data_type' => 'currency', 'currency' => 'NGN']);
        $volume = $this->makeMetric([['Green', 'gte', 0, null]], ['code' => 'KRI-TXN-VOL', 'data_type' => 'currency', 'currency' => 'NGN']);

        $service->capture($loss, ['value' => 5_000_000, 'period_label' => '2026-07'], $this->officer);
        $service->capture($volume, ['value' => 500_000_000_000, 'period_label' => '2026-07'], $this->officer);

        $calculated = $this->makeMetric(
            [['Red', 'gt', 3, null], ['Green', 'lte', 3, null]],
            [
                'code' => 'KRI-LOSS-BPS',
                'source' => 'calculated',
                'calculation_expression' => '[metric.KRI-OPS-LOSS] / [metric.KRI-TXN-VOL] * 10000',
                'data_type' => 'ratio',
            ],
        );

        $value = $service->computeCalculated($calculated, CarbonImmutable::parse('2026-07-15'));

        $this->assertNotNull($value);
        $this->assertEqualsWithDelta(0.1, (float) $value->value, 0.0001);
        $this->assertSame('calculated', $value->source);
        $this->assertSame('Green', $value->threshold_level_hit);
    }

    public function test_a_calculated_metric_with_missing_inputs_is_skipped_not_guessed(): void
    {
        $calculated = $this->makeMetric([], [
            'code' => 'KRI-DERIVED',
            'source' => 'calculated',
            'calculation_expression' => '[metric.KRI-MISSING] * 2',
        ]);

        $this->assertNull(app(MetricService::class)->computeCalculated($calculated));
    }

    public function test_an_unsafe_expression_is_rejected_before_it_is_stored(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(MetricService::class)->validateExpression($this->tenant->id, 'shell_exec("id")');
    }

    public function test_an_expression_referencing_an_unknown_metric_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(MetricService::class)->validateExpression($this->tenant->id, '[metric.DOES-NOT-EXIST] + 1');
    }

    // ── The scheduled run ────────────────────────────────────────────

    public function test_the_evaluate_metrics_command_computes_and_breaches(): void
    {
        $service = app(MetricService::class);

        $loss = $this->makeMetric([['Green', 'gte', 0, null]], ['code' => 'KRI-OPS-LOSS', 'data_type' => 'currency', 'currency' => 'NGN']);
        $volume = $this->makeMetric([['Green', 'gte', 0, null]], ['code' => 'KRI-TXN-VOL', 'data_type' => 'currency', 'currency' => 'NGN']);

        $period = $service->periodFor($loss)['label'];
        $service->capture($loss, ['value' => 50_000_000, 'period_label' => $period], $this->officer);
        $service->capture($volume, ['value' => 100_000_000_000, 'period_label' => $period], $this->officer);

        $this->makeMetric(
            [['Red', 'gt', 3, null], ['Green', 'lte', 3, null]],
            [
                'code' => 'KRI-LOSS-BPS',
                'source' => 'calculated',
                'calculation_expression' => '[metric.KRI-OPS-LOSS] / [metric.KRI-TXN-VOL] * 10000',
                'data_type' => 'ratio',
            ],
        );

        $this->artisan('atheris:evaluate-metrics')->assertSuccessful();

        $derived = Metric::where('code', 'KRI-LOSS-BPS')->firstOrFail();
        $latest = $derived->values()->first();

        $this->assertNotNull($latest);
        $this->assertEqualsWithDelta(5.0, (float) $latest->value, 0.0001);
        $this->assertSame('Red', $latest->threshold_level_hit);
        $this->assertTrue($latest->breach_flag);
        $this->assertSame(1, MetricBreach::where('metric_id', $derived->id)->count());
    }

    public function test_periods_follow_the_metric_frequency(): void
    {
        $service = app(MetricService::class);
        $moment = CarbonImmutable::parse('2026-08-15 09:00:00', 'Africa/Lagos');

        $this->assertSame('2026-08', $service->periodFor($this->makeMetric([], ['frequency' => 'Monthly']), $moment)['label']);
        $this->assertSame('2026-Q3', $service->periodFor($this->makeMetric([], ['frequency' => 'Quarterly']), $moment)['label']);
        $this->assertSame('2026-H2', $service->periodFor($this->makeMetric([], ['frequency' => 'Semi-annual']), $moment)['label']);
        $this->assertSame('2026', $service->periodFor($this->makeMetric([], ['frequency' => 'Annual']), $moment)['label']);
        $this->assertSame('2026-08-15', $service->periodFor($this->makeMetric([], ['frequency' => 'Daily']), $moment)['label']);
    }
}
