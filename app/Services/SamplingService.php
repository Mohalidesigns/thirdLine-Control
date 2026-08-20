<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Sampling for continuous testing (12.3).
 *
 * The seed is the point. A regulator or an internal auditor asking "which
 * 40 items did you test in March, and why those?" must get the same 40
 * back, so every method here is a deterministic function of (population,
 * config, seed) and the seed is written onto the run.
 *
 * Methods:
 *   full        — the whole population; the default for automated testing
 *   random      — simple random selection of n
 *   stratified  — proportional allocation of n across the values of a field
 *   monetary    — monetary-unit (probability-proportional-to-size) sampling,
 *                 the method an auditor expects on a value population
 */
class SamplingService
{
    public const METHODS = ['full', 'random', 'stratified', 'monetary'];

    /**
     * @param  array<int, array<string, mixed>>  $population
     * @param  array<string, mixed>  $config
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     method: string,
     *     seed: int,
     *     population_size: int,
     *     sample_size: int,
     *     strata: array<string, int>,
     *     interval: float|null
     * }
     */
    public function draw(array $population, array $config = [], ?int $seed = null): array
    {
        $method = (string) ($config['method'] ?? 'full');

        if (! in_array($method, self::METHODS, true)) {
            throw ValidationException::withMessages([
                'sample_config' => "'{$method}' is not a supported sampling method.",
            ]);
        }

        $seed ??= $this->newSeed();
        $populationSize = count($population);

        $result = match ($method) {
            'random' => $this->random($population, (int) ($config['size'] ?? 25), $seed),
            'stratified' => $this->stratified(
                $population,
                (int) ($config['size'] ?? 25),
                (string) ($config['stratum_field'] ?? ''),
                $seed,
            ),
            'monetary' => $this->monetary(
                $population,
                (string) ($config['value_field'] ?? ''),
                (int) ($config['size'] ?? 25),
                $seed,
            ),
            default => ['rows' => $population, 'strata' => [], 'interval' => null],
        };

        return [
            'rows' => $result['rows'],
            'method' => $method,
            'seed' => $seed,
            'population_size' => $populationSize,
            'sample_size' => count($result['rows']),
            'strata' => $result['strata'] ?? [],
            'interval' => $result['interval'] ?? null,
        ];
    }

    /** A seed a human can read back off the run record. */
    public function newSeed(): int
    {
        return random_int(1, 2147483646);
    }

    /**
     * Simple random selection without replacement. Uses a seeded
     * Mersenne Twister so the same seed always yields the same indices,
     * independent of PHP's global random state.
     *
     * @param  array<int, array<string, mixed>>  $population
     * @return array{rows: array<int, array<string, mixed>>, strata: array<string, int>}
     */
    private function random(array $population, int $size, int $seed): array
    {
        if ($size <= 0 || $size >= count($population)) {
            return ['rows' => $population, 'strata' => []];
        }

        $indices = $this->shuffledIndices(count($population), $seed);
        $chosen = array_slice($indices, 0, $size);
        sort($chosen);

        return [
            'rows' => array_values(array_map(fn (int $i) => $population[$i], $chosen)),
            'strata' => [],
        ];
    }

    /**
     * Proportional allocation across the distinct values of a field, with
     * at least one item per non-empty stratum — a branch with three
     * transactions must not be invisible because it is small.
     *
     * @param  array<int, array<string, mixed>>  $population
     * @return array{rows: array<int, array<string, mixed>>, strata: array<string, int>}
     */
    private function stratified(array $population, int $size, string $field, int $seed): array
    {
        if ($field === '') {
            throw ValidationException::withMessages([
                'sample_config' => 'Stratified sampling needs a stratum_field.',
            ]);
        }

        if ($size <= 0 || $size >= count($population)) {
            return ['rows' => $population, 'strata' => []];
        }

        $groups = [];

        foreach ($population as $row) {
            $groups[(string) ($row[$field] ?? '(blank)')][] = $row;
        }

        ksort($groups);

        $total = count($population);
        $rows = [];
        $allocation = [];

        foreach ($groups as $key => $members) {
            $share = (int) max(1, round($size * count($members) / $total));
            $share = min($share, count($members));

            $indices = $this->shuffledIndices(count($members), $seed + crc32((string) $key));
            $chosen = array_slice($indices, 0, $share);
            sort($chosen);

            foreach ($chosen as $index) {
                $rows[] = $members[$index];
            }

            $allocation[(string) $key] = $share;
        }

        return ['rows' => $rows, 'strata' => $allocation];
    }

    /**
     * Monetary-unit sampling: pick every Nth naira (or kobo, or dollar)
     * from a random start, so a large item is proportionally more likely
     * to be selected and a single item above the interval is certain to be.
     *
     * @param  array<int, array<string, mixed>>  $population
     * @return array{rows: array<int, array<string, mixed>>, strata: array<string, int>, interval: float|null}
     */
    private function monetary(array $population, string $valueField, int $size, int $seed): array
    {
        if ($valueField === '') {
            throw ValidationException::withMessages([
                'sample_config' => 'Monetary-unit sampling needs a value_field.',
            ]);
        }

        $values = array_map(fn ($row) => abs((float) ($row[$valueField] ?? 0)), $population);
        $total = array_sum($values);

        if ($total <= 0 || $size <= 0) {
            return ['rows' => [], 'strata' => [], 'interval' => null];
        }

        $interval = $total / $size;

        mt_srand($seed);
        $start = (mt_rand(0, 999999) / 1000000) * $interval;
        mt_srand();

        $rows = [];
        $cumulative = 0.0;
        $target = $start;

        foreach ($population as $index => $row) {
            $cumulative += $values[$index];

            // A single item larger than the interval can span several
            // hits; it is selected once, which is the standard treatment.
            $selected = false;

            while ($target < $cumulative) {
                $selected = true;
                $target += $interval;
            }

            if ($selected) {
                $rows[] = $row;
            }
        }

        return ['rows' => $rows, 'strata' => [], 'interval' => round($interval, 6)];
    }

    /**
     * A deterministic Fisher–Yates shuffle of 0..n-1 driven by a seeded
     * linear congruential generator, so the result does not depend on the
     * PHP version's shuffle implementation.
     *
     * @return array<int, int>
     */
    private function shuffledIndices(int $count, int $seed): array
    {
        $indices = range(0, max(0, $count - 1));
        $state = ($seed % 2147483647) ?: 1;

        for ($i = $count - 1; $i > 0; $i--) {
            $state = (int) (($state * 16807) % 2147483647);
            $j = $state % ($i + 1);
            [$indices[$i], $indices[$j]] = [$indices[$j], $indices[$i]];
        }

        return $indices;
    }
}
