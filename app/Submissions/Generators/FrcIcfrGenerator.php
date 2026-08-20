<?php

namespace App\Submissions\Generators;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\User;
use App\Submissions\DeficiencyAggregator;
use App\Submissions\GeneratedPack;
use App\Submissions\SubmissionGenerator;

/**
 * FRC ICFR Management Report — Nigeria's SOX-404 equivalent (2024 guidance).
 *
 * The report is the end of a top-down chain: significant accounts →
 * processes → risks → controls → test results → deficiency evaluation and
 * aggregation → conclusion. This generator walks the last four links from
 * live data and leaves the first two to the officer, because the platform
 * does not know which accounts are significant to these financial
 * statements.
 *
 * The conclusion is never computed. A material weakness disclosed means
 * management has concluded ICFR was not effective — that sentence is signed
 * by a person, so the generator states the evidence and requires the answer.
 */
class FrcIcfrGenerator extends SubmissionGenerator
{
    public function packType(): string
    {
        return 'FRC/ICFR';
    }

    public function label(): string
    {
        return 'FRC ICFR Management Report (2024 guidance)';
    }

    public function regulatorCode(): string
    {
        return 'FRC-NG';
    }

    public function frameworkCode(): string
    {
        return 'FRC-ICFR-2024';
    }

    public function obligationRef(): ?string
    {
        return 'FRC-ICFR-MGT-REPORT';
    }

    public function description(): string
    {
        return "Management's statement of responsibility for internal control over financial reporting, the "
            .'evaluation framework, the effectiveness assessment and any material weaknesses.';
    }

    public function signatories(): array
    {
        return [
            ['role' => 'Managing Director / Chief Executive Officer'],
            ['role' => 'Chief Financial Officer'],
        ];
    }

    public function generate(User $user, array $options = []): GeneratedPack
    {
        if (! $this->framework()) {
            return new GeneratedPack(
                sections: [],
                validationErrors: [$this->missingFrameworkError()],
                signatories: $this->signatories(),
            );
        }

        $entity = $this->entity($options);
        $period = $options['period_label'] ?? $this->defaultPeriodLabel();
        [, $unverified] = $this->splitByVerification($this->requirements());

        $aggregator = new DeficiencyAggregator($this->escalationThreshold($user));
        $evaluation = $aggregator->aggregate($this->deficiencies($aggregator));

        $sections = $this->mergeExisting([
            $this->responsibilitySection($entity, $period),
            $this->frameworkSection(),
            $this->scopingSection(),
            $this->testingSection(),
            $this->deficiencySection($evaluation, $aggregator),
            $this->conclusionSection($evaluation),
        ], $options['existing'] ?? []);

        $errors = $this->conclusionErrors($sections, $evaluation);

        if ($unverified !== []) {
            $errors[] = $this->error(
                'requirements_unverified',
                count($unverified).' ICFR requirement(s) are unverified and are excluded from the report.',
                false,
            );
        }

        return new GeneratedPack(
            sections: $sections,
            unverifiedItems: $unverified,
            validationErrors: $errors,
            signatories: $this->signatories(),
            meta: [
                'period_label' => $period,
                'entity' => $entity?->name,
                'deficiency_counts' => $evaluation['counts'],
                'escalations' => $evaluation['escalations'],
            ],
        );
    }

    /** R1: the aggregation threshold is tenant-configurable data, not a constant. */
    private function escalationThreshold(User $user): int
    {
        return max(2, (int) ($user->tenant?->settings['icfr']['significant_deficiency_escalation_threshold'] ?? 2));
    }

    private function responsibilitySection($entity, string $period): array
    {
        return $this->section('MR1', 'Statement of management responsibility', [
            $this->field('entity_name', 'Reporting entity', $entity?->name, true, 'system'),
            $this->field('fiscal_year_end', 'Fiscal year end', $this->fiscalYearEnd($entity), true,
                $entity?->fiscal_year_end_month ? 'system' : 'input'),
            $this->field('period', 'Reporting period', $period, true, 'system'),
            $this->field('responsibility_statement', 'Statement of responsibility', null, true, 'input',
                "Management's acknowledgement of its responsibility for establishing and maintaining adequate "
                .'internal control over financial reporting.'),
        ]);
    }

    private function frameworkSection(): array
    {
        return $this->section('MR2', 'Evaluation framework', [
            $this->field('control_framework', 'Framework used for the evaluation', 'COSO Internal Control — Integrated Framework (2013)',
                true, 'system', 'Change this only if the evaluation genuinely used another recognised framework.'),
            $this->field('framework_rationale', 'Basis for the framework selection', null, false),
        ]);
    }

    /**
     * The top-down scope. Significant accounts and their processes are the
     * officer's determination; the control population that hangs off them is
     * the platform's.
     */
    private function scopingSection(): array
    {
        $keyControls = Control::query()
            ->where('is_template', false)
            ->where('status', 'Active')
            ->where('is_key_control', true)
            ->count();

        return $this->section('SCOPE', 'Top-down scoping', [
            $this->field('significant_accounts', 'Significant accounts and disclosures', [], true, 'input',
                'One row per account: account, materiality basis, relevant assertions, process.'),
            $this->field('in_scope_processes', 'In-scope processes', [], true, 'input'),
            $this->field('key_control_population', 'Key controls in the population', $keyControls, true, 'system'),
        ], [
            'row_templates' => [
                'significant_accounts' => ['Account or disclosure', 'Materiality basis', 'Assertions', 'Process'],
                'in_scope_processes' => ['Process', 'Owner', 'Key controls'],
            ],
        ]);
    }

    private function testingSection(): array
    {
        $ratings = Control::query()
            ->where('is_template', false)
            ->where('status', 'Active')
            ->where('is_key_control', true)
            ->selectRaw("
                count(*) as total,
                sum(case when operating_effectiveness = 'Effective' then 1 else 0 end) as effective,
                sum(case when operating_effectiveness = 'Partially Effective' then 1 else 0 end) as partial,
                sum(case when operating_effectiveness = 'Ineffective' then 1 else 0 end) as ineffective,
                sum(case when operating_effectiveness is null or operating_effectiveness = 'Not Tested' then 1 else 0 end) as untested
            ")
            ->first();

        $untested = (int) ($ratings->untested ?? 0);

        return $this->section('TEST', 'Testing results', [
            $this->field('tested_effective', 'Key controls rated effective', (int) ($ratings->effective ?? 0), true, 'system'),
            $this->field('tested_partial', 'Key controls rated partially effective', (int) ($ratings->partial ?? 0), true, 'system'),
            $this->field('tested_ineffective', 'Key controls rated ineffective', (int) ($ratings->ineffective ?? 0), true, 'system'),
            $this->field('tested_untested', 'Key controls not tested', $untested, true, 'system',
                $untested > 0 ? 'An untested key control is a scope limitation, not a pass.' : null),
            $this->field('testing_narrative', 'Commentary on the testing approach', null, true, 'input'),
        ]);
    }

    private function deficiencySection(array $evaluation, DeficiencyAggregator $aggregator): array
    {
        $rows = array_map(fn (array $deficiency) => [
            $deficiency['reference'],
            $deficiency['area'],
            $deficiency['description'],
            DeficiencyAggregator::LABELS[$deficiency['tier']],
            ($deficiency['escalated'] ?? false) ? 'Escalated by aggregation' : '',
        ], $evaluation['deficiencies']);

        $fields = [
            $this->field('deficiency_rows', 'Deficiency register', $rows, true, 'system'),
            $this->field('deficiency_control', 'Control deficiencies',
                $evaluation['counts'][DeficiencyAggregator::CONTROL_DEFICIENCY], true, 'system'),
            $this->field('deficiency_significant', 'Significant deficiencies',
                $evaluation['counts'][DeficiencyAggregator::SIGNIFICANT_DEFICIENCY], true, 'system'),
            $this->field('deficiency_material', 'Material weaknesses',
                $evaluation['counts'][DeficiencyAggregator::MATERIAL_WEAKNESS], true, 'system'),
        ];

        foreach ($evaluation['escalations'] as $index => $escalation) {
            $fields[] = $this->field(
                'aggregation_review_'.$index,
                'Management review of aggregated deficiencies in '.$escalation['area'],
                null,
                true,
                'input',
                $escalation['reason'],
            );
        }

        return $this->section('DEF', 'Deficiency evaluation and aggregation', $fields, [
            'aggregation_threshold' => $aggregator->threshold(),
            'escalations' => $evaluation['escalations'],
            'row_templates' => [
                'deficiency_rows' => ['Reference', 'Area', 'Deficiency', 'Classification', 'Note'],
            ],
        ]);
    }

    private function conclusionSection(array $evaluation): array
    {
        $material = $evaluation['counts'][DeficiencyAggregator::MATERIAL_WEAKNESS];

        return $this->section('MR3', 'Assessment and conclusion', [
            $this->field('assessment_as_at', 'Effectiveness assessed as at'),
            $this->field('conclusion', 'Management conclusion on ICFR effectiveness', null, true, 'input',
                $material > 0
                    ? "{$material} material weakness(es) are on the register. Internal control over financial "
                      .'reporting cannot be concluded effective while a material weakness exists.'
                    : 'No material weakness is on the register for the period.',
                ['Effective', 'Not effective']),
            $this->field('material_weakness_disclosure', 'Disclosure of material weaknesses',
                null, $material > 0, 'input',
                $material > 0 ? 'Describe each material weakness and the remediation plan.' : null),
            $this->field('auditor_attestation', 'External auditor attestation report issued', null, true, 'input',
                "Management's statement that the external auditor has issued an attestation report on ICFR.",
                ['Yes', 'No']),
            $this->field('auditor_name', 'External auditor'),
        ]);
    }

    /**
     * Live control failures, classified. Open exceptions are the primary
     * source because they are the failures someone has already accepted are
     * real; an ineffective rating with no exception behind it is added so a
     * silently failing control is not omitted.
     *
     * @return array<int, array<string, mixed>>
     */
    private function deficiencies(DeficiencyAggregator $aggregator): array
    {
        $exceptions = ControlException::query()
            ->open()
            ->whereNotNull('control_id')
            ->with(['control:id,control_ref,title,is_key_control,process_id', 'control.process:id,name'])
            ->orderByDesc('date_raised')
            ->limit(500)
            ->get(['id', 'reference', 'title', 'severity', 'control_id']);

        $deficiencies = [];
        $seenControls = [];

        foreach ($exceptions as $exception) {
            $control = $exception->control;

            if (! $control) {
                continue;
            }

            $seenControls[$control->id] = true;

            $deficiencies[] = [
                'reference' => $exception->reference,
                'area' => $control->process?->name ?? 'Unassigned process',
                'control_ref' => $control->control_ref,
                'description' => $exception->title,
                'severity' => $exception->severity,
                'tier' => $aggregator->classify($exception->severity, (bool) $control->is_key_control),
            ];
        }

        $failing = Control::query()
            ->where('is_template', false)
            ->where('status', 'Active')
            ->whereIn('operating_effectiveness', ['Ineffective', 'Partially Effective'])
            ->with('process:id,name')
            ->limit(500)
            ->get(['id', 'control_ref', 'title', 'is_key_control', 'operating_effectiveness', 'process_id']);

        foreach ($failing as $control) {
            if (isset($seenControls[$control->id])) {
                continue;
            }

            $severity = $control->operating_effectiveness === 'Ineffective' ? 'High' : 'Medium';

            $deficiencies[] = [
                'reference' => $control->control_ref,
                'area' => $control->process?->name ?? 'Unassigned process',
                'control_ref' => $control->control_ref,
                'description' => $control->title.' — rated '.strtolower($control->operating_effectiveness),
                'severity' => $severity,
                'tier' => $aggregator->classify($severity, (bool) $control->is_key_control),
            ];
        }

        return $deficiencies;
    }

    /** @return array<int, array<string, mixed>> */
    private function conclusionErrors(array $sections, array $evaluation): array
    {
        $fields = collect($sections)->firstWhere('key', 'MR3')['fields'] ?? [];
        $value = fn (string $key) => collect($fields)->firstWhere('key', $key)['value'] ?? null;

        $errors = [];
        $material = $evaluation['counts'][DeficiencyAggregator::MATERIAL_WEAKNESS];
        $conclusion = (string) $value('conclusion');

        if ($material > 0 && $conclusion === 'Effective') {
            $errors[] = $this->error(
                'conclusion_contradicts_evidence',
                "The report concludes ICFR is effective while {$material} material weakness(es) stand on the "
                .'register. Remediate and re-test, or change the conclusion.',
            );
        }

        foreach ($evaluation['escalations'] as $index => $escalation) {
            $review = collect($sections)->firstWhere('key', 'DEF')['fields'] ?? [];
            $answered = collect($review)->firstWhere('key', 'aggregation_review_'.$index)['value'] ?? null;

            if ($answered === null || trim((string) $answered) === '') {
                $errors[] = $this->error(
                    'aggregation_review_outstanding',
                    "Aggregated deficiencies in {$escalation['area']} have not been reviewed by management. "
                    .'The aggregation rule flags them; only a person concludes on them.',
                );
            }
        }

        return $errors;
    }

    private function fiscalYearEnd($entity): ?string
    {
        if (! $entity?->fiscal_year_end_month) {
            return null;
        }

        return sprintf('%02d-%02d', $entity->fiscal_year_end_month, $entity->fiscal_year_end_day ?? 31);
    }
}
