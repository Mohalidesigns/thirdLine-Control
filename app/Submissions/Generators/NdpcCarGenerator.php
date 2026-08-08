<?php

namespace App\Submissions\Generators;

use App\Models\DataSourceDataset;
use App\Models\Evidence;
use App\Models\Incident;
use App\Models\User;
use App\Submissions\GeneratedPack;
use App\Submissions\SubmissionGenerator;
use App\Support\Money;

/**
 * NDPC Compliance Audit Return — GAID 2025 Article 10.
 *
 * Two rules from the instrument shape this generator. First, tier drives
 * everything: only UHL and EHL controllers file at all, and both must engage
 * a licensed Data Protection Compliance Organisation — so an OHL answer ends
 * the return, and a UHL/EHL answer without a DPCO is a blocking error.
 * Second, the fee band and the late-filing penalty are regulatory facts, not
 * constants: the band comes from the officer, and the penalty rate comes from
 * the seeded obligation. If that obligation has not been verified against the
 * NDPC's own document, the exposure is reported as unverified and left out of
 * the filed return (R10) rather than being quoted as fact.
 */
class NdpcCarGenerator extends SubmissionGenerator
{
    private const TIERS = ['UHL', 'EHL', 'OHL'];

    /** Tiers that must file, per Article 10. */
    private const FILING_TIERS = ['UHL', 'EHL'];

    public function packType(): string
    {
        return 'NDPC/CAR';
    }

    public function label(): string
    {
        return 'NDPC Compliance Audit Return (GAID 2025 Art. 10)';
    }

    public function regulatorCode(): string
    {
        return 'NDPC';
    }

    public function frameworkCode(): string
    {
        return 'NDPA-GAID-2025';
    }

    public function obligationRef(): ?string
    {
        return 'NDPA-CAR-ANNUAL';
    }

    public function description(): string
    {
        return 'Annual data-protection compliance audit return: tier, DPO, RoPA, DPIA register, breach register '
            .'with 72-hour evidence, cross-border transfers, processors and training.';
    }

    public function signatories(): array
    {
        return [
            ['role' => 'Data Protection Officer'],
            ['role' => 'Chief Executive Officer'],
            ['role' => 'Licensed Data Protection Compliance Organisation'],
        ];
    }

    public function defaultPeriodLabel(): string
    {
        return 'FY '.(now()->year - 1);
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

        $sections = $this->mergeExisting([
            $this->tierSection($entity, $period),
            $this->dpoSection(),
            $this->ropaSection(),
            $this->dpiaSection(),
            $this->breachSection($period),
            $this->crossBorderSection(),
            $this->processorSection(),
            $this->trainingSection(),
            $this->feeSection($unverified),
        ], $options['existing'] ?? []);

        $sections = $this->applyPenalty($sections);
        $errors = $this->tierErrors($sections);

        if ($unverified !== []) {
            $errors[] = $this->error(
                'requirements_unverified',
                count($unverified).' GAID requirement(s) are unverified and are excluded from the return. '
                .'Verify the NDPA-GAID-2025 content pack before filing.',
                false,
            );
        }

        return new GeneratedPack(
            sections: $sections,
            evidenceIds: $this->breachEvidenceIds(),
            unverifiedItems: array_merge($unverified, $this->unverifiedPenalty()),
            validationErrors: $errors,
            signatories: $this->signatories(),
            meta: ['period_label' => $period, 'entity' => $entity?->name],
        );
    }

    private function tierSection($entity, string $period): array
    {
        return $this->section('TIER', 'Tier determination and filing obligation', [
            $this->field('entity_name', 'Data controller / processor', $entity?->name, true, 'system'),
            $this->field('period', 'Return period', $period, true, 'system'),
            $this->field('tier', 'Tier (Articles 8–9)', null, true, 'input',
                'UHL and EHL controllers of major importance file this return. OHL does not.', self::TIERS),
            $this->field('data_subject_volume', 'Number of data subjects processed in the period'),
            $this->field('dpco_name', 'Licensed Data Protection Compliance Organisation engaged', null, true, 'input',
                'Required for UHL and EHL.'),
            $this->field('dpco_licence_ref', 'DPCO licence reference'),
            $this->field('commenced_business_on', 'Date business commenced', $entity?->incorporated_on?->toDateString(),
                false, $entity?->incorporated_on ? 'system' : 'input',
                'A new entity files its first return within 15 months of commencing business.'),
        ]);
    }

    private function dpoSection(): array
    {
        return $this->section('DPO', 'Data protection officer', [
            $this->field('dpo_name', 'DPO name'),
            $this->field('dpo_email', 'DPO contact email'),
            $this->field('dpo_appointed_on', 'Date appointed'),
            $this->field('dpo_reports_to', 'Reporting line'),
            $this->field('dpo_independence', 'Independence attestation', null, true, 'input',
                'Confirmation that the DPO reports at the highest management level and receives no instruction '
                .'on the exercise of their tasks.'),
        ]);
    }

    /**
     * The record of processing activities, answered from the connector
     * registry: every dataset the platform ingests carries a PII
     * classification and, for sensitive data, a DPO authorisation (Phase 12).
     */
    private function ropaSection(): array
    {
        $datasets = DataSourceDataset::query()
            ->whereIn('pii_classification', ['personal', 'sensitive_personal'])
            ->with('dataSource:id,name')
            ->get(['id', 'data_source_id', 'name', 'pii_classification', 'dpo_authorised_at']);

        $unauthorised = $datasets
            ->where('pii_classification', 'sensitive_personal')
            ->whereNull('dpo_authorised_at')
            ->count();

        return $this->section('ROPA', 'Record of processing activities', [
            $this->field('ropa_registered_activities', 'Processing activities on the platform register',
                $datasets->count(), true, 'system',
                $unauthorised > 0
                    ? "{$unauthorised} sensitive-data activity(ies) have no recorded DPO authorisation."
                    : null),
            $this->field('ropa_rows', 'Processing activities', $datasets->map(fn (DataSourceDataset $dataset) => [
                $dataset->name,
                $dataset->dataSource?->name,
                $dataset->pii_classification,
                $dataset->dpo_authorised_at ? 'Authorised' : 'Not authorised',
            ])->values()->all(), true, 'system'),
            $this->field('ropa_manual_activities', 'Processing activities held outside the platform', [], true, 'input',
                'One row per activity: purpose, categories of data, lawful basis, retention.'),
        ], [
            'row_templates' => [
                'ropa_rows' => ['Activity', 'Source system', 'Classification', 'DPO authorisation'],
                'ropa_manual_activities' => ['Purpose', 'Categories of data', 'Lawful basis', 'Retention'],
            ],
        ]);
    }

    private function dpiaSection(): array
    {
        return $this->section('DPIA', 'Data protection impact assessments', [
            $this->field('dpia_completed', 'DPIAs completed in the period'),
            $this->field('dpia_outstanding', 'High-risk processing without a completed DPIA'),
            $this->field('dpia_rows', 'DPIA register', [], true, 'input',
                'One row per assessment: processing activity, date, residual risk, approver.'),
        ], [
            'row_templates' => ['dpia_rows' => ['Processing activity', 'Date completed', 'Residual risk', 'Approved by']],
        ]);
    }

    /**
     * The breach register, answered from the incident module: every data
     * breach recorded, with whether its regulator notification went out
     * inside 72 hours. That comparison is the evidence the Article 33
     * question actually asks for.
     */
    private function breachSection(string $period): array
    {
        $breaches = Incident::query()
            ->where('incident_type', 'data_breach')
            ->with(['notifications' => fn ($q) => $q->with('regulator:id,code')])
            ->orderByDesc('detected_at')
            ->limit(200)
            ->get(['id', 'incident_ref', 'title', 'severity', 'detected_at', 'notified_at', 'notification_due_at', 'status']);

        $late = 0;
        $rows = [];

        foreach ($breaches as $breach) {
            $withinWindow = $breach->notified_at && $breach->notification_due_at
                ? $breach->notified_at->lessThanOrEqualTo($breach->notification_due_at)
                : null;

            if ($withinWindow === false) {
                $late++;
            }

            $rows[] = [
                $breach->incident_ref,
                $breach->title,
                $breach->detected_at?->toDateTimeString(),
                $breach->notified_at?->toDateTimeString() ?? 'Not notified',
                match ($withinWindow) {
                    true => 'Within 72 hours',
                    false => 'Late',
                    default => 'Outstanding',
                },
            ];
        }

        return $this->section('BRCH', 'Personal data breach register', [
            $this->field('breach_count', 'Breaches recorded in the period', $breaches->count(), true, 'system'),
            $this->field('breach_late_count', 'Notified outside the 72-hour window', $late, true, 'system',
                $late > 0 ? 'Each late notification is itself reportable.' : null),
            $this->field('breach_rows', 'Breach register', $rows, true, 'system'),
            $this->field('breach_narrative', 'Commentary on breaches and remediation', null, true, 'input'),
        ], [
            'row_templates' => [
                'breach_rows' => ['Reference', 'Description', 'Detected', 'NDPC notified', '72-hour compliance'],
            ],
            'period' => $period,
        ]);
    }

    private function crossBorderSection(): array
    {
        return $this->section('XBORDER', 'Cross-border transfers', [
            $this->field('xborder_rows', 'Transfer register', [], true, 'input',
                'One row per transfer: recipient, country, lawful basis, adequacy evaluation.'),
            $this->field('xborder_adequacy', 'Adequacy evaluation summary', null, true, 'input'),
        ], [
            'row_templates' => [
                'xborder_rows' => ['Recipient', 'Country', 'Lawful basis', 'Adequacy evaluation'],
            ],
        ]);
    }

    private function processorSection(): array
    {
        return $this->section('PROC', 'Processors and data processing agreements', [
            $this->field('processor_rows', 'Processor register', [], true, 'input',
                'One row per processor: name, service, DPA signed on, sub-processors.'),
        ], [
            'row_templates' => [
                'processor_rows' => ['Processor', 'Service', 'DPA signed', 'Sub-processors'],
            ],
        ]);
    }

    private function trainingSection(): array
    {
        return $this->section('TRAIN', 'Data protection training', [
            $this->field('training_delivered', 'Staff trained in the period'),
            $this->field('training_coverage', 'Coverage of staff handling personal data (%)'),
            $this->field('training_evidence', 'Training evidence reference', null, false),
        ]);
    }

    /**
     * The fee band is an officer's answer, not a constant: GAID sets the band
     * by data volume and the platform has not verified the thresholds. The
     * late-filing exposure is computed only from a verified penalty record.
     */
    private function feeSection(array $unverified): array
    {
        $obligation = $this->obligation();
        $verified = $obligation?->verification_status === 'verified';
        $rateBasisPoints = (int) ($obligation?->penalty_amount_minor ?? 0);

        $note = $obligation?->penalty_description
            ?: 'Fee band and late-filing penalty as set by the NDPC.';

        return $this->section('FEE', 'Filing fee and late-filing exposure', [
            $this->field('fee_band_label', 'Fee band applied'),
            $this->field('fee_amount_minor', 'Filing fee (NGN, minor units)', null, true, 'input', $note),
            $this->field('late_penalty_exposure', 'Late-filing exposure', null, false,
                $verified ? 'system' : 'input',
                $verified
                    ? 'Computed at '.number_format($rateBasisPoints / 100, 0).'% of the fee entered above.'
                    : 'The penalty rate on the seeded obligation is unverified, so it is not applied here '
                      .'and the exposure is excluded from the filed return.'),
        ], [
            'penalty_verified' => $verified,
            'penalty_basis_points' => $verified ? $rateBasisPoints : null,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function unverifiedPenalty(): array
    {
        $obligation = $this->obligation();

        if (! $obligation || $obligation->verification_status === 'verified') {
            return [];
        }

        return [[
            'ref' => $obligation->obligation_ref,
            'title' => 'Late-filing penalty rate and fee band',
            'status' => $obligation->verification_status,
            'reason' => 'Excluded from the generated return — the rate has not been verified against the NDPC\'s '
                .'published fee schedule.',
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function tierErrors(array $sections): array
    {
        $fields = collect($sections)->firstWhere('key', 'TIER')['fields'] ?? [];
        $value = fn (string $key) => collect($fields)->firstWhere('key', $key)['value'] ?? null;

        $tier = strtoupper((string) $value('tier'));
        $errors = [];

        if ($tier === '') {
            $errors[] = $this->error('tier_missing', 'Determine the entity tier before filing — it decides whether a return is due at all.');

            return $errors;
        }

        if (! in_array($tier, self::TIERS, true)) {
            $errors[] = $this->error('tier_invalid', "'{$tier}' is not a GAID tier. Use UHL, EHL or OHL.");

            return $errors;
        }

        if (! in_array($tier, self::FILING_TIERS, true)) {
            $errors[] = $this->error(
                'tier_not_filing',
                'An OHL entity does not file a Compliance Audit Return under Article 10. '
                .'Record the determination and close this pack rather than filing it.',
            );

            return $errors;
        }

        if (trim((string) $value('dpco_name')) === '') {
            $errors[] = $this->error(
                'dpco_missing',
                'A UHL or EHL entity must engage a licensed Data Protection Compliance Organisation. '
                .'Name the DPCO before filing.',
            );
        }

        return $errors;
    }

    /** @return array<int, int> */
    private function breachEvidenceIds(): array
    {
        $breachIds = Incident::query()
            ->where('incident_type', 'data_breach')
            ->pluck('id');

        if ($breachIds->isEmpty()) {
            return [];
        }

        return Evidence::query()
            ->where('linked_type', Incident::class)
            ->whereIn('linked_id', $breachIds)
            ->pluck('id')
            ->all();
    }

    /**
     * Compute the late-filing exposure once the officer has entered a fee —
     * but only from a verified penalty rate. With an unverified rate the
     * field stays the officer's own estimate and is excluded from the filed
     * document (R10).
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function applyPenalty(array $sections): array
    {
        return array_map(function (array $section) {
            if ($section['key'] !== 'FEE' || ! ($section['penalty_verified'] ?? false)) {
                return $section;
            }

            $fee = (int) (collect($section['fields'])->firstWhere('key', 'fee_amount_minor')['value'] ?? 0);
            $basisPoints = (int) ($section['penalty_basis_points'] ?? 0);

            if ($fee <= 0 || $basisPoints <= 0) {
                return $section;
            }

            $exposure = Money::of((int) round($fee * $basisPoints / 10000), 'NGN');

            $section['fields'] = array_map(function (array $field) use ($exposure) {
                if ($field['key'] === 'late_penalty_exposure') {
                    $field['value'] = $exposure->format();
                    $field['source'] = 'system';
                }

                return $field;
            }, $section['fields']);

            return $section;
        }, $sections);
    }
}
