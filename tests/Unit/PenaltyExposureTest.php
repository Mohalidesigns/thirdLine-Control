<?php

namespace Tests\Unit;

use App\Models\ObligationAssignment;
use App\Models\ObligationInstance;
use App\Models\OrganisationUnit;
use App\Models\Regulator;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ObligationService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cost-of-non-compliance figure is the number executives act on, so
 * every penalty basis gets an explicit test — including the CBN consumer
 * protection per-week case, where a part-week counts as a whole week.
 */
class PenaltyExposureTest extends TestCase
{
    use RefreshDatabase;

    private ObligationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ObligationService::class);
    }

    private function overdueInstance(
        string $basis,
        int $amountMinor,
        int $daysOverdue,
        ?int $fixedAmountMinor = null,
    ): ObligationInstance {
        $tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $regulator = Regulator::create(['code' => 'REG'.fake()->unique()->numberBetween(1, 9999), 'name' => 'Test Regulator', 'jurisdiction' => 'NG']);

        $obligation = RegulatoryObligation::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'regulator_id' => $regulator->id,
            'obligation_ref' => 'OB-'.fake()->unique()->numberBetween(1, 99999),
            'title' => 'Test obligation',
            'obligation_type' => 'filing',
            'frequency' => 'annual',
            'due_rule' => ['type' => 'fixed_date', 'month' => 3, 'day' => 31],
            'penalty_amount_minor' => $amountMinor,
            'penalty_fixed_amount_minor' => $fixedAmountMinor,
            'penalty_currency' => 'NGN',
            'penalty_basis' => $basis,
            'verification_status' => 'unverified',
        ]);

        $unit = OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Head Office', 'type' => 'Head Office',
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $assignment = ObligationAssignment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'obligation_id' => $obligation->id,
            'entity_id' => $unit->id,
            'owner_id' => $user->id,
        ]);

        $instance = ObligationInstance::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'obligation_id' => $obligation->id,
            'assignment_id' => $assignment->id,
            'period_label' => 'FY2026',
            'due_at' => now()->subDays($daysOverdue),
            'status' => $daysOverdue > 0 ? 'Overdue' : 'Not Started',
            'days_overdue' => $daysOverdue,
            'penalty_currency' => 'NGN',
        ]);

        $instance->setRelation('obligation', $obligation);

        return $instance;
    }

    public function test_a_fixed_penalty_does_not_grow_with_time(): void
    {
        $exposure = $this->service->calculateExposure($this->overdueInstance('fixed', 200000000, 45));

        $this->assertTrue(Money::of(200000000, 'NGN')->equals($exposure));
    }

    public function test_a_per_day_penalty_multiplies_by_days_overdue(): void
    {
        // SEC Nigeria's ₦5,000 per day of default, 30 days late.
        $exposure = $this->service->calculateExposure($this->overdueInstance('per_day', 500000, 30));

        $this->assertSame(15000000, $exposure->amount);
        $this->assertSame('₦150,000.00', $exposure->format());
    }

    public function test_a_per_week_penalty_counts_a_part_week_as_a_whole_week(): void
    {
        // CBN consumer protection: ₦500,000 per complaint per week unresolved.
        // Eight days late is two weeks, not one and a bit.
        $exposure = $this->service->calculateExposure($this->overdueInstance('per_week', 50000000, 8));

        $this->assertSame(100000000, $exposure->amount);
        $this->assertSame('₦1,000,000.00', $exposure->format());
    }

    public function test_a_per_week_penalty_charges_a_full_week_on_the_first_day(): void
    {
        $exposure = $this->service->calculateExposure($this->overdueInstance('per_week', 50000000, 1));

        $this->assertSame(50000000, $exposure->amount);
    }

    public function test_a_per_instance_penalty_is_charged_once(): void
    {
        $exposure = $this->service->calculateExposure($this->overdueInstance('per_instance', 200000000, 100));

        $this->assertSame(200000000, $exposure->amount);
    }

    public function test_a_two_part_penalty_charges_the_fixed_sum_once_on_top_of_the_daily_one(): void
    {
        // NCC: ₦3,000,000 on default plus ₦300,000 for each day it continues.
        // Ten days late is ₦3,000,000 + ₦3,000,000, not ₦3,000,000.
        $exposure = $this->service->calculateExposure(
            $this->overdueInstance('per_day', 300_000_00, 10, 3_000_000_00)
        );

        $this->assertSame(6_000_000_00, $exposure->amount);
        $this->assertSame('₦6,000,000.00', $exposure->format());
    }

    public function test_a_two_part_penalty_charges_the_fixed_sum_from_the_first_day(): void
    {
        // SEC Nigeria: ₦500,000 plus ₦5,000 a day. One day late is ₦505,000 —
        // with only the daily element modelled this understated it as ₦5,000.
        $exposure = $this->service->calculateExposure(
            $this->overdueInstance('per_day', 5_000_00, 1, 500_000_00)
        );

        $this->assertSame(505_000_00, $exposure->amount);
    }

    public function test_an_obligation_with_no_fixed_element_is_unchanged(): void
    {
        $exposure = $this->service->calculateExposure($this->overdueInstance('per_day', 500000, 30));

        $this->assertSame(15000000, $exposure->amount);
    }

    public function test_a_percentage_penalty_without_a_declared_base_is_zero_rather_than_invented(): void
    {
        $exposure = $this->service->calculateExposure($this->overdueInstance('percentage', 5000, 40));

        $this->assertTrue($exposure->isZero());
    }

    public function test_a_percentage_penalty_applies_the_rate_to_a_declared_base(): void
    {
        // 50% administrative penalty on a ₦1,000,000 filing fee.
        $exposure = $this->service->calculateExposure(
            $this->overdueInstance('percentage', 5000, 40),
            Money::of(100000000, 'NGN'),
        );

        $this->assertSame(50000000, $exposure->amount);
    }

    public function test_nothing_is_owed_before_the_deadline_passes(): void
    {
        $exposure = $this->service->calculateExposure($this->overdueInstance('per_day', 500000, 0));

        $this->assertTrue($exposure->isZero());
    }

    public function test_an_obligation_with_no_stated_penalty_reports_no_exposure(): void
    {
        $instance = $this->overdueInstance('per_day', 500000, 30);
        $instance->obligation->penalty_amount_minor = null;

        $this->assertNull($this->service->calculateExposure($instance));
    }

    public function test_the_grace_period_delays_the_start_of_exposure(): void
    {
        $instance = $this->overdueInstance('per_day', 500000, 5);
        $instance->obligation->grace_period_days = 10;
        $instance->status = 'Not Started';
        $instance->days_overdue = 0;

        $this->service->refreshInstance($instance);

        $this->assertSame(0, $instance->days_overdue);
        $this->assertSame('Not Started', $instance->status);
        $this->assertSame(0, $instance->penalty_exposure_minor);
    }
}
