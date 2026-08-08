<?php

namespace Tests\Unit;

use App\Support\LossSimulator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LossSimulatorTest extends TestCase
{
    public function test_simulation_is_deterministic_under_a_seed(): void
    {
        $first = (new LossSimulator(4242))->run(1_000_00, 5_000_00, 40_000_00, 0.3);
        $second = (new LossSimulator(4242))->run(1_000_00, 5_000_00, 40_000_00, 0.3);

        $this->assertSame($first['expected_loss_minor'], $second['expected_loss_minor']);
        $this->assertSame($first['var_95_minor'], $second['var_95_minor']);
        $this->assertSame($first['var_99_minor'], $second['var_99_minor']);
        $this->assertSame($first['curve'], $second['curve']);
    }

    public function test_a_different_seed_produces_a_different_run(): void
    {
        $a = (new LossSimulator(1))->run(1_000_00, 5_000_00, 40_000_00, 0.3);
        $b = (new LossSimulator(2))->run(1_000_00, 5_000_00, 40_000_00, 0.3);

        $this->assertNotSame($a['var_99_minor'], $b['var_99_minor']);
    }

    public function test_it_uses_its_own_generator_and_never_the_global_one(): void
    {
        mt_srand(999);
        $before = mt_rand();

        mt_srand(999);
        (new LossSimulator(7))->run(0, 1_000_00, 5_000_00, 0.5, 'triangular', 500);
        $after = mt_rand();

        $this->assertSame($before, $after, 'The simulator must not disturb the global mt_rand state.');
    }

    public function test_var_99_is_at_least_var_95_and_expected_loss_sits_below_both(): void
    {
        $result = (new LossSimulator(20260809))->run(500_00, 5_000_00, 50_000_00, 0.4);

        $this->assertGreaterThanOrEqual($result['var_95_minor'], $result['var_99_minor']);
        $this->assertLessThanOrEqual($result['var_95_minor'], $result['expected_loss_minor']);
    }

    public function test_expected_loss_tracks_the_occurrence_probability(): void
    {
        $rare = (new LossSimulator(11))->run(1_000_00, 10_000_00, 20_000_00, 0.05);
        $certain = (new LossSimulator(11))->run(1_000_00, 10_000_00, 20_000_00, 1.0);

        $this->assertLessThan($certain['expected_loss_minor'], $rare['expected_loss_minor']);
        // With p = 1 every iteration is a loss, so expected loss ≈ mean severity.
        $this->assertEqualsWithDelta(
            $certain['mean_severity_minor'],
            $certain['expected_loss_minor'],
            1,
        );
    }

    public function test_both_distributions_stay_inside_the_min_max_envelope(): void
    {
        foreach (LossSimulator::DISTRIBUTIONS as $distribution) {
            $result = (new LossSimulator(5150))->run(2_000_00, 6_000_00, 9_000_00, 1.0, $distribution, 2000);

            $this->assertGreaterThanOrEqual(2_000_00, $result['expected_loss_minor'], $distribution);
            $this->assertLessThanOrEqual(9_000_00, $result['var_99_minor'], $distribution);
        }
    }

    public function test_it_rejects_inputs_that_are_not_ordered(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LossSimulator)->run(10_000_00, 5_000_00, 1_000_00, 0.5);
    }

    public function test_it_rejects_an_out_of_range_probability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LossSimulator)->run(0, 1, 2, 1.4);
    }
}
