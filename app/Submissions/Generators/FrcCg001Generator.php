<?php

namespace App\Submissions\Generators;

use App\Models\FrameworkRequirement;
use App\Models\User;
use App\Submissions\GeneratedPack;
use App\Submissions\SubmissionGenerator;

/**
 * FRC/CG/001 — the annual Nigerian Code of Corporate Governance return.
 *
 * The rule that shapes this generator: on the apply-and-explain principles
 * in Section E, "N/A" is not an available answer. A company either applied a
 * principle or it did not, and if it did not it explains why. The generator
 * therefore treats an unanswered or N/A principle as a blocking error, not a
 * warning — the Submit action is refused until every principle carries a
 * Yes or a No and a narrative.
 *
 * The principles themselves are not written here. They are the 28 seeded
 * requirements of the FRC-NCCG-2018 content pack (R1), and any of them whose
 * text has not been verified against the FRC's own document is excluded from
 * the generated return and listed instead (R10).
 */
class FrcCg001Generator extends SubmissionGenerator
{
    public function packType(): string
    {
        return 'FRC/CG/001';
    }

    public function label(): string
    {
        return 'FRC/CG/001 — Corporate governance return (NCCG 2018)';
    }

    public function regulatorCode(): string
    {
        return 'FRC-NG';
    }

    public function frameworkCode(): string
    {
        return 'FRC-NCCG-2018';
    }

    public function obligationRef(): ?string
    {
        return 'FRC-CG-001-RETURN';
    }

    public function description(): string
    {
        return 'Sections A to E of the annual corporate governance return, including the apply-and-explain '
            .'narrative for every principle. Certified by four signatories.';
    }

    public function signatories(): array
    {
        return [
            ['role' => 'Chairman of the Board'],
            ['role' => 'Managing Director / Chief Executive Officer'],
            ['role' => 'Chairman, Governance Committee'],
            ['role' => 'Company Secretary'],
        ];
    }

    public function generate(User $user, array $options = []): GeneratedPack
    {
        $entity = $this->entity($options);
        $period = $options['period_label'] ?? $this->defaultPeriodLabel();
        $errors = [];

        if (! $this->framework()) {
            return new GeneratedPack(
                sections: [],
                validationErrors: [$this->missingFrameworkError()],
                signatories: $this->signatories(),
            );
        }

        $principles = $this->requirements()->where('requirement_type', 'principle')->values();

        if ($principles->isEmpty()) {
            $principles = $this->requirements();
        }

        [$verified, $unverified] = $this->splitByVerification($principles);
        $mapped = $this->mappedControls($verified);

        if ($unverified !== []) {
            $errors[] = $this->error(
                'principles_unverified',
                count($unverified).' of '.$principles->count().' principles come from unverified content and are '
                .'excluded from the return. Verify the FRC-NCCG-2018 content pack before filing.',
            );
        }

        $sections = [
            $this->sectionA($entity, $period),
            $this->sectionB($entity),
            $this->sectionC(),
            $this->sectionD(),
            $this->sectionE($verified, $mapped),
        ];

        // Merge whatever a human already answered on this pack back over the
        // freshly-derived shell, so regenerating never destroys typed work.
        $sections = $this->mergeExisting($sections, $options['existing'] ?? []);

        $errors = array_merge($errors, $this->principleErrors($sections));

        return new GeneratedPack(
            sections: $sections,
            evidenceIds: array_values(array_filter($options['evidence_ids'] ?? [])),
            unverifiedItems: $unverified,
            validationErrors: $errors,
            signatories: $this->signatories(),
            meta: [
                'principles_total' => $principles->count(),
                'principles_included' => $verified->count(),
                'period_label' => $period,
                'entity' => $entity?->name,
            ],
        );
    }

    private function sectionA($entity, string $period): array
    {
        return $this->section('A', 'Section A — Introduction', [
            $this->field('a_entity_name', 'Name of company', $entity?->name, true, 'system'),
            $this->field('a_period', 'Reporting period', $period, true, 'system'),
            $this->field('a_code_applied', 'Code applied', 'Nigerian Code of Corporate Governance 2018', true, 'system'),
            $this->field('a_statement', 'Statement of compliance approach', null, true, 'input',
                'The board\'s summary of how it applied the Code during the period.'),
        ]);
    }

    private function sectionB($entity): array
    {
        return $this->section('B', 'Section B — General information', [
            $this->field('b_rc_number', 'RC number', $entity?->rc_number, true, $entity?->rc_number ? 'system' : 'input'),
            $this->field('b_incorporated_on', 'Date of incorporation', $entity?->incorporated_on?->toDateString(),
                true, $entity?->incorporated_on ? 'system' : 'input'),
            $this->field('b_entity_type', 'Entity type', $entity?->entity_type, true, $entity?->entity_type ? 'system' : 'input'),
            $this->field('b_registered_address', 'Registered address', null),
            $this->field('b_external_auditors', 'External auditors'),
            $this->field('b_auditor_appointed_on', 'Auditor appointment date'),
            $this->field('b_registrars', 'Registrars'),
            $this->field('b_contact_name', 'Contact person'),
            $this->field('b_contact_email', 'Contact email'),
            $this->field('b_contact_phone', 'Contact telephone'),
        ]);
    }

    /**
     * Board composition and attendance. The platform does not hold a register
     * of directors, so these are honest input rows rather than invented data —
     * and an unfilled row costs completeness, which is what stops a half-built
     * return being filed.
     */
    private function sectionC(): array
    {
        return $this->section('C', 'Section C — Board composition and meeting attendance', [
            $this->field('c_board_size', 'Number of directors'),
            $this->field('c_independent_count', 'Of which independent non-executive'),
            $this->field('c_board_meetings_held', 'Board meetings held in the period'),
            $this->field('c_directors', 'Directors', [], true, 'input',
                'One row per director: name, category, appointment date, meetings attended.'),
            $this->field('c_committees', 'Board committees', [], true, 'input',
                'One row per committee: name, chair, members, meetings held, attendance.'),
        ], [
            'row_templates' => [
                'c_directors' => ['Name', 'Category', 'Appointment date', 'Meetings attended'],
                'c_committees' => ['Committee', 'Chair', 'Members', 'Meetings held', 'Attendance'],
            ],
        ]);
    }

    private function sectionD(): array
    {
        return $this->section('D', 'Section D — Senior management', [
            $this->field('d_management', 'Senior management', [], true, 'input',
                'One row per officer: position, name, gender.'),
        ], [
            'row_templates' => ['d_management' => ['Position', 'Name', 'Gender']],
        ]);
    }

    /**
     * Section E — the apply-and-explain walk. Each principle gets a Yes/No
     * and a narrative. The generator pre-answers "Yes" only where an approved
     * control mapping already evidences the principle; the explanation is
     * always the human's to write.
     */
    private function sectionE($principles, array $mapped): array
    {
        $fields = [];

        foreach ($principles as $principle) {
            /** @var FrameworkRequirement $principle */
            $controls = $mapped[$principle->id] ?? [];

            $fields[] = $this->field(
                'e_'.strtolower($principle->ref_code).'_applied',
                $principle->ref_code.' — '.$principle->title.': applied?',
                $controls !== [] ? 'Yes' : null,
                true,
                $controls !== [] ? 'system' : 'input',
                $controls !== []
                    ? 'Evidenced by '.implode(', ', array_column($controls, 'ref')).'.'
                    : 'No mapped control evidences this principle yet.',
                // "N/A" is deliberately absent: the Code does not permit it.
                ['Yes', 'No'],
            );

            $fields[] = $this->field(
                'e_'.strtolower($principle->ref_code).'_explanation',
                $principle->ref_code.' — explanation of application or deviation',
                null,
                true,
                'input',
            );
        }

        return $this->section('E', 'Section E — Application of the 28 principles', $fields, [
            'principle_count' => count($fields) / 2,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function principleErrors(array $sections): array
    {
        $sectionE = collect($sections)->firstWhere('key', 'E');

        if (! $sectionE) {
            return [];
        }

        $unanswered = [];
        $notApplicable = [];

        foreach ($sectionE['fields'] as $field) {
            if (! str_ends_with($field['key'], '_applied')) {
                continue;
            }

            $value = is_string($field['value']) ? trim($field['value']) : $field['value'];

            if ($value === null || $value === '') {
                $unanswered[] = $field['label'];
            } elseif (in_array(strtoupper((string) $value), ['N/A', 'NA', 'NOT APPLICABLE'], true)) {
                $notApplicable[] = $field['label'];
            }
        }

        $errors = [];

        if ($unanswered !== []) {
            $errors[] = $this->error(
                'principle_unanswered',
                count($unanswered).' principle(s) have no Yes/No answer. Every principle must be answered before filing.',
            );
        }

        if ($notApplicable !== []) {
            $errors[] = $this->error(
                'principle_not_applicable',
                count($notApplicable).' principle(s) are answered "N/A". The Code does not permit N/A — '
                .'answer Yes or No and explain the application or the deviation.',
            );
        }

        return $errors;
    }
}
