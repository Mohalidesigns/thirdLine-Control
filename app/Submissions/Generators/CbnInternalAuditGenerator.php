<?php

namespace App\Submissions\Generators;

use App\Models\ControlException;
use App\Models\Finding;
use App\Models\SpotCheck;
use App\Models\User;
use App\Submissions\GeneratedPack;
use App\Submissions\SubmissionGenerator;

/**
 * CBN independent external assessment of the internal audit function.
 *
 * The pack the assessor and the CBN both want to see: what the audit universe
 * is, what the risk-based plan said, what was actually executed against it,
 * how issues are tracked and closed, the QAIP evidence, and the evidence that
 * the function is independent. Execution and closure come from live data —
 * those are the numbers a plan-versus-actual conversation turns on — and the
 * assessor's own report is an attached document, never a generated one.
 */
class CbnInternalAuditGenerator extends SubmissionGenerator
{
    public function packType(): string
    {
        return 'CBN/IA-ASSESSMENT';
    }

    public function label(): string
    {
        return 'CBN internal audit external assessment pack';
    }

    public function regulatorCode(): string
    {
        return 'CBN';
    }

    public function frameworkCode(): string
    {
        return 'CBN-CG-2023';
    }

    public function obligationRef(): ?string
    {
        return 'CBN-CG-2023-IA-ASSESS';
    }

    public function description(): string
    {
        return 'Audit universe, risk-based plan and execution, issue tracking and closure rates, QAIP evidence, '
            .'independence evidence and the external assessor\'s report.';
    }

    public function signatories(): array
    {
        return [
            ['role' => 'Chief Internal Auditor'],
            ['role' => 'Chairman, Board Audit Committee'],
            ['role' => 'Independent external assessor'],
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

        $sections = $this->mergeExisting([
            $this->universeSection($entity, $period),
            $this->planSection(),
            $this->issueSection(),
            $this->qaipSection(),
            $this->independenceSection(),
            $this->assessorSection(),
        ], $options['existing'] ?? []);

        $errors = [];

        if (($assessor = $this->assessorAnswer($sections)) === null) {
            $errors[] = $this->error(
                'assessor_report_missing',
                "The external assessor's report is the point of this pack. Attach it and record the assessor "
                .'before filing.',
            );
        } elseif ($assessor === 'No') {
            $errors[] = $this->error(
                'assessment_not_performed',
                'No external assessment has been performed. The CBN return cannot be filed without one.',
            );
        }

        return new GeneratedPack(
            sections: $sections,
            evidenceIds: array_values(array_filter($options['evidence_ids'] ?? [])),
            unverifiedItems: $unverified,
            validationErrors: $errors,
            signatories: $this->signatories(),
            meta: ['period_label' => $period, 'entity' => $entity?->name],
        );
    }

    private function universeSection($entity, string $period): array
    {
        return $this->section('UNIV', 'Audit universe', [
            $this->field('entity_name', 'Institution', $entity?->name, true, 'system'),
            $this->field('period', 'Assessment period', $period, true, 'system'),
            $this->field('universe_rows', 'Audit universe', [], true, 'input',
                'One row per auditable entity: entity, risk rating, last audited, audit cycle.'),
            $this->field('universe_last_refreshed', 'Universe last refreshed'),
        ], [
            'row_templates' => [
                'universe_rows' => ['Auditable entity', 'Risk rating', 'Last audited', 'Cycle'],
            ],
        ]);
    }

    /**
     * Plan against execution. Spot checks are the platform's record of
     * assurance work performed, so completion against plan is answered from
     * them rather than asserted.
     */
    private function planSection(): array
    {
        $checks = SpotCheck::query()
            ->selectRaw("
                count(*) as total,
                sum(case when status in ('Completed','Report Issued') then 1 else 0 end) as completed,
                sum(case when status in ('Draft','In Progress') then 1 else 0 end) as outstanding
            ")
            ->first();

        $total = (int) ($checks->total ?? 0);
        $completed = (int) ($checks->completed ?? 0);

        return $this->section('PLAN', 'Risk-based plan and execution', [
            $this->field('plan_approved_on', 'Plan approved by the audit committee on'),
            $this->field('plan_engagements', 'Engagements in the approved plan'),
            $this->field('executed_engagements', 'Assurance engagements recorded', $total, true, 'system'),
            $this->field('completed_engagements', 'Completed', $completed, true, 'system'),
            $this->field('outstanding_engagements', 'Still in flight', (int) ($checks->outstanding ?? 0), true, 'system'),
            $this->field('execution_rate', 'Completion rate against recorded engagements',
                $total > 0 ? round(($completed / $total) * 100, 1).'%' : '—', true, 'system'),
            $this->field('plan_variance_narrative', 'Explanation of variance against plan', null, true, 'input'),
        ]);
    }

    private function issueSection(): array
    {
        // Findings carry no status of their own — every one raises an
        // exception, and closure is tracked there. Counting both would
        // double-count the same issue.
        $auditFindings = Finding::query()->whereHas('spotCheck')->count();

        $exceptions = ControlException::query()
            ->selectRaw("
                count(*) as total,
                sum(case when status = 'Verified-Closed' then 1 else 0 end) as closed,
                sum(case when is_overdue = 1 and status in ('Open','Assigned','In Progress','Remediated') then 1 else 0 end) as overdue
            ")
            ->first();

        $issueTotal = (int) ($exceptions->total ?? 0);
        $issueClosed = (int) ($exceptions->closed ?? 0);

        return $this->section('ISSUE', 'Issue tracking and closure', [
            $this->field('audit_findings_raised', 'Assurance findings raised', $auditFindings, true, 'system'),
            $this->field('issues_raised', 'Issues on the register', $issueTotal, true, 'system'),
            $this->field('issues_closed', 'Issues closed and verified', $issueClosed, true, 'system'),
            $this->field('issue_closure_rate', 'Closure rate',
                $issueTotal > 0 ? round(($issueClosed / $issueTotal) * 100, 1).'%' : '—', true, 'system'),
            $this->field('issues_overdue', 'Open issues past their target date', (int) ($exceptions->overdue ?? 0),
                true, 'system'),
            $this->field('issue_tracking_narrative', 'How issues are tracked to closure', null, true, 'input'),
        ]);
    }

    private function qaipSection(): array
    {
        return $this->section('QAIP', 'Quality assurance and improvement programme', [
            $this->field('qaip_internal_assessment_date', 'Last internal quality assessment'),
            $this->field('qaip_external_assessment_date', 'Last external quality assessment'),
            $this->field('qaip_conformance', 'Conformance with the IIA Standards', null, true, 'input',
                null, ['Generally conforms', 'Partially conforms', 'Does not conform']),
            $this->field('qaip_improvement_actions', 'Improvement actions arising', [], false, 'input'),
        ], [
            'row_templates' => [
                'qaip_improvement_actions' => ['Action', 'Owner', 'Due', 'Status'],
            ],
        ]);
    }

    private function independenceSection(): array
    {
        return $this->section('IND', 'Independence and reporting lines', [
            $this->field('cia_reports_to', 'Chief Internal Auditor reports functionally to', null, true, 'input'),
            $this->field('cia_admin_reports_to', 'Administrative reporting line'),
            $this->field('committee_access', 'Unrestricted access to the board audit committee', null, true, 'input',
                null, ['Yes', 'No']),
            $this->field('independence_impairments', 'Impairments to independence in the period', null, true, 'input',
                'State "None" where there were none — a blank is not an answer.'),
        ]);
    }

    private function assessorSection(): array
    {
        return $this->section('ASSESS', 'External assessor', [
            $this->field('assessment_performed', 'External assessment performed in the period', null, true, 'input',
                null, ['Yes', 'No']),
            $this->field('assessor_name', 'External assessor'),
            $this->field('assessment_date', 'Assessment date'),
            $this->field('assessment_opinion', 'Assessor opinion', null, true, 'input'),
            $this->field('assessment_report_evidence', 'Assessor report attached as evidence', null, true, 'input',
                'Attach the report to this pack before filing.'),
        ]);
    }

    private function assessorAnswer(array $sections): ?string
    {
        $fields = collect($sections)->firstWhere('key', 'ASSESS')['fields'] ?? [];
        $value = collect($fields)->firstWhere('key', 'assessment_performed')['value'] ?? null;

        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }
}
