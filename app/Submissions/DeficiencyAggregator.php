<?php

namespace App\Submissions;

/**
 * The ICFR deficiency taxonomy and its aggregation rule (13.4c).
 *
 * Three tiers, in ascending seriousness: a control deficiency, a significant
 * deficiency, and a material weakness. The classification of a single finding
 * is mechanical. The part that is not mechanical — and the part auditors
 * actually argue about — is aggregation: several significant deficiencies in
 * the same area can together constitute a material weakness even though none
 * of them does alone.
 *
 * This class applies that escalation and then stops. It marks the area for
 * HUMAN REVIEW rather than restating the conclusion as fact, because whether
 * a cluster of deficiencies rises to a material weakness is a judgement the
 * management team signs its name to.
 */
final class DeficiencyAggregator
{
    public const CONTROL_DEFICIENCY = 'control_deficiency';

    public const SIGNIFICANT_DEFICIENCY = 'significant_deficiency';

    public const MATERIAL_WEAKNESS = 'material_weakness';

    public const TIERS = [self::CONTROL_DEFICIENCY, self::SIGNIFICANT_DEFICIENCY, self::MATERIAL_WEAKNESS];

    public const LABELS = [
        self::CONTROL_DEFICIENCY => 'Control deficiency',
        self::SIGNIFICANT_DEFICIENCY => 'Significant deficiency',
        self::MATERIAL_WEAKNESS => 'Material weakness',
    ];

    /**
     * @param  int  $escalationThreshold  significant deficiencies in one area that
     *                                    together warrant material-weakness review
     */
    public function __construct(private int $escalationThreshold = 2) {}

    /**
     * A single finding's tier. Severity carries most of it; a key control
     * over a significant account raises a middling finding a tier, because
     * the same failure matters more where the financial statements depend
     * on it.
     */
    public function classify(string $severity, bool $keyControl = false): string
    {
        return match ($severity) {
            'Critical' => self::MATERIAL_WEAKNESS,
            'High' => self::SIGNIFICANT_DEFICIENCY,
            'Medium' => $keyControl ? self::SIGNIFICANT_DEFICIENCY : self::CONTROL_DEFICIENCY,
            default => self::CONTROL_DEFICIENCY,
        };
    }

    /**
     * Apply the aggregation rule across classified deficiencies.
     *
     * @param  array<int, array<string, mixed>>  $deficiencies  each with 'area' and 'tier'
     * @return array{deficiencies: array<int, array<string, mixed>>, escalations: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function aggregate(array $deficiencies): array
    {
        $byArea = [];

        foreach ($deficiencies as $index => $deficiency) {
            $byArea[$deficiency['area'] ?? 'Unassigned'][] = $index;
        }

        $escalations = [];

        foreach ($byArea as $area => $indexes) {
            $significant = array_values(array_filter(
                $indexes,
                fn (int $index) => $deficiencies[$index]['tier'] === self::SIGNIFICANT_DEFICIENCY,
            ));

            if (count($significant) < $this->escalationThreshold) {
                continue;
            }

            foreach ($significant as $index) {
                $deficiencies[$index]['tier'] = self::MATERIAL_WEAKNESS;
                $deficiencies[$index]['escalated'] = true;
                $deficiencies[$index]['escalation_reason'] = sprintf(
                    '%d significant deficiencies in %s aggregate to a material weakness.',
                    count($significant),
                    $area,
                );
            }

            $escalations[] = [
                'area' => $area,
                'significant_count' => count($significant),
                'threshold' => $this->escalationThreshold,
                'from' => self::SIGNIFICANT_DEFICIENCY,
                'to' => self::MATERIAL_WEAKNESS,
                'requires_human_review' => true,
                'reason' => sprintf(
                    '%d significant deficiencies in %s meet the aggregation threshold of %d. '
                    .'Management must confirm whether they together constitute a material weakness.',
                    count($significant),
                    $area,
                    $this->escalationThreshold,
                ),
            ];
        }

        $counts = array_fill_keys(self::TIERS, 0);

        foreach ($deficiencies as $deficiency) {
            $counts[$deficiency['tier']]++;
        }

        return [
            'deficiencies' => array_values($deficiencies),
            'escalations' => $escalations,
            'counts' => $counts,
        ];
    }

    public function threshold(): int
    {
        return $this->escalationThreshold;
    }
}
