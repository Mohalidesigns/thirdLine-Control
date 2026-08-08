<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Monte Carlo loss simulation for quantitative risk (10.1). Pure PHP, no
 * external service, and deterministic: the generator is a self-contained
 * xorshift32 seeded per run, so the same inputs always produce the same
 * VaR — the global mt_rand state is never touched and never read.
 *
 * Each iteration draws occurrence (Bernoulli p) then severity (triangular
 * or PERT over min / most-likely / max). Amounts are integer minor units
 * throughout (R7); no float ever holds a money value that is persisted.
 */
final class LossSimulator
{
    public const ITERATIONS = 10_000;

    public const DISTRIBUTIONS = ['triangular', 'pert'];

    /** Exceedance probabilities plotted on the loss-exceedance curve. */
    private const CURVE_POINTS = [0.99, 0.95, 0.90, 0.75, 0.50, 0.25, 0.10, 0.05, 0.01];

    private int $state;

    public function __construct(private readonly int $seed = 20260809)
    {
        $this->state = $seed & 0xFFFFFFFF ?: 0x9E3779B9;
    }

    /**
     * @param  int  $minMinor  best-case severity, minor units
     * @param  int  $likelyMinor  most-likely severity, minor units
     * @param  int  $maxMinor  worst-case severity, minor units
     * @param  float  $occurrenceProbability  0..1 chance the risk materialises in the period
     * @return array{
     *     iterations: int, seed: int, distribution: string,
     *     expected_loss_minor: int, mean_severity_minor: int,
     *     var_95_minor: int, var_99_minor: int,
     *     curve: array<int, array{exceedance: float, loss_minor: int}>
     * }
     */
    public function run(
        int $minMinor,
        int $likelyMinor,
        int $maxMinor,
        float $occurrenceProbability,
        string $distribution = 'pert',
        int $iterations = self::ITERATIONS,
    ): array {
        if (! in_array($distribution, self::DISTRIBUTIONS, true)) {
            throw new InvalidArgumentException("Unknown distribution '{$distribution}'.");
        }

        if ($minMinor > $likelyMinor || $likelyMinor > $maxMinor) {
            throw new InvalidArgumentException('Loss inputs must satisfy min ≤ most likely ≤ max.');
        }

        if ($occurrenceProbability < 0 || $occurrenceProbability > 1) {
            throw new InvalidArgumentException('Occurrence probability must be between 0 and 1.');
        }

        $iterations = max(100, min(100_000, $iterations));

        $losses = [];
        $severityTotal = 0.0;
        $lossTotal = 0.0;

        for ($i = 0; $i < $iterations; $i++) {
            $severity = $distribution === 'pert'
                ? $this->pert($minMinor, $likelyMinor, $maxMinor)
                : $this->triangular($minMinor, $likelyMinor, $maxMinor);

            $severityTotal += $severity;

            $loss = $this->uniform() < $occurrenceProbability ? $severity : 0.0;
            $lossTotal += $loss;
            $losses[] = $loss;
        }

        sort($losses);

        return [
            'iterations' => $iterations,
            'seed' => $this->seed,
            'distribution' => $distribution,
            'expected_loss_minor' => (int) round($lossTotal / $iterations),
            'mean_severity_minor' => (int) round($severityTotal / $iterations),
            'var_95_minor' => (int) round($this->percentile($losses, 0.95)),
            'var_99_minor' => (int) round($this->percentile($losses, 0.99)),
            'curve' => array_map(fn (float $p) => [
                'exceedance' => $p,
                'loss_minor' => (int) round($this->percentile($losses, 1 - $p)),
            ], self::CURVE_POINTS),
        ];
    }

    /** Inverse-CDF triangular sample — exact, no rejection loop. */
    private function triangular(float $a, float $c, float $b): float
    {
        if ($b - $a < PHP_FLOAT_EPSILON) {
            return $a;
        }

        $u = $this->uniform();
        $split = ($c - $a) / ($b - $a);

        return $u < $split
            ? $a + sqrt($u * ($b - $a) * ($c - $a))
            : $b - sqrt((1 - $u) * ($b - $a) * ($b - $c));
    }

    /**
     * PERT: a Beta(α, β) over [min, max] with the classic λ = 4 weighting
     * on the most-likely value, so the mode dominates without excluding
     * the tails the way a triangular does.
     */
    private function pert(float $a, float $c, float $b): float
    {
        $range = $b - $a;

        if ($range < PHP_FLOAT_EPSILON) {
            return $a;
        }

        $alpha = 1 + 4 * ($c - $a) / $range;
        $beta = 1 + 4 * ($b - $c) / $range;

        return $a + $this->beta($alpha, $beta) * $range;
    }

    private function beta(float $alpha, float $beta): float
    {
        $x = $this->gamma($alpha);
        $y = $this->gamma($beta);
        $sum = $x + $y;

        return $sum < PHP_FLOAT_EPSILON ? 0.5 : $x / $sum;
    }

    /** Marsaglia–Tsang gamma sampler, with the shape < 1 boost. */
    private function gamma(float $shape): float
    {
        if ($shape < 1) {
            $u = max($this->uniform(), PHP_FLOAT_EPSILON);

            return $this->gamma($shape + 1) * $u ** (1 / $shape);
        }

        $d = $shape - 1 / 3;
        $c = 1 / sqrt(9 * $d);

        for ($guard = 0; $guard < 1000; $guard++) {
            do {
                $x = $this->normal();
                $v = 1 + $c * $x;
            } while ($v <= 0);

            $v = $v ** 3;
            $u = max($this->uniform(), PHP_FLOAT_EPSILON);

            if (log($u) < 0.5 * $x ** 2 + $d - $d * $v + $d * log($v)) {
                return $d * $v;
            }
        }

        return $d;
    }

    /** Box–Muller standard normal. */
    private function normal(): float
    {
        $u1 = max($this->uniform(), PHP_FLOAT_EPSILON);
        $u2 = $this->uniform();

        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    /** xorshift32 — exact 32-bit integer arithmetic, no global state. */
    private function uniform(): float
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;

        return $this->state / 4294967296.0;
    }

    /** @param array<int, float> $sorted */
    private function percentile(array $sorted, float $q): float
    {
        $count = count($sorted);

        if ($count === 0) {
            return 0.0;
        }

        $index = (int) floor($q * ($count - 1));

        return $sorted[max(0, min($count - 1, $index))];
    }
}
