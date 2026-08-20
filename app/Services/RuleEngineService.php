<?php

namespace App\Services;

use App\Models\DataSourceDataset;
use App\Models\MonitoringRule;
use App\Support\SqlGuard;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PDO;

/**
 * The rule engine (12.3).
 *
 * Eleven rule types, each a pure function of (rows, definition) returning
 * evaluated / passed / failed and a list of findings. No type reaches out
 * to the database, writes anything, or knows what a control is — that is
 * MonitoringService's job. Keeping the engine pure is what makes the
 * fixture tests meaningful.
 *
 * Every definition is validated against its type's schema before a rule
 * can be saved, so a malformed rule fails at authoring time rather than
 * at 03:00 on a Sunday.
 */
class RuleEngineService
{
    /** Comparison operators available to threshold and exception_list rules. */
    public const OPERATORS = [
        'gt', 'gte', 'lt', 'lte', 'eq', 'neq', 'between', 'in', 'not_in',
        'contains', 'not_contains', 'starts_with', 'is_null', 'is_not_null', 'regex',
    ];

    public const AGGREGATES = ['sum', 'avg', 'count', 'max', 'min', 'count_distinct'];

    public const PATTERNS = ['benford', 'round_number', 'off_hours', 'same_day_maker_checker'];

    public function __construct(private SamplingService $sampling) {}

    // ── Definition schemas ───────────────────────────────────────────

    /**
     * The required keys per rule type. Published to the UI so the rule
     * builder is generated from the same source that validates (R1).
     *
     * @return array<string, array{required: array<int, string>, optional: array<int, string>, description: string}>
     */
    public static function schemas(): array
    {
        return [
            'threshold' => [
                'required' => ['field', 'aggregate', 'operator', 'value'],
                'optional' => ['dataset', 'value_to', 'group_by'],
                'description' => 'Aggregate a field over the population (optionally per group) and compare it to a limit.',
            ],
            'reconciliation' => [
                'required' => ['left_dataset', 'right_dataset', 'key_fields'],
                'optional' => ['compare_fields', 'tolerance', 'direction'],
                'description' => 'Match two datasets on a key and report unmatched items and value differences beyond tolerance.',
            ],
            'duplicate' => [
                'required' => ['key_fields'],
                'optional' => ['dataset', 'normalise', 'min_occurrences'],
                'description' => 'Report records sharing the same key, optionally after normalisation (case, spacing, digits only).',
            ],
            'gap' => [
                'required' => ['mode', 'field'],
                'optional' => ['dataset', 'step', 'start', 'end', 'group_by', 'skip_weekends'],
                'description' => 'Report missing numbers in a sequence, or missing days in a date series.',
            ],
            'sod_conflict' => [
                'required' => ['subject_field', 'function_field'],
                'optional' => ['dataset', 'name_field', 'entity_field', 'system_key'],
                'description' => 'Evaluate an entitlement extract against the toxic-combination matrix.',
            ],
            'exception_list' => [
                'required' => ['conditions'],
                'optional' => ['dataset', 'match', 'identifier_field'],
                'description' => 'Every row matching the filter is an exception.',
            ],
            'completeness' => [
                'required' => [],
                'optional' => ['dataset', 'required_fields', 'expected_row_count', 'min_row_count', 'tolerance_rows'],
                'description' => 'Required fields populated, and the row count within the expected range.',
            ],
            'timeliness' => [
                'required' => ['event_field', 'max_hours'],
                'optional' => ['dataset', 'reference_field', 'identifier_field'],
                'description' => 'Records must occur within an expected latency of a reference moment.',
            ],
            'trend' => [
                'required' => ['field', 'period_field'],
                'optional' => ['dataset', 'aggregate', 'tolerance_percentage', 'direction'],
                'description' => 'Period-over-period movement beyond a tolerance.',
            ],
            'pattern' => [
                'required' => ['pattern'],
                'optional' => [
                    'dataset', 'field', 'timestamp_field', 'maker_field', 'checker_field',
                    'approved_at_field', 'created_at_field', 'start_hour', 'end_hour',
                    'identifier_field', 'tolerance_percentage', 'round_to',
                ],
                'description' => 'Benford’s law, round-number bias, off-hours activity, or same-day create-and-approve.',
            ],
            'custom_sql' => [
                'required' => ['query'],
                'optional' => ['params', 'identifier_column', 'reason_column'],
                'description' => 'A parameterised read-only SELECT run against a throwaway copy of the snapshot.',
            ],
        ];
    }

    /**
     * Validate a definition before a rule is saved. Throws with the field
     * key the rule builder binds errors to.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<int, int>  $datasetIds
     */
    public function validateDefinition(string $type, array $definition, array $datasetIds): void
    {
        $schema = self::schemas()[$type] ?? null;

        if ($schema === null) {
            throw ValidationException::withMessages(['rule_type' => "'{$type}' is not a known rule type."]);
        }

        $missing = array_values(array_filter(
            $schema['required'],
            fn (string $key) => ! array_key_exists($key, $definition)
                || $definition[$key] === null
                || $definition[$key] === '',
        ));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'rule_definition' => 'Missing for a '.$type.' rule: '.implode(', ', $missing).'.',
            ]);
        }

        if ($datasetIds === []) {
            throw ValidationException::withMessages([
                'dataset_ids' => 'A monitoring rule must read from at least one dataset.',
            ]);
        }

        match ($type) {
            'threshold' => $this->validateThreshold($definition),
            'reconciliation' => $this->validateReconciliation($definition, $datasetIds),
            'gap' => $this->validateGap($definition),
            'exception_list' => $this->validateConditions($definition['conditions'] ?? []),
            'pattern' => $this->validatePattern($definition),
            'custom_sql' => $this->validateCustomSql($definition, $datasetIds),
            default => null,
        };
    }

    // ── Evaluation ───────────────────────────────────────────────────

    /**
     * Run a rule against loaded rows.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $rowsByDataset  dataset id => rows
     * @param  array<int, DataSourceDataset>  $datasets  dataset id => model
     * @return array{
     *     evaluated: int, passed: int, failed: int,
     *     findings: array<int, array<string, mixed>>,
     *     summary: array<string, mixed>,
     *     seed: int|null
     * }
     */
    public function evaluate(MonitoringRule $rule, array $rowsByDataset, array $datasets = []): array
    {
        $definition = (array) ($rule->rule_definition ?? []);
        $primaryId = $this->primaryDatasetId($rule, $definition);
        $rows = $rowsByDataset[$primaryId] ?? [];
        $seed = null;

        // Sampling applies to the row-level types only; an aggregate or a
        // reconciliation over a sample would be arithmetic nonsense.
        if ($this->isSampleable($rule->rule_type) && ! empty($rule->sample_config['method'])
            && $rule->sample_config['method'] !== 'full') {
            $drawn = $this->sampling->draw($rows, (array) $rule->sample_config);
            $rows = $drawn['rows'];
            $seed = $drawn['seed'];
        }

        $result = match ($rule->rule_type) {
            'threshold' => $this->threshold($rows, $definition),
            'reconciliation' => $this->reconciliation($rowsByDataset, $definition),
            'duplicate' => $this->duplicate($rows, $definition),
            'gap' => $this->gap($rows, $definition),
            'sod_conflict' => $this->sodConflict($rows, $definition),
            'exception_list' => $this->exceptionList($rows, $definition),
            'completeness' => $this->completeness($rows, $definition),
            'timeliness' => $this->timeliness($rows, $definition),
            'trend' => $this->trend($rows, $definition),
            'pattern' => $this->pattern($rows, $definition),
            'custom_sql' => $this->customSql($rowsByDataset, $datasets, $definition),
            default => throw new InvalidArgumentException("Unsupported rule type {$rule->rule_type}."),
        };

        $findings = array_slice($result['findings'], 0, (int) config('monitoring.max_findings_per_run'));
        $truncated = count($result['findings']) > count($findings);

        return [
            'evaluated' => $result['evaluated'],
            'passed' => max(0, $result['evaluated'] - $result['failed']),
            'failed' => $result['failed'],
            'findings' => $findings,
            'summary' => [
                ...$result['summary'],
                'findings_truncated' => $truncated,
                'findings_total' => count($result['findings']),
            ],
            'seed' => $seed,
        ];
    }

    /** Types where testing a sample rather than the population is meaningful. */
    public function isSampleable(string $type): bool
    {
        return in_array($type, ['exception_list', 'timeliness', 'pattern', 'duplicate', 'sod_conflict'], true);
    }

    // ── Rule types ───────────────────────────────────────────────────

    /**
     * threshold — an aggregate against a limit, optionally per group.
     *
     * The operator states the ACCEPTABLE condition, so `sum lte 5000000`
     * reads "the total must stay at or below ₦5m" and a finding is raised
     * when it does not. Stating the acceptable side keeps the rule text
     * and the control objective in the same voice.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function threshold(array $rows, array $definition): array
    {
        $field = (string) $definition['field'];
        $aggregate = (string) $definition['aggregate'];
        $groupBy = $definition['group_by'] ?? null;
        $findings = [];

        $groups = $groupBy
            ? $this->groupBy($rows, (string) $groupBy)
            : ['(population)' => $rows];

        foreach ($groups as $key => $members) {
            $value = $this->aggregate($members, $field, $aggregate);
            $breached = ! $this->compare($value, $definition['operator'], $definition['value'] ?? null, $definition['value_to'] ?? null);

            if ($breached) {
                $findings[] = [
                    'record_identifier' => (string) $key,
                    'record_data' => ['group' => $key, 'aggregate' => $aggregate, 'value' => $value, 'rows' => count($members)],
                    'failure_reason' => sprintf(
                        '%s(%s) for %s is %s, outside the acceptable condition %s.',
                        strtoupper($aggregate), $field, $key, $this->number($value),
                        $this->limitLabel($definition),
                    ),
                ];
            }
        }

        return [
            'evaluated' => count($groups),
            'failed' => count($findings),
            'findings' => $findings,
            'summary' => ['groups' => count($groups), 'aggregate' => $aggregate, 'field' => $field],
        ];
    }

    /**
     * reconciliation — two datasets matched on a key, with tolerance.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $rowsByDataset
     */
    private function reconciliation(array $rowsByDataset, array $definition): array
    {
        $left = $rowsByDataset[(int) $definition['left_dataset']] ?? [];
        $right = $rowsByDataset[(int) $definition['right_dataset']] ?? [];
        $keyFields = (array) $definition['key_fields'];
        $compare = (array) ($definition['compare_fields'] ?? []);
        $absolute = (float) ($definition['tolerance']['absolute'] ?? 0);
        $percentage = (float) ($definition['tolerance']['percentage'] ?? 0);
        $direction = (string) ($definition['direction'] ?? 'both');

        $leftIndex = $this->indexByKey($left, $keyFields);
        $rightIndex = $this->indexByKey($right, $keyFields);
        $findings = [];

        foreach ($leftIndex as $key => $row) {
            if (! isset($rightIndex[$key])) {
                if ($direction !== 'right_to_left') {
                    $findings[] = [
                        'record_identifier' => (string) $key,
                        'record_data' => ['side' => 'left_only', 'row' => $row],
                        'failure_reason' => "Present in the first dataset but not the second (key {$key}).",
                    ];
                }

                continue;
            }

            foreach ($compare as $pair) {
                $leftField = (string) ($pair['left'] ?? $pair);
                $rightField = (string) ($pair['right'] ?? $pair);
                $leftValue = (float) ($row[$leftField] ?? 0);
                $rightValue = (float) ($rightIndex[$key][$rightField] ?? 0);
                $difference = abs($leftValue - $rightValue);
                $allowed = max($absolute, abs($leftValue) * $percentage / 100);

                if ($difference > $allowed) {
                    $findings[] = [
                        'record_identifier' => (string) $key,
                        'record_data' => [
                            'field' => $leftField,
                            'left' => $leftValue,
                            'right' => $rightValue,
                            'difference' => round($difference, 6),
                            'tolerance' => round($allowed, 6),
                        ],
                        'failure_reason' => sprintf(
                            '%s differs by %s (%s vs %s), outside the tolerance of %s.',
                            $leftField, $this->number($difference), $this->number($leftValue),
                            $this->number($rightValue), $this->number($allowed),
                        ),
                    ];
                }
            }
        }

        if ($direction !== 'left_to_right') {
            foreach ($rightIndex as $key => $row) {
                if (! isset($leftIndex[$key])) {
                    $findings[] = [
                        'record_identifier' => (string) $key,
                        'record_data' => ['side' => 'right_only', 'row' => $row],
                        'failure_reason' => "Present in the second dataset but not the first (key {$key}).",
                    ];
                }
            }
        }

        $evaluated = count(array_unique([...array_keys($leftIndex), ...array_keys($rightIndex)]));

        return [
            'evaluated' => $evaluated,
            'failed' => count($findings),
            'findings' => $findings,
            'summary' => [
                'left_rows' => count($left),
                'right_rows' => count($right),
                'matched_keys' => count(array_intersect_key($leftIndex, $rightIndex)),
            ],
        ];
    }

    /**
     * duplicate — exact or normalised key duplication.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function duplicate(array $rows, array $definition): array
    {
        $keyFields = (array) $definition['key_fields'];
        $normalise = (array) ($definition['normalise'] ?? []);
        $minimum = max(2, (int) ($definition['min_occurrences'] ?? 2));

        $groups = [];

        foreach ($rows as $row) {
            $key = implode('|', array_map(
                fn (string $field) => $this->normalise((string) ($row[$field] ?? ''), $normalise),
                $keyFields,
            ));

            $groups[$key][] = $row;
        }

        $findings = [];
        $failed = 0;

        foreach ($groups as $key => $members) {
            if (count($members) < $minimum) {
                continue;
            }

            $failed += count($members);
            $findings[] = [
                'record_identifier' => (string) $key,
                'record_data' => ['occurrences' => count($members), 'sample' => array_slice($members, 0, 5)],
                'failure_reason' => count($members).' records share the key '.$key.'.',
            ];
        }

        return [
            'evaluated' => count($rows),
            'failed' => $failed,
            'findings' => $findings,
            'summary' => ['distinct_keys' => count($groups), 'duplicate_groups' => count($findings)],
        ];
    }

    /**
     * gap — missing sequence numbers or missing days.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function gap(array $rows, array $definition): array
    {
        $mode = (string) $definition['mode'];
        $field = (string) $definition['field'];
        $groupBy = $definition['group_by'] ?? null;
        $groups = $groupBy ? $this->groupBy($rows, (string) $groupBy) : ['(all)' => $rows];
        $findings = [];

        foreach ($groups as $groupKey => $members) {
            $values = array_values(array_filter(array_map(
                fn ($row) => $row[$field] ?? null,
                $members,
            ), fn ($v) => $v !== null && $v !== ''));

            if ($values === []) {
                continue;
            }

            $missing = $mode === 'date'
                ? $this->missingDates($values, $definition)
                : $this->missingNumbers($values, $definition);

            foreach ($missing as $gap) {
                $findings[] = [
                    'record_identifier' => $groupBy ? "{$groupKey}:{$gap}" : (string) $gap,
                    'record_data' => ['group' => $groupKey, 'missing' => $gap, 'field' => $field],
                    'failure_reason' => $mode === 'date'
                        ? "No {$field} record for {$gap}".($groupBy ? " in {$groupKey}" : '').'.'
                        : "Sequence number {$gap} is missing from {$field}".($groupBy ? " in {$groupKey}" : '').'.',
                ];
            }
        }

        return [
            'evaluated' => count($rows),
            'failed' => count($findings),
            'findings' => $findings,
            'summary' => ['mode' => $mode, 'groups' => count($groups), 'gaps' => count($findings)],
        ];
    }

    /**
     * sod_conflict — the row-shaping half. The toxic-combination matrix
     * itself lives in sod_conflict_rules and is applied by SodService;
     * here we only reduce the extract to subject → functions.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sodConflict(array $rows, array $definition): array
    {
        $subjectField = (string) $definition['subject_field'];
        $functionField = (string) $definition['function_field'];
        $nameField = $definition['name_field'] ?? null;
        $entityField = $definition['entity_field'] ?? null;

        $subjects = [];

        foreach ($rows as $row) {
            $subject = (string) ($row[$subjectField] ?? '');

            if ($subject === '') {
                continue;
            }

            $subjects[$subject]['functions'][] = (string) ($row[$functionField] ?? '');
            $subjects[$subject]['name'] ??= $nameField ? (string) ($row[$nameField] ?? '') : null;
            $subjects[$subject]['entity'] ??= $entityField ? (string) ($row[$entityField] ?? '') : null;
        }

        $conflicts = app(SodService::class)->evaluate(
            $subjects,
            $definition['system_key'] ?? null,
        );

        return [
            'evaluated' => count($subjects),
            'failed' => count($conflicts),
            'findings' => $conflicts,
            'summary' => ['subjects' => count($subjects), 'conflicts' => count($conflicts)],
        ];
    }

    /**
     * exception_list — rows matching a filter are exceptions.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function exceptionList(array $rows, array $definition): array
    {
        $conditions = (array) $definition['conditions'];
        $matchAll = (string) ($definition['match'] ?? 'all') === 'all';
        $identifier = $definition['identifier_field'] ?? null;
        $findings = [];

        foreach ($rows as $index => $row) {
            $results = array_map(
                fn (array $condition) => $this->compare(
                    $row[$condition['field']] ?? null,
                    $condition['operator'],
                    $condition['value'] ?? null,
                    $condition['value_to'] ?? null,
                ),
                $conditions,
            );

            $matched = $matchAll ? ! in_array(false, $results, true) : in_array(true, $results, true);

            if (! $matched) {
                continue;
            }

            $findings[] = [
                'record_identifier' => (string) ($identifier ? ($row[$identifier] ?? $index) : $index),
                'record_data' => $row,
                'failure_reason' => 'Matches the exception filter: '.$this->describeConditions($conditions, $matchAll).'.',
            ];
        }

        return [
            'evaluated' => count($rows),
            'failed' => count($findings),
            'findings' => $findings,
            'summary' => ['conditions' => count($conditions), 'match' => $matchAll ? 'all' : 'any'],
        ];
    }

    /**
     * completeness — required fields populated, row count as expected.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function completeness(array $rows, array $definition): array
    {
        $required = (array) ($definition['required_fields'] ?? []);
        $findings = [];

        foreach ($rows as $index => $row) {
            $blank = array_values(array_filter(
                $required,
                fn (string $field) => ! array_key_exists($field, $row)
                    || $row[$field] === null
                    || trim((string) $row[$field]) === '',
            ));

            if ($blank !== []) {
                $findings[] = [
                    'record_identifier' => (string) $index,
                    'record_data' => ['missing_fields' => $blank, 'row' => $row],
                    'failure_reason' => 'Required field(s) not populated: '.implode(', ', $blank).'.',
                ];
            }
        }

        $expected = $definition['expected_row_count'] ?? null;
        $minimum = $definition['min_row_count'] ?? null;
        $toleranceRows = (int) ($definition['tolerance_rows'] ?? 0);

        if ($expected !== null && abs(count($rows) - (int) $expected) > $toleranceRows) {
            $findings[] = [
                'record_identifier' => 'row_count',
                'record_data' => ['expected' => (int) $expected, 'actual' => count($rows)],
                'failure_reason' => sprintf(
                    'The extract holds %d row(s) against an expected %d (tolerance %d).',
                    count($rows), (int) $expected, $toleranceRows,
                ),
            ];
        }

        if ($minimum !== null && count($rows) < (int) $minimum) {
            $findings[] = [
                'record_identifier' => 'row_count_minimum',
                'record_data' => ['minimum' => (int) $minimum, 'actual' => count($rows)],
                'failure_reason' => sprintf(
                    'The extract holds %d row(s), below the minimum of %d — the feed may be broken.',
                    count($rows), (int) $minimum,
                ),
            ];
        }

        return [
            'evaluated' => max(count($rows), 1),
            'failed' => count($findings),
            'findings' => $findings,
            'summary' => ['required_fields' => count($required), 'row_count' => count($rows)],
        ];
    }

    /**
     * timeliness — records within an expected latency.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function timeliness(array $rows, array $definition): array
    {
        $eventField = (string) $definition['event_field'];
        $referenceField = $definition['reference_field'] ?? null;
        $maxHours = (float) $definition['max_hours'];
        $identifier = $definition['identifier_field'] ?? null;
        $findings = [];
        $evaluated = 0;

        foreach ($rows as $index => $row) {
            $event = $this->toTimestamp($row[$eventField] ?? null);

            if ($event === null) {
                continue;
            }

            $reference = $referenceField
                ? $this->toTimestamp($row[$referenceField] ?? null)
                : now()->getTimestamp();

            if ($reference === null) {
                continue;
            }

            $evaluated++;
            $hours = ($reference - $event) / 3600;

            if ($hours > $maxHours) {
                $findings[] = [
                    'record_identifier' => (string) ($identifier ? ($row[$identifier] ?? $index) : $index),
                    'record_data' => ['hours_elapsed' => round($hours, 2), 'max_hours' => $maxHours, 'row' => $row],
                    'failure_reason' => sprintf(
                        'Took %.1f hour(s) against a limit of %.1f.',
                        $hours, $maxHours,
                    ),
                ];
            }
        }

        return [
            'evaluated' => $evaluated,
            'failed' => count($findings),
            'findings' => $findings,
            'summary' => ['max_hours' => $maxHours, 'evaluated' => $evaluated],
        ];
    }

    /**
     * trend — period-over-period movement beyond tolerance.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function trend(array $rows, array $definition): array
    {
        $field = (string) $definition['field'];
        $periodField = (string) $definition['period_field'];
        $aggregate = (string) ($definition['aggregate'] ?? 'sum');
        $tolerance = (float) ($definition['tolerance_percentage'] ?? 20);
        $direction = (string) ($definition['direction'] ?? 'both');

        $periods = $this->groupBy($rows, $periodField);
        ksort($periods);

        $series = [];

        foreach ($periods as $period => $members) {
            $series[(string) $period] = $this->aggregate($members, $field, $aggregate);
        }

        $findings = [];
        $labels = array_keys($series);

        for ($i = 1; $i < count($labels); $i++) {
            $previous = $series[$labels[$i - 1]];
            $current = $series[$labels[$i]];

            if ($previous == 0.0) {
                continue;
            }

            $movement = ($current - $previous) / abs($previous) * 100;

            $breached = match ($direction) {
                'increase' => $movement > $tolerance,
                'decrease' => $movement < -$tolerance,
                default => abs($movement) > $tolerance,
            };

            if ($breached) {
                $findings[] = [
                    'record_identifier' => $labels[$i],
                    'record_data' => [
                        'period' => $labels[$i],
                        'previous_period' => $labels[$i - 1],
                        'previous' => $previous,
                        'current' => $current,
                        'movement_percentage' => round($movement, 2),
                    ],
                    'failure_reason' => sprintf(
                        '%s(%s) moved %.1f%% from %s to %s, beyond the %.1f%% tolerance.',
                        strtoupper($aggregate), $field, $movement, $labels[$i - 1], $labels[$i], $tolerance,
                    ),
                ];
            }
        }

        return [
            'evaluated' => max(0, count($labels) - 1),
            'failed' => count($findings),
            'findings' => $findings,
            'summary' => ['periods' => count($labels), 'series' => $series],
        ];
    }

    /**
     * pattern — the forensic tests.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function pattern(array $rows, array $definition): array
    {
        return match ((string) $definition['pattern']) {
            'benford' => $this->benford($rows, $definition),
            'round_number' => $this->roundNumber($rows, $definition),
            'off_hours' => $this->offHours($rows, $definition),
            'same_day_maker_checker' => $this->sameDayMakerChecker($rows, $definition),
            default => throw new InvalidArgumentException('Unknown pattern test.'),
        };
    }

    /**
     * Benford's law on the leading digit. A finding per digit whose share
     * deviates beyond tolerance — this test flags a population for a human
     * to look at, it never concludes fraud.
     */
    private function benford(array $rows, array $definition): array
    {
        $field = (string) ($definition['field'] ?? 'amount');
        $tolerance = (float) ($definition['tolerance_percentage'] ?? 3);

        $counts = array_fill(1, 9, 0);
        $total = 0;

        foreach ($rows as $row) {
            $value = abs((float) ($row[$field] ?? 0));

            if ($value < 1) {
                continue;
            }

            $digit = (int) substr((string) (int) $value, 0, 1);

            if ($digit >= 1 && $digit <= 9) {
                $counts[$digit]++;
                $total++;
            }
        }

        $findings = [];
        $observed = [];

        if ($total >= 100) {
            foreach ($counts as $digit => $count) {
                $expectedShare = log10(1 + 1 / $digit) * 100;
                $actualShare = $count / $total * 100;
                $observed[$digit] = ['expected' => round($expectedShare, 2), 'actual' => round($actualShare, 2)];

                if (abs($actualShare - $expectedShare) > $tolerance) {
                    $findings[] = [
                        'record_identifier' => 'leading_digit_'.$digit,
                        'record_data' => ['digit' => $digit, 'expected_percentage' => round($expectedShare, 2), 'actual_percentage' => round($actualShare, 2), 'count' => $count],
                        'failure_reason' => sprintf(
                            'Leading digit %d appears in %.2f%% of %d values against Benford’s %.2f%%.',
                            $digit, $actualShare, $total, $expectedShare,
                        ),
                    ];
                }
            }
        }

        return [
            'evaluated' => $total,
            'failed' => count($findings),
            'findings' => $findings,
            'summary' => [
                'population' => $total,
                'distribution' => $observed,
                'note' => $total < 100 ? 'Fewer than 100 values — Benford was not applied.' : null,
            ],
        ];
    }

    private function roundNumber(array $rows, array $definition): array
    {
        $field = (string) ($definition['field'] ?? 'amount');
        $roundTo = (float) ($definition['round_to'] ?? 100000);
        $identifier = $definition['identifier_field'] ?? null;
        $findings = [];
        $evaluated = 0;

        foreach ($rows as $index => $row) {
            $value = abs((float) ($row[$field] ?? 0));

            if ($value <= 0) {
                continue;
            }

            $evaluated++;

            if (fmod($value, $roundTo) == 0.0) {
                $findings[] = [
                    'record_identifier' => (string) ($identifier ? ($row[$identifier] ?? $index) : $index),
                    'record_data' => ['value' => $value, 'round_to' => $roundTo, 'row' => $row],
                    'failure_reason' => sprintf('%s is an exact multiple of %s.', $this->number($value), $this->number($roundTo)),
                ];
            }
        }

        return [
            'evaluated' => $evaluated,
            'failed' => count($findings),
            'findings' => $findings,
            'summary' => ['round_to' => $roundTo, 'hits' => count($findings)],
        ];
    }

    private function offHours(array $rows, array $definition): array
    {
        $field = (string) ($definition['timestamp_field'] ?? 'created_at');
        $startHour = (int) ($definition['start_hour'] ?? 8);
        $endHour = (int) ($definition['end_hour'] ?? 18);
        $identifier = $definition['identifier_field'] ?? null;
        $findings = [];
        $evaluated = 0;

        foreach ($rows as $index => $row) {
            $timestamp = $this->toTimestamp($row[$field] ?? null);

            if ($timestamp === null) {
                continue;
            }

            $evaluated++;
            $hour = (int) date('G', $timestamp);
            $weekday = (int) date('N', $timestamp);
            $outsideHours = $hour < $startHour || $hour >= $endHour;
            $weekend = $weekday >= 6;

            if ($outsideHours || $weekend) {
                $findings[] = [
                    'record_identifier' => (string) ($identifier ? ($row[$identifier] ?? $index) : $index),
                    'record_data' => ['at' => date('Y-m-d H:i', $timestamp), 'hour' => $hour, 'weekend' => $weekend, 'row' => $row],
                    'failure_reason' => $weekend
                        ? 'Posted at the weekend ('.date('D H:i', $timestamp).').'
                        : sprintf('Posted at %02d:%02d, outside %02d:00–%02d:00.', $hour, (int) date('i', $timestamp), $startHour, $endHour),
                ];
            }
        }

        return [
            'evaluated' => $evaluated,
            'failed' => count($findings),
            'findings' => $findings,
            'summary' => ['window' => sprintf('%02d:00–%02d:00', $startHour, $endHour), 'hits' => count($findings)],
        ];
    }

    private function sameDayMakerChecker(array $rows, array $definition): array
    {
        $maker = (string) ($definition['maker_field'] ?? 'maker');
        $checker = (string) ($definition['checker_field'] ?? 'checker');
        $createdField = (string) ($definition['created_at_field'] ?? 'created_at');
        $approvedField = (string) ($definition['approved_at_field'] ?? 'approved_at');
        $identifier = $definition['identifier_field'] ?? null;
        $findings = [];
        $evaluated = 0;

        foreach ($rows as $index => $row) {
            $created = $this->toTimestamp($row[$createdField] ?? null);
            $approved = $this->toTimestamp($row[$approvedField] ?? null);
            $makerId = trim((string) ($row[$maker] ?? ''));
            $checkerId = trim((string) ($row[$checker] ?? ''));

            if ($makerId === '') {
                continue;
            }

            $evaluated++;

            // The self-approval case is the serious one and is reported
            // even when the timestamps are absent.
            if ($checkerId !== '' && strcasecmp($makerId, $checkerId) === 0) {
                $findings[] = [
                    'record_identifier' => (string) ($identifier ? ($row[$identifier] ?? $index) : $index),
                    'record_data' => ['maker' => $makerId, 'checker' => $checkerId, 'row' => $row],
                    'failure_reason' => "Created and approved by the same user ({$makerId}) — maker-checker did not operate.",
                ];

                continue;
            }

            if ($created !== null && $approved !== null
                && date('Y-m-d', $created) === date('Y-m-d', $approved)
                && ($approved - $created) < (int) ($definition['min_seconds'] ?? 60)) {
                $findings[] = [
                    'record_identifier' => (string) ($identifier ? ($row[$identifier] ?? $index) : $index),
                    'record_data' => [
                        'maker' => $makerId,
                        'checker' => $checkerId,
                        'seconds_to_approval' => $approved - $created,
                        'row' => $row,
                    ],
                    'failure_reason' => sprintf(
                        'Approved %d second(s) after creation — too fast for a real second look.',
                        $approved - $created,
                    ),
                ];
            }
        }

        return [
            'evaluated' => $evaluated,
            'failed' => count($findings),
            'findings' => $findings,
            'summary' => ['hits' => count($findings)],
        ];
    }

    /**
     * custom_sql — validated, parameterised, and executed against a
     * throwaway in-memory SQLite loaded from the snapshot. The statement
     * cannot reach the application database because the application
     * database is not on the connection.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $rowsByDataset
     * @param  array<int, DataSourceDataset>  $datasets
     */
    private function customSql(array $rowsByDataset, array $datasets, array $definition): array
    {
        $query = (string) $definition['query'];
        $params = (array) ($definition['params'] ?? []);
        $identifierColumn = $definition['identifier_column'] ?? null;
        $reasonColumn = $definition['reason_column'] ?? null;

        $tables = [];

        foreach (array_keys($rowsByDataset) as $datasetId) {
            $tables[$datasetId] = $this->tableNameFor($datasetId, $datasets[$datasetId] ?? null);
        }

        try {
            SqlGuard::assertReadOnly($query, array_values($tables), array_keys($params));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['rule_definition' => $e->getMessage()]);
        }

        $pdo = $this->sandbox($rowsByDataset, $tables);

        $statement = $pdo->prepare($query);

        foreach ($params as $name => $value) {
            $statement->bindValue(':'.$name, $value);
        }

        $statement->execute();

        $limit = (int) config('monitoring.sql_max_result_rows');
        $findings = [];
        $evaluated = 0;

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $evaluated++;

            if (count($findings) >= $limit) {
                break;
            }

            $findings[] = [
                'record_identifier' => (string) ($identifierColumn ? ($row[$identifierColumn] ?? $evaluated) : $evaluated),
                'record_data' => $row,
                'failure_reason' => (string) ($reasonColumn ? ($row[$reasonColumn] ?? '') : 'Returned by the rule query.'),
            ];
        }

        $totalRows = array_sum(array_map('count', $rowsByDataset));

        return [
            'evaluated' => max($totalRows, $evaluated),
            'failed' => $evaluated,
            'findings' => $findings,
            'summary' => ['returned_rows' => $evaluated, 'tables' => array_values($tables)],
        ];
    }

    /**
     * Build the sandbox: an in-memory SQLite holding only the snapshot
     * rows, with a busy timeout and no attached databases.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $rowsByDataset
     * @param  array<int, string>  $tables
     */
    private function sandbox(array $rowsByDataset, array $tables): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => (int) config('monitoring.sql_timeout_seconds'),
        ]);

        foreach ($rowsByDataset as $datasetId => $rows) {
            $table = $tables[$datasetId] ?? 'ds_'.$datasetId;
            $columns = $this->columnsOf($rows);

            if ($columns === []) {
                $pdo->exec("create table {$table} (_empty text)");

                continue;
            }

            $definition = implode(', ', array_map(fn (string $c) => '"'.$c.'"', $columns));
            $pdo->exec("create table {$table} ({$definition})");

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $insert = $pdo->prepare("insert into {$table} values ({$placeholders})");

            foreach ($rows as $row) {
                $insert->execute(array_map(
                    fn (string $column) => is_scalar($row[$column] ?? null) || ($row[$column] ?? null) === null
                        ? ($row[$column] ?? null)
                        : json_encode($row[$column]),
                    $columns,
                ));
            }
        }

        return $pdo;
    }

    /**
     * The sandbox table name for a dataset: a slug of its name where that
     * is safe, `ds_<id>` otherwise, so a rule author writes readable SQL.
     */
    public function tableNameFor(int $datasetId, ?DataSourceDataset $dataset = null): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', (string) ($dataset->name ?? '')) ?? '');
        $slug = trim($slug, '_');

        return preg_match('/^[a-z][a-z0-9_]{1,50}$/', $slug) ? $slug : 'ds_'.$datasetId;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @param array<int, array<string, mixed>> $rows */
    private function columnsOf(array $rows): array
    {
        $columns = [];

        foreach (array_slice($rows, 0, 200) as $row) {
            foreach (array_keys($row) as $key) {
                $clean = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $key);

                if ($clean !== '' && ! in_array($clean, $columns, true)) {
                    $columns[] = $clean;
                }
            }
        }

        return $columns;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function groupBy(array $rows, string $field): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[(string) ($row[$field] ?? '(blank)')][] = $row;
        }

        return $groups;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function indexByKey(array $rows, array $keyFields): array
    {
        $index = [];

        foreach ($rows as $row) {
            $key = implode('|', array_map(
                fn (string $field) => trim((string) ($row[$field] ?? '')),
                $keyFields,
            ));

            $index[$key] = $row;
        }

        return $index;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function aggregate(array $rows, string $field, string $aggregate): float
    {
        $values = array_map(fn ($row) => (float) ($row[$field] ?? 0), $rows);

        return match ($aggregate) {
            'sum' => array_sum($values),
            'avg' => $values === [] ? 0.0 : array_sum($values) / count($values),
            'count' => (float) count($rows),
            'count_distinct' => (float) count(array_unique(array_map(
                fn ($row) => (string) ($row[$field] ?? ''),
                $rows,
            ))),
            'max' => $values === [] ? 0.0 : max($values),
            'min' => $values === [] ? 0.0 : min($values),
            default => 0.0,
        };
    }

    /**
     * Returns true when the value SATISFIES the operator. Threshold rules
     * invert it — a threshold passes when the aggregate is within the
     * limit, and the limit is expressed as the acceptable condition.
     */
    public function compare(mixed $value, string $operator, mixed $limit, mixed $limitTo = null): bool
    {
        return match ($operator) {
            'gt' => (float) $value > (float) $limit,
            'gte' => (float) $value >= (float) $limit,
            'lt' => (float) $value < (float) $limit,
            'lte' => (float) $value <= (float) $limit,
            'eq' => (string) $value === (string) $limit,
            'neq' => (string) $value !== (string) $limit,
            'between' => (float) $value >= (float) $limit && (float) $value <= (float) $limitTo,
            'in' => in_array((string) $value, array_map('strval', (array) $limit), true),
            'not_in' => ! in_array((string) $value, array_map('strval', (array) $limit), true),
            'contains' => $value !== null && str_contains(mb_strtolower((string) $value), mb_strtolower((string) $limit)),
            'not_contains' => $value === null || ! str_contains(mb_strtolower((string) $value), mb_strtolower((string) $limit)),
            'starts_with' => $value !== null && str_starts_with(mb_strtolower((string) $value), mb_strtolower((string) $limit)),
            'is_null' => $value === null || $value === '',
            'is_not_null' => $value !== null && $value !== '',
            'regex' => $value !== null && (bool) preg_match($this->safeRegex((string) $limit), (string) $value),
            default => false,
        };
    }

    /**
     * A user-supplied regex is delimited here rather than taken raw, so a
     * pattern cannot smuggle in the `e` modifier or a different delimiter.
     */
    private function safeRegex(string $pattern): string
    {
        return '/'.str_replace('/', '\/', $pattern).'/u';
    }

    private function normalise(string $value, array $rules): string
    {
        foreach ($rules as $rule) {
            $value = match ($rule) {
                'lower' => mb_strtolower($value),
                'trim' => trim($value),
                'strip_spaces' => preg_replace('/\s+/', '', $value) ?? $value,
                'digits_only' => preg_replace('/\D+/', '', $value) ?? $value,
                'alnum_only' => preg_replace('/[^A-Za-z0-9]+/', '', $value) ?? $value,
                default => $value,
            };
        }

        return $value;
    }

    /** @param array<int, string|int|float> $values */
    private function missingNumbers(array $values, array $definition): array
    {
        $step = max(1, (int) ($definition['step'] ?? 1));
        $numbers = array_values(array_unique(array_map(
            fn ($v) => (int) preg_replace('/\D+/', '', (string) $v),
            $values,
        )));

        sort($numbers);

        $start = isset($definition['start']) ? (int) $definition['start'] : ($numbers[0] ?? 0);
        $end = isset($definition['end']) ? (int) $definition['end'] : (end($numbers) ?: 0);
        $present = array_flip($numbers);
        $missing = [];

        for ($n = $start; $n <= $end; $n += $step) {
            if (! isset($present[$n])) {
                $missing[] = (string) $n;

                if (count($missing) >= 5000) {
                    break;
                }
            }
        }

        return $missing;
    }

    /** @param array<int, string|int|float> $values */
    private function missingDates(array $values, array $definition): array
    {
        $skipWeekends = (bool) ($definition['skip_weekends'] ?? true);
        $dates = [];

        foreach ($values as $value) {
            $timestamp = $this->toTimestamp($value);

            if ($timestamp !== null) {
                $dates[date('Y-m-d', $timestamp)] = true;
            }
        }

        if ($dates === []) {
            return [];
        }

        $keys = array_keys($dates);
        sort($keys);

        $start = strtotime((string) ($definition['start'] ?? $keys[0]));
        $end = strtotime((string) ($definition['end'] ?? end($keys)));
        $missing = [];

        for ($day = $start; $day <= $end; $day += 86400) {
            $label = date('Y-m-d', $day);

            if ($skipWeekends && (int) date('N', $day) >= 6) {
                continue;
            }

            if (! isset($dates[$label])) {
                $missing[] = $label;

                if (count($missing) >= 1000) {
                    break;
                }
            }
        }

        return $missing;
    }

    private function toTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (int) $value > 100000000) {
            return (int) $value;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : $timestamp;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }

    private function limitLabel(array $definition): string
    {
        $symbol = [
            'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤', 'eq' => '=', 'neq' => '≠',
        ][$definition['operator']] ?? strtoupper((string) $definition['operator']);

        return $definition['operator'] === 'between'
            ? 'between '.$this->number((float) $definition['value']).' and '.$this->number((float) ($definition['value_to'] ?? 0))
            : $symbol.' '.$this->number((float) ($definition['value'] ?? 0));
    }

    private function describeConditions(array $conditions, bool $matchAll): string
    {
        $parts = array_map(
            fn (array $c) => sprintf('%s %s %s', $c['field'], $c['operator'], is_array($c['value'] ?? null)
                ? implode('/', $c['value'])
                : (string) ($c['value'] ?? '')),
            $conditions,
        );

        return implode($matchAll ? ' AND ' : ' OR ', $parts);
    }

    private function primaryDatasetId(MonitoringRule $rule, array $definition): int
    {
        if (isset($definition['dataset'])) {
            return (int) $definition['dataset'];
        }

        $ids = array_values((array) ($rule->dataset_ids ?? []));

        return (int) ($ids[0] ?? 0);
    }

    // ── Definition validators ────────────────────────────────────────

    private function validateThreshold(array $definition): void
    {
        if (! in_array($definition['aggregate'], self::AGGREGATES, true)) {
            throw ValidationException::withMessages([
                'rule_definition' => "'{$definition['aggregate']}' is not a supported aggregate.",
            ]);
        }

        if (! in_array($definition['operator'], self::OPERATORS, true)) {
            throw ValidationException::withMessages([
                'rule_definition' => "'{$definition['operator']}' is not a supported operator.",
            ]);
        }

        if ($definition['operator'] === 'between' && ! isset($definition['value_to'])) {
            throw ValidationException::withMessages([
                'rule_definition' => 'A between comparison needs both value and value_to.',
            ]);
        }
    }

    private function validateReconciliation(array $definition, array $datasetIds): void
    {
        foreach (['left_dataset', 'right_dataset'] as $side) {
            if (! in_array((int) $definition[$side], array_map('intval', $datasetIds), true)) {
                throw ValidationException::withMessages([
                    'rule_definition' => "The {$side} must be one of the rule's own datasets.",
                ]);
            }
        }

        if ((int) $definition['left_dataset'] === (int) $definition['right_dataset']) {
            throw ValidationException::withMessages([
                'rule_definition' => 'A reconciliation needs two different datasets.',
            ]);
        }

        if ((array) $definition['key_fields'] === []) {
            throw ValidationException::withMessages([
                'rule_definition' => 'A reconciliation needs at least one key field.',
            ]);
        }
    }

    private function validateGap(array $definition): void
    {
        if (! in_array($definition['mode'], ['sequence', 'date'], true)) {
            throw ValidationException::withMessages([
                'rule_definition' => "A gap rule's mode must be 'sequence' or 'date'.",
            ]);
        }
    }

    private function validateConditions(array $conditions): void
    {
        if ($conditions === []) {
            throw ValidationException::withMessages([
                'rule_definition' => 'An exception-list rule needs at least one condition.',
            ]);
        }

        foreach ($conditions as $condition) {
            if (! isset($condition['field'], $condition['operator'])) {
                throw ValidationException::withMessages([
                    'rule_definition' => 'Every condition needs a field and an operator.',
                ]);
            }

            if (! in_array($condition['operator'], self::OPERATORS, true)) {
                throw ValidationException::withMessages([
                    'rule_definition' => "'{$condition['operator']}' is not a supported operator.",
                ]);
            }

            if ($condition['operator'] === 'regex') {
                if (@preg_match($this->safeRegex((string) ($condition['value'] ?? '')), '') === false) {
                    throw ValidationException::withMessages([
                        'rule_definition' => 'The regular expression is not valid.',
                    ]);
                }
            }
        }
    }

    private function validatePattern(array $definition): void
    {
        if (! in_array($definition['pattern'], self::PATTERNS, true)) {
            throw ValidationException::withMessages([
                'rule_definition' => "'{$definition['pattern']}' is not a supported pattern test.",
            ]);
        }
    }

    private function validateCustomSql(array $definition, array $datasetIds): void
    {
        $datasets = DataSourceDataset::withoutGlobalScopes()
            ->whereIn('id', $datasetIds)
            ->get()
            ->keyBy('id');

        $tables = [];

        foreach ($datasetIds as $id) {
            $tables[] = $this->tableNameFor((int) $id, $datasets->get($id));
        }

        try {
            SqlGuard::assertReadOnly(
                (string) $definition['query'],
                $tables,
                array_keys((array) ($definition['params'] ?? [])),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['rule_definition' => $e->getMessage()]);
        }
    }
}
