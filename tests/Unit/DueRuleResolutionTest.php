<?php

namespace Tests\Unit;

use App\Models\OrganisationUnit;
use App\Models\PublicHoliday;
use App\Models\Regulator;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use App\Services\ObligationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Due-date resolution is the single most load-bearing calculation in the
 * obligation engine: get it wrong and the product tells a bank it has more
 * time than it does.
 */
class DueRuleResolutionTest extends TestCase
{
    use RefreshDatabase;

    private ObligationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ObligationService::class);
    }

    private function entity(array $overrides = []): OrganisationUnit
    {
        $tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'timezone' => 'Africa/Lagos']);

        return OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Head Office',
            'type' => 'Head Office',
            'jurisdiction' => 'NG',
            'entity_type' => 'commercial_bank',
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
            ...$overrides,
        ]);
    }

    private function obligation(array $dueRule, array $overrides = []): RegulatoryObligation
    {
        $regulator = Regulator::create(['code' => 'REG'.fake()->unique()->numberBetween(1, 9999), 'name' => 'Test Regulator', 'jurisdiction' => 'NG']);

        return RegulatoryObligation::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'regulator_id' => $regulator->id,
            'obligation_ref' => 'OB-'.fake()->unique()->numberBetween(1, 99999),
            'title' => 'Test obligation',
            'obligation_type' => 'filing',
            'trigger_type' => 'calendar',
            'frequency' => 'annual',
            'due_rule' => $dueRule,
            'verification_status' => 'unverified',
            ...$overrides,
        ]);
    }

    private function period(string $start, string $end): array
    {
        return [
            'label' => 'FY'.Carbon::parse($end)->year,
            'start' => Carbon::parse($start)->startOfDay(),
            'end' => Carbon::parse($end)->endOfDay(),
        ];
    }

    public function test_fixed_date_resolves_to_the_first_occurrence_after_the_period_it_reports_on(): void
    {
        $entity = $this->entity();
        // 31 March filing for a 31 December year end lands in the next year.
        $obligation = $this->obligation(['type' => 'fixed_date', 'month' => 3, 'day' => 31]);

        $due = $this->service->resolveDueDate($obligation, $entity, $this->period('2026-01-01', '2026-12-31'));

        $this->assertSame('2027-03-31', $due->setTimezone('Africa/Lagos')->toDateString());
    }

    public function test_fixed_date_without_a_month_resolves_monthly(): void
    {
        $entity = $this->entity();
        // "VAT by the 21st of the following month".
        $obligation = $this->obligation(['type' => 'fixed_date', 'day' => 21], ['frequency' => 'monthly']);

        $due = $this->service->resolveDueDate($obligation, $entity, $this->period('2026-01-01', '2026-01-31'));

        $this->assertSame('2026-02-23', $due->setTimezone('Africa/Lagos')->toDateString(),
            '21 February 2026 is a Saturday, so the deadline rolls to Monday the 23rd.');
    }

    public function test_a_fixed_date_that_does_not_exist_in_the_month_clamps_to_the_month_end(): void
    {
        $entity = $this->entity();
        $obligation = $this->obligation(['type' => 'fixed_date', 'month' => 2, 'day' => 31, 'roll' => 'none']);

        $due = $this->service->resolveDueDate($obligation, $entity, $this->period('2026-03-01', '2026-12-31'));

        $this->assertSame('2027-02-28', $due->setTimezone('Africa/Lagos')->toDateString());
    }

    public function test_a_leap_year_february_deadline_uses_the_29th(): void
    {
        $entity = $this->entity();
        $obligation = $this->obligation(['type' => 'fixed_date', 'month' => 2, 'day' => 31, 'roll' => 'none']);

        $due = $this->service->resolveDueDate($obligation, $entity, $this->period('2027-03-01', '2027-12-31'));

        $this->assertSame('2028-02-29', $due->setTimezone('Africa/Lagos')->toDateString(),
            '2028 is a leap year, so a month-end February deadline is the 29th.');
    }

    public function test_relative_rule_measures_from_the_fiscal_year_end(): void
    {
        $entity = $this->entity(['fiscal_year_end_month' => 6, 'fiscal_year_end_day' => 30]);
        $obligation = $this->obligation(['type' => 'relative', 'anchor' => 'fiscal_year_end', 'offset_days' => 60, 'roll' => 'none']);

        $due = $this->service->resolveDueDate($obligation, $entity, $this->period('2025-07-01', '2026-06-30'));

        $this->assertSame('2026-08-29', $due->setTimezone('Africa/Lagos')->toDateString());
    }

    public function test_relative_rule_measures_from_the_period_end_by_default(): void
    {
        $entity = $this->entity();
        $obligation = $this->obligation(['type' => 'relative', 'offset_days' => 30, 'roll' => 'none'], ['frequency' => 'quarterly']);

        $due = $this->service->resolveDueDate($obligation, $entity, $this->period('2026-01-01', '2026-03-31'));

        $this->assertSame('2026-04-30', $due->setTimezone('Africa/Lagos')->toDateString());
    }

    public function test_relative_rule_supports_a_month_offset(): void
    {
        $entity = $this->entity();
        $obligation = $this->obligation(['type' => 'relative', 'anchor' => 'fiscal_year_end', 'offset_months' => 3, 'roll' => 'none']);

        $due = $this->service->resolveDueDate($obligation, $entity, $this->period('2026-01-01', '2026-12-31'));

        $this->assertSame('2027-03-31', $due->setTimezone('Africa/Lagos')->toDateString());
    }

    public function test_event_relative_rule_is_an_exact_wall_clock_countdown(): void
    {
        $entity = $this->entity();
        $obligation = $this->obligation(
            ['type' => 'event_relative', 'event' => 'breach_detected', 'offset_hours' => 72],
            ['trigger_type' => 'event', 'frequency' => 'event_driven'],
        );

        // A breach detected late on a Friday is still due 72 hours later,
        // weekend or not — the clock does not roll to a business day.
        $breach = Carbon::parse('2026-08-07 22:00:00', 'Africa/Lagos');
        $due = $this->service->resolveDueDate($obligation, $entity, $this->period('2026-01-01', '2026-12-31'), $breach);

        $this->assertSame(
            '2026-08-10 22:00:00',
            $due->setTimezone('Africa/Lagos')->format('Y-m-d H:i:s'),
        );
    }

    public function test_event_relative_rule_returns_null_without_an_event_timestamp(): void
    {
        $entity = $this->entity();
        $obligation = $this->obligation(
            ['type' => 'event_relative', 'event' => 'breach_detected', 'offset_hours' => 72],
            ['trigger_type' => 'event', 'frequency' => 'event_driven'],
        );

        $this->assertNull($this->service->resolveDueDate($obligation, $entity, $this->period('2026-01-01', '2026-12-31')));
    }

    public function test_a_deadline_landing_on_a_weekend_rolls_to_the_next_business_day(): void
    {
        $entity = $this->entity();
        // 31 May 2026 is a Sunday.
        $obligation = $this->obligation(['type' => 'fixed_date', 'month' => 5, 'day' => 31]);

        $due = $this->service->resolveDueDate($obligation, $entity, $this->period('2025-06-01', '2026-04-30'));

        $this->assertSame('2026-06-01', $due->setTimezone('Africa/Lagos')->toDateString());
    }

    public function test_a_deadline_landing_on_a_nigerian_public_holiday_rolls_forward(): void
    {
        $entity = $this->entity();

        PublicHoliday::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'jurisdiction' => 'NG',
            'holiday_date' => '2000-10-01',
            'name' => 'Independence Day',
            'is_recurring' => true,
        ]);

        // 1 October 2026 is a Thursday and a public holiday.
        $obligation = $this->obligation(['type' => 'fixed_date', 'month' => 10, 'day' => 1]);

        $due = $this->service->resolveDueDate($obligation, $entity, $this->period('2025-11-01', '2026-09-30'));

        $this->assertSame('2026-10-02', $due->setTimezone('Africa/Lagos')->toDateString());
    }

    public function test_a_deadline_can_be_rolled_backwards_instead(): void
    {
        $entity = $this->entity();
        // 31 May 2026 is a Sunday; rolling back gives Friday 29 May.
        $obligation = $this->obligation(['type' => 'fixed_date', 'month' => 5, 'day' => 31, 'roll' => 'previous_business_day']);

        $due = $this->service->resolveDueDate($obligation, $entity, $this->period('2025-06-01', '2026-04-30'));

        $this->assertSame('2026-05-29', $due->setTimezone('Africa/Lagos')->toDateString());
    }

    public function test_fiscal_quarters_are_numbered_from_the_fiscal_year_start_not_from_january(): void
    {
        $entity = $this->entity(['fiscal_year_end_month' => 3, 'fiscal_year_end_day' => 31]);
        $obligation = $this->obligation(['type' => 'relative', 'offset_days' => 30], ['frequency' => 'quarterly']);

        $periods = $this->service->periods(
            $obligation, $entity,
            Carbon::parse('2026-04-01'), Carbon::parse('2027-03-31'),
        );

        $this->assertSame('Q1 FY2027', $periods[0]['label']);
        $this->assertSame('2026-04-01', $periods[0]['start']->toDateString());
        $this->assertSame('2026-06-30', $periods[0]['end']->toDateString());
    }

    public function test_an_unknown_rule_type_resolves_to_nothing_rather_than_guessing(): void
    {
        $entity = $this->entity();
        $obligation = $this->obligation(['type' => 'whenever']);

        $this->assertNull($this->service->resolveDueDate($obligation, $entity, $this->period('2026-01-01', '2026-12-31')));
    }
}
