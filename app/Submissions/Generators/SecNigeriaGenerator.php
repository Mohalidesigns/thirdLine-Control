<?php

namespace App\Submissions\Generators;

use App\Models\FrameworkRequirement;
use App\Models\ObligationInstance;
use App\Models\RegulatoryObligation;
use App\Models\User;
use App\Submissions\GeneratedPack;
use App\Submissions\SubmissionGenerator;

/**
 * SEC Nigeria corporate governance report, with the quarterly and annual
 * returns tracker attached.
 *
 * Two things in one pack because they are filed to the same regulator by the
 * same officer: the governance principles walk, and the standing record of
 * whether every periodic return actually went in on time. The tracker is
 * generated entirely from the obligation register — it is the one section
 * nobody should be typing by hand.
 */
class SecNigeriaGenerator extends SubmissionGenerator
{
    public function packType(): string
    {
        return 'SEC/CG';
    }

    public function label(): string
    {
        return 'SEC Nigeria corporate governance report and returns tracker';
    }

    public function regulatorCode(): string
    {
        return 'SEC-NG';
    }

    public function frameworkCode(): string
    {
        return 'SEC-NG-CG-2020';
    }

    public function obligationRef(): ?string
    {
        return 'SEC-NG-CG-FORM-01';
    }

    public function description(): string
    {
        return 'The SEC corporate governance report against the SEC Code principles, plus a tracker of every '
            .'quarterly and annual return and whether it was filed on time.';
    }

    public function signatories(): array
    {
        return [
            ['role' => 'Chairman of the Board'],
            ['role' => 'Managing Director / Chief Executive Officer'],
            ['role' => 'Company Secretary'],
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

        [$verified, $unverified] = $this->splitByVerification($this->requirements());
        $mapped = $this->mappedControls($verified);

        $sections = $this->mergeExisting([
            $this->generalSection($entity, $period),
            $this->principlesSection($verified, $mapped),
            $this->returnsSection(),
        ], $options['existing'] ?? []);

        $errors = $this->principleErrors($sections);

        if ($unverified !== []) {
            $errors[] = $this->error(
                'principles_unverified',
                count($unverified).' SEC principle(s) are unverified and are excluded from the report. '
                .'Verify the SEC-NG-CG-2020 content pack before filing.',
            );
        }

        return new GeneratedPack(
            sections: $sections,
            unverifiedItems: $unverified,
            validationErrors: $errors,
            signatories: $this->signatories(),
            meta: ['period_label' => $period, 'entity' => $entity?->name],
        );
    }

    private function generalSection($entity, string $period): array
    {
        return $this->section('GEN', 'General information', [
            $this->field('entity_name', 'Company', $entity?->name, true, 'system'),
            $this->field('rc_number', 'RC number', $entity?->rc_number, true, $entity?->rc_number ? 'system' : 'input'),
            $this->field('period', 'Reporting period', $period, true, 'system'),
            $this->field('listing_status', 'Listing status'),
            $this->field('company_secretary', 'Company secretary'),
        ]);
    }

    private function principlesSection($principles, array $mapped): array
    {
        $fields = [];

        foreach ($principles as $principle) {
            /** @var FrameworkRequirement $principle */
            $controls = $mapped[$principle->id] ?? [];

            $fields[] = $this->field(
                'sec_'.strtolower($principle->ref_code).'_complied',
                $principle->ref_code.' — '.$principle->title.': complied?',
                $controls !== [] ? 'Yes' : null,
                true,
                $controls !== [] ? 'system' : 'input',
                $controls !== [] ? 'Evidenced by '.implode(', ', array_column($controls, 'ref')).'.' : null,
                ['Yes', 'No'],
            );

            $fields[] = $this->field(
                'sec_'.strtolower($principle->ref_code).'_explanation',
                $principle->ref_code.' — explanation',
                null,
                true,
                'input',
            );
        }

        return $this->section('CG', 'Corporate governance principles', $fields);
    }

    /**
     * The returns tracker: every SEC periodic obligation the register holds,
     * with what it was due and what actually happened. Generated, not typed.
     */
    private function returnsSection(): array
    {
        $obligationIds = RegulatoryObligation::query()
            ->whereHas('regulator', fn ($q) => $q->where('code', $this->regulatorCode()))
            ->whereIn('frequency', ['quarterly', 'annual', 'semi_annual', 'monthly'])
            ->pluck('id');

        $instances = ObligationInstance::query()
            ->whereIn('obligation_id', $obligationIds)
            ->with('obligation:id,title,obligation_ref,frequency')
            ->orderByDesc('due_at')
            ->limit(60)
            ->get(['id', 'obligation_id', 'period_label', 'due_at', 'submitted_at', 'status', 'submission_reference']);

        $late = $instances->filter(fn (ObligationInstance $instance) => $instance->submitted_at
            && $instance->due_at
            && $instance->submitted_at->greaterThan($instance->due_at))->count();

        $outstanding = $instances->whereIn('status', ['Not Started', 'In Progress', 'Overdue'])->count();

        return $this->section('RET', 'Quarterly and annual returns tracker', [
            $this->field('returns_tracked', 'Return instances tracked', $instances->count(), true, 'system'),
            $this->field('returns_late', 'Filed after the deadline', $late, true, 'system'),
            $this->field('returns_outstanding', 'Still outstanding', $outstanding, true, 'system'),
            $this->field('returns_rows', 'Returns tracker', $instances->map(fn (ObligationInstance $instance) => [
                $instance->obligation?->title,
                $instance->obligation?->frequency,
                $instance->period_label,
                $instance->due_at?->toDateString(),
                $instance->submitted_at?->toDateString() ?? '—',
                $instance->status,
                $instance->submission_reference ?? '—',
            ])->values()->all(), true, 'system'),
            $this->field('returns_narrative', 'Explanation of late or outstanding returns',
                null, $late > 0 || $outstanding > 0, 'input'),
        ], [
            'row_templates' => [
                'returns_rows' => ['Return', 'Frequency', 'Period', 'Due', 'Filed', 'Status', 'Reference'],
            ],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function principleErrors(array $sections): array
    {
        $fields = collect($sections)->firstWhere('key', 'CG')['fields'] ?? [];

        $unanswered = collect($fields)
            ->filter(fn (array $field) => str_ends_with($field['key'], '_complied'))
            ->filter(fn (array $field) => $field['value'] === null || trim((string) $field['value']) === '')
            ->count();

        return $unanswered > 0
            ? [$this->error('principle_unanswered', "{$unanswered} principle(s) have no compliance answer.")]
            : [];
    }
}
