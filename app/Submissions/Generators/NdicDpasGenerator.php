<?php

namespace App\Submissions\Generators;

use App\Models\Control;
use App\Models\ImprovementAction;
use App\Models\ObligationInstance;
use App\Models\RiskAppetite;
use App\Models\User;
use App\Submissions\GeneratedPack;
use App\Submissions\SubmissionGenerator;
use App\Support\Money;

/**
 * NDIC Differential Premium Assessment System — the management-factor pack.
 *
 * The commercial point of this return is the last section: the management
 * factors do not just get a rating, they get a basis-point add-on to the
 * deposit insurance premium. Putting that in money on the same page as the
 * control evidence is what makes the board care about a late return.
 *
 * The add-on schedule itself is a regulator's table, so it is tenant data
 * (settings → ndic_dpas → add_on_basis_points), not a constant in this class
 * (R1). With no configured schedule the pack reports the factor positions and
 * says plainly that the premium impact cannot be computed — an invented
 * basis-point figure on a board paper is worse than none (R10).
 */
class NdicDpasGenerator extends SubmissionGenerator
{
    /** The management factors this pack answers, in return order. */
    private const FACTORS = [
        'M-RET' => 'Timeliness of returns',
        'M-EXAM' => 'Closure of examiner recommendations',
        'M-IC' => 'Internal control effectiveness',
        'M-RM' => 'Risk management framework',
        'M-REP' => 'Financial reporting accuracy',
    ];

    public function packType(): string
    {
        return 'NDIC/DPAS';
    }

    public function label(): string
    {
        return 'NDIC DPAS management-factor pack';
    }

    public function regulatorCode(): string
    {
        return 'NDIC';
    }

    public function frameworkCode(): string
    {
        return 'NDIC-DPAS';
    }

    public function obligationRef(): ?string
    {
        return 'NDIC-DPAS-RETURNS';
    }

    public function description(): string
    {
        return 'Return-submission timeliness, examiner-recommendation closure, internal control effectiveness and '
            .'risk management status, with the computed basis-point premium impact.';
    }

    public function signatories(): array
    {
        return [
            ['role' => 'Chief Financial Officer'],
            ['role' => 'Chief Risk Officer'],
            ['role' => 'Managing Director / Chief Executive Officer'],
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

        $schedule = $this->schedule($user);
        $factors = $this->factorPositions();

        $sections = $this->mergeExisting([
            $this->headerSection($entity, $period),
            $this->timelinessSection($factors['M-RET']),
            $this->examinerSection($factors['M-EXAM']),
            $this->controlSection($factors['M-IC']),
            $this->riskSection($factors['M-RM']),
            $this->premiumSection($factors, $schedule, $verified),
        ], $options['existing'] ?? []);

        $sections = $this->applyPremium($sections, $factors, $schedule);

        $errors = [];

        if ($schedule === []) {
            $errors[] = $this->error(
                'dpas_schedule_missing',
                'No verified DPAS add-on schedule is configured, so the basis-point premium impact is not computed. '
                .'Configure it under tenant settings once the schedule has been checked against the NDIC framework.',
                false,
            );
        }

        return new GeneratedPack(
            sections: $sections,
            unverifiedItems: array_merge($unverified, $schedule === [] ? [[
                'ref' => 'NDIC-DPAS-ADD-ON',
                'title' => 'DPAS add-on basis-point schedule',
                'status' => 'unconfigured',
                'reason' => 'Excluded from the pack — no verified add-on schedule is configured for this tenant.',
            ]] : []),
            validationErrors: $errors,
            signatories: $this->signatories(),
            meta: [
                'period_label' => $period,
                'entity' => $entity?->name,
                'factors' => $factors,
                'schedule_configured' => $schedule !== [],
            ],
        );
    }

    /** @return array<string, int> factor ref => basis points */
    private function schedule(User $user): array
    {
        $schedule = $user->tenant?->settings['ndic_dpas']['add_on_basis_points'] ?? [];

        return is_array($schedule)
            ? array_map('intval', array_intersect_key($schedule, self::FACTORS))
            : [];
    }

    /**
     * Each management factor's live position: adverse or not, and the number
     * behind it. The add-on applies where the factor is adverse.
     *
     * @return array<string, array<string, mixed>>
     */
    private function factorPositions(): array
    {
        $returns = ObligationInstance::query()
            ->whereIn('status', ['Submitted', 'Accepted', 'Rejected', 'Overdue'])
            ->selectRaw('
                count(*) as total,
                sum(case when submitted_at is not null and due_at is not null and submitted_at > due_at then 1 else 0 end) as late,
                sum(case when days_overdue > 0 then 1 else 0 end) as overdue
            ')
            ->first();

        $lateReturns = (int) ($returns->late ?? 0) + (int) ($returns->overdue ?? 0);

        $examiner = ImprovementAction::query()
            ->where('source_type', 'audit')
            ->selectRaw("
                count(*) as total,
                sum(case when status in ('Implemented','Verified') then 1 else 0 end) as closed
            ")
            ->first();

        $examinerTotal = (int) ($examiner->total ?? 0);
        $examinerClosed = (int) ($examiner->closed ?? 0);
        $examinerRate = $examinerTotal > 0 ? ($examinerClosed / $examinerTotal) * 100 : 100.0;

        $controls = Control::query()
            ->where('is_template', false)
            ->where('status', 'Active')
            ->where('is_key_control', true)
            ->selectRaw("
                count(*) as total,
                sum(case when operating_effectiveness = 'Effective' then 1 else 0 end) as effective
            ")
            ->first();

        $controlTotal = (int) ($controls->total ?? 0);
        $controlEffective = (int) ($controls->effective ?? 0);
        $controlRate = $controlTotal > 0 ? ($controlEffective / $controlTotal) * 100 : 0.0;

        $appetiteApproved = RiskAppetite::query()->active()->whereNotNull('approved_at')->exists();

        return [
            'M-RET' => [
                'label' => self::FACTORS['M-RET'],
                'measure' => $lateReturns.' late or overdue return(s) of '.(int) ($returns->total ?? 0),
                'adverse' => $lateReturns > 0,
            ],
            'M-EXAM' => [
                'label' => self::FACTORS['M-EXAM'],
                'measure' => round($examinerRate, 1).'% of examiner recommendations closed',
                'adverse' => $examinerRate < 100,
            ],
            'M-IC' => [
                'label' => self::FACTORS['M-IC'],
                'measure' => round($controlRate, 1).'% of key controls rated effective',
                'adverse' => $controlRate < 90,
            ],
            'M-RM' => [
                'label' => self::FACTORS['M-RM'],
                'measure' => $appetiteApproved
                    ? 'Board-approved risk appetite in force'
                    : 'No board-approved risk appetite statement in force',
                'adverse' => ! $appetiteApproved,
            ],
            'M-REP' => [
                'label' => self::FACTORS['M-REP'],
                'measure' => 'Assessed by management',
                'adverse' => null,
            ],
        ];
    }

    private function headerSection($entity, string $period): array
    {
        return $this->section('HDR', 'Institution and period', [
            $this->field('entity_name', 'Insured institution', $entity?->name, true, 'system'),
            $this->field('period', 'Assessment period', $period, true, 'system'),
            $this->field('deposit_base_minor', 'Assessable deposit base (NGN, minor units)', null, true, 'input',
                'The base the premium and any add-on are applied to.'),
        ]);
    }

    private function timelinessSection(array $factor): array
    {
        $instances = ObligationInstance::query()
            ->with(['obligation:id,title,obligation_ref'])
            ->whereIn('status', ['Submitted', 'Accepted', 'Rejected', 'Overdue'])
            ->orderByDesc('due_at')
            ->limit(50)
            ->get(['id', 'obligation_id', 'period_label', 'due_at', 'submitted_at', 'status', 'days_overdue']);

        return $this->section('M-RET', 'Return-submission timeliness', [
            $this->field('returns_position', 'Position', $factor['measure'], true, 'system'),
            $this->field('returns_rows', 'Return submission record', $instances->map(fn (ObligationInstance $instance) => [
                $instance->obligation?->title,
                $instance->period_label,
                $instance->due_at?->toDateString(),
                $instance->submitted_at?->toDateString() ?? 'Not submitted',
                $instance->submitted_at && $instance->due_at && $instance->submitted_at->greaterThan($instance->due_at)
                    ? 'Late'
                    : ($instance->days_overdue > 0 ? 'Overdue' : 'On time'),
            ])->values()->all(), true, 'system'),
            $this->field('returns_narrative', 'Explanation of any late filing', null, true, 'input'),
        ], [
            'row_templates' => [
                'returns_rows' => ['Return', 'Period', 'Due', 'Submitted', 'Timeliness'],
            ],
        ]);
    }

    private function examinerSection(array $factor): array
    {
        $actions = ImprovementAction::query()
            ->where('source_type', 'audit')
            ->with('owner:id,name')
            ->orderBy('due_at')
            ->limit(50)
            ->get(['id', 'reference', 'title', 'status', 'due_at', 'owner_id']);

        return $this->section('M-EXAM', 'Examiner recommendation closure', [
            $this->field('examiner_position', 'Position', $factor['measure'], true, 'system'),
            $this->field('examiner_rows', 'Recommendation status', $actions->map(fn (ImprovementAction $action) => [
                $action->reference,
                $action->title,
                $action->owner?->name ?? 'Unassigned',
                $action->due_at?->toDateString(),
                $action->status,
            ])->values()->all(), true, 'system'),
            $this->field('examiner_narrative', 'Explanation of outstanding recommendations', null, true, 'input'),
        ], [
            'row_templates' => [
                'examiner_rows' => ['Reference', 'Recommendation', 'Owner', 'Due', 'Status'],
            ],
        ]);
    }

    private function controlSection(array $factor): array
    {
        return $this->section('M-IC', 'Internal control effectiveness', [
            $this->field('control_position', 'Position', $factor['measure'], true, 'system'),
            $this->field('control_narrative', 'Summary of the internal control environment', null, true, 'input'),
        ]);
    }

    private function riskSection(array $factor): array
    {
        return $this->section('M-RM', 'Risk management framework', [
            $this->field('risk_position', 'Position', $factor['measure'], true, 'system'),
            $this->field('risk_framework_reviewed_on', 'Framework last reviewed'),
            $this->field('risk_narrative', 'Summary of the risk management framework', null, true, 'input'),
        ]);
    }

    private function premiumSection(array $factors, array $schedule, $verified): array
    {
        $fields = [];

        foreach (self::FACTORS as $ref => $label) {
            $configured = array_key_exists($ref, $schedule);

            $fields[] = $this->field(
                'addon_'.strtolower(str_replace('-', '_', $ref)),
                $label.' add-on',
                null,
                false,
                $configured ? 'system' : 'input',
                $configured
                    ? ($factors[$ref]['adverse'] === true
                        ? 'Adverse — '.$schedule[$ref].' basis point(s) applied.'
                        : 'Not adverse — no add-on.')
                    : 'No verified add-on rate is configured for this factor.',
            );
        }

        $fields[] = $this->field('addon_total_basis_points', 'Total add-on (basis points)', null,
            false, $schedule !== [] ? 'system' : 'input');
        $fields[] = $this->field('addon_premium_impact', 'Premium impact on the deposit base', null,
            false, $schedule !== [] ? 'system' : 'input');
        $fields[] = $this->field('premium_narrative', 'Board commentary on the premium consequence', null, true, 'input');

        return $this->section('PREM', 'Premium impact', $fields, [
            'schedule' => $schedule,
            'verified_requirements' => $verified->pluck('ref_code')->all(),
        ]);
    }

    /**
     * The arithmetic, applied only when a schedule exists and the officer has
     * entered a deposit base. Basis points × base ÷ 10,000, in integer minor
     * units throughout — a premium computed through a float is a premium
     * nobody can reconcile (R7).
     */
    private function applyPremium(array $sections, array $factors, array $schedule): array
    {
        if ($schedule === []) {
            return $sections;
        }

        $headerFields = collect($sections)->firstWhere('key', 'HDR')['fields'] ?? [];
        $base = (int) (collect($headerFields)->firstWhere('key', 'deposit_base_minor')['value'] ?? 0);

        $totalBasisPoints = 0;

        foreach ($schedule as $ref => $basisPoints) {
            if (($factors[$ref]['adverse'] ?? null) === true) {
                $totalBasisPoints += $basisPoints;
            }
        }

        $impact = $base > 0
            ? Money::of((int) round($base * $totalBasisPoints / 10000), 'NGN')->format()
            : 'Enter the deposit base to compute';

        return array_map(function (array $section) use ($totalBasisPoints, $impact) {
            if ($section['key'] !== 'PREM') {
                return $section;
            }

            $section['fields'] = array_map(function (array $field) use ($totalBasisPoints, $impact) {
                if ($field['key'] === 'addon_total_basis_points') {
                    $field['value'] = $totalBasisPoints;
                }

                if ($field['key'] === 'addon_premium_impact') {
                    $field['value'] = $impact;
                }

                return $field;
            }, $section['fields']);

            return $section;
        }, $sections);
    }
}
