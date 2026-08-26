<?php

namespace Database\Seeders;

use App\Models\ConsequenceAction;
use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlException;
use App\Models\Investigation;
use App\Models\InvestigationActivity;
use App\Models\InvestigationFinding;
use App\Models\InvestigationSubject;
use App\Models\InvestigationTeamMember;
use App\Models\SpeakUpCase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * CR-04 demo pack.
 *
 * Four investigations, chosen to show the four things the module exists to
 * do rather than four variations of the same one:
 *
 *   1. A completed fraud case, raised from a control exception, with a
 *      named subject, a culpable outcome, a finding against the control
 *      that failed, a dismissal and a partial recovery.
 *   2. A live conflict-of-interest case raised from a Speak Up report:
 *      confidential, LOCKED, its team taken from the case allowlist, and
 *      no reporter anywhere in it.
 *   3. An asset-misappropriation case with a system_process subject and no
 *      human named yet — the state the register is actually in most of the
 *      time, and the one that catches "every investigation has a person".
 *   4. A suspended case, waiting on a police report, so the dashboard's
 *      suspended bucket has something in it and the ageing widget can be
 *      seen doing the right thing.
 *
 * Written without an authenticated user, so tenancy and references are
 * stamped explicitly rather than inherited from a request (R-I).
 */
class InvestigationDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first();

        if (! $tenant || Investigation::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        $tid = $tenant->id;

        $head = $this->userWithRole($tid, 'Control Function Head');
        $officer = $this->userWithRole($tid, 'Control Officer');
        $owner = $this->userWithRole($tid, 'Control Owner');

        if (! $head || ! $officer) {
            return;
        }

        $branch = ControlEntity::withoutGlobalScopes()->where('tenant_id', $tid)
            ->where('entity_kind', 'branch')->first();
        $control = Control::withoutGlobalScopes()->where('tenant_id', $tid)->first();
        $exception = ControlException::withoutGlobalScopes()->where('tenant_id', $tid)->first();
        $speakUp = SpeakUpCase::withoutGlobalScopes()->where('tenant_id', $tid)
            ->where('case_type', 'whistleblowing')->first();

        // ── 1. The completed fraud case ─────────────────────────────
        $fraud = $this->make($tid, [
            'title' => 'Suppressed cash lodgements at the counter',
            'category' => 'fraud',
            'source' => 'control_exception',
            'priority' => 'Critical',
            'risk_rating' => 'Critical',
            'status' => 'completed',
            'control_entity_id' => $branch?->id,
            'origin_type' => $exception ? ControlException::class : null,
            'origin_id' => $exception?->id,
            'lead_investigator_id' => $officer->id,
            'reported_date' => now()->subMonths(3)->toDateString(),
            'commenced_date' => now()->subMonths(3)->addDays(2)->toDateString(),
            'target_completion_date' => now()->subMonth()->toDateString(),
            'completed_date' => now()->subMonth()->addDays(4)->toDateString(),
            'estimated_financial_impact' => 4200000,
            'confirmed_financial_loss' => 3850000,
            'background' => 'A branch reconciliation flagged eleven customer lodgements with no matching credit over a five-week period.',
            'scope' => 'Branch counter operations, 1 April to 10 May.',
            'objectives' => 'Establish whether lodgements were suppressed, by whom, and over what period.',
            'methodology' => 'Core banking extract review, CCTV review of the counter, four interviews under caution, and a walkthrough of the daily till reconciliation.',
            'conclusion' => 'Eleven lodgements totalling ₦3.85m were suppressed at the counter by a single teller. The daily till reconciliation, which would have caught the first of them, was not performed for the whole period.',
        ], $head, $officer);

        $teller = InvestigationSubject::create([
            'tenant_id' => $tid,
            'investigation_id' => $fraud->id,
            'subject_type' => 'staff',
            'name' => 'Chinedu Okafor',
            'staff_id' => 'STF-40118',
            'department' => 'Branch Operations',
            'position' => 'Teller',
            'role_in_case' => 'primary_subject',
            'outcome' => 'culpable',
            'outcome_rationale' => 'Admitted eleven suppressions in interview; CCTV and the core banking extract corroborate each one.',
            'outcome_recorded_on' => now()->subMonth()->addDays(3)->toDateString(),
            'outcome_recorded_by' => $officer->id,
        ]);

        InvestigationSubject::create([
            'tenant_id' => $tid,
            'investigation_id' => $fraud->id,
            'subject_type' => 'staff',
            'name' => 'Amaka Eze',
            'staff_id' => 'STF-31904',
            'department' => 'Branch Operations',
            'position' => 'Branch Operations Manager',
            'role_in_case' => 'person_of_interest',
            'outcome' => 'partially_culpable',
            'outcome_rationale' => 'Did not perform the daily till reconciliation for five consecutive weeks. No evidence of complicity.',
            'outcome_recorded_on' => now()->subMonth()->addDays(3)->toDateString(),
            'outcome_recorded_by' => $officer->id,
        ]);

        $finding = InvestigationFinding::create([
            'tenant_id' => $tid,
            'investigation_id' => $fraud->id,
            'reference' => 'INVF-'.now()->year.'-001',
            'title' => 'The daily till reconciliation did not operate for five weeks',
            'severity' => 'Critical',
            'description' => 'The control was signed off as performed on the checklist but no reconciliation was produced.',
            'root_cause' => 'The branch treated the sign-off as an attendance record rather than a control.',
            'control_failure' => 'The detective control that would have caught the first suppression did not operate at all.',
            'recommendation' => 'Require the reconciliation output to be attached as evidence before the checklist line can be signed off.',
            'financial_impact' => 3850000,
            'control_id' => $control?->id,
            'exception_id' => $exception?->id,
            'raised_by' => $officer->id,
            'established_on' => now()->subMonth()->addDays(2)->toDateString(),
        ]);

        ConsequenceAction::create([
            'tenant_id' => $tid,
            'investigation_id' => $fraud->id,
            'investigation_subject_id' => $teller->id,
            'reference' => 'CON-'.now()->year.'-001',
            'action_type' => 'dismissal',
            'description' => 'Summary dismissal for gross misconduct.',
            'status' => 'implemented',
            'recommended_by' => $officer->id,
            'recommended_on' => now()->subMonth()->addDays(4)->toDateString(),
            'approved_by' => $head->id,
            'approved_on' => now()->subMonth()->addDays(6)->toDateString(),
            'implemented_on' => now()->subMonth()->addDays(9)->toDateString(),
            'implemented_by' => $head->id,
            'implementation_note' => 'Effected by HR; letter served and acknowledged.',
        ]);

        ConsequenceAction::create([
            'tenant_id' => $tid,
            'investigation_id' => $fraud->id,
            'investigation_subject_id' => $teller->id,
            'reference' => 'CON-'.now()->year.'-002',
            'action_type' => 'restitution_recovery',
            'description' => 'Recovery from terminal benefits and a signed repayment undertaking.',
            'status' => 'implemented',
            'recommended_by' => $officer->id,
            'recommended_on' => now()->subMonth()->addDays(4)->toDateString(),
            'approved_by' => $head->id,
            'approved_on' => now()->subMonth()->addDays(6)->toDateString(),
            'implemented_on' => now()->subDays(20)->toDateString(),
            'implemented_by' => $head->id,
            'amount_recovered' => 1420000,
        ]);

        ConsequenceAction::create([
            'tenant_id' => $tid,
            'investigation_id' => $fraud->id,
            'reference' => 'CON-'.now()->year.'-003',
            'action_type' => 'process_change',
            'description' => 'Evidence must be attached before a reconciliation checklist line can be signed off.',
            'status' => 'approved',
            'recommended_by' => $officer->id,
            'recommended_on' => now()->subMonth()->addDays(4)->toDateString(),
            'approved_by' => $head->id,
            'approved_on' => now()->subMonth()->addDays(6)->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
        ]);

        $fraud->update(['amount_recovered' => 1420000]);

        $this->diary($fraud, $officer, [
            ['case_created', 'Investigation opened from a control exception.', 90],
            ['team_assigned', 'Team assembled: lead investigator and one reviewer.', 89],
            ['interview_conducted', 'Interview with the branch operations manager.', 70],
            ['evidence_collected', 'Core banking extract obtained for the period.', 68],
            ['site_visit', 'Counter observation and CCTV review at the branch.', 65],
            ['interview_conducted', 'Interview under caution with the teller.', 62],
            ['finding_added', 'Critical finding recorded against the till reconciliation.', 34],
            ['action_recommended', 'Dismissal and recovery recommended.', 32],
            ['case_completed', 'Investigation completed and rated Critical.', 30],
        ]);

        // ── 2. The Speak Up case: confidential and locked ────────────
        if ($speakUp) {
            $whistle = $this->make($tid, [
                'title' => 'Procurement awards concentrated on one vendor',
                'category' => 'conflict_of_interest',
                'source' => 'whistleblowing',
                'priority' => 'High',
                'status' => 'under_investigation',
                'is_confidential' => true,
                'confidentiality_locked' => true,
                'origin_type' => SpeakUpCase::class,
                'origin_id' => $speakUp->id,
                'lead_investigator_id' => $head->id,
                'reported_date' => now()->subWeeks(3)->toDateString(),
                'commenced_date' => now()->subWeeks(3)->addDay()->toDateString(),
                'target_completion_date' => now()->addWeeks(3)->toDateString(),
                'background' => 'A Speak Up report alleged three consecutive contract awards to one vendor without a competitive process.',
                'scope' => 'Procurement awards over ₦5m in the last four quarters.',
            ], $head, null);

            // The team is the case allowlist — never the request (§D.3-2).
            foreach (array_map('intval', $speakUp->access_user_ids ?? []) as $memberId) {
                if ($memberId !== $whistle->lead_investigator_id && User::withoutGlobalScopes()->whereKey($memberId)->exists()) {
                    InvestigationTeamMember::firstOrCreate(
                        ['investigation_id' => $whistle->id, 'user_id' => $memberId],
                        ['tenant_id' => $tid, 'role' => 'investigator', 'assigned_at' => now()->subWeeks(3), 'assigned_by' => $head->id],
                    );
                }
            }

            InvestigationSubject::create([
                'tenant_id' => $tid,
                'investigation_id' => $whistle->id,
                'subject_type' => 'vendor',
                'name' => 'Adeyemi Trading Limited',
                'role_in_case' => 'primary_subject',
                'outcome' => 'pending',
            ]);

            $this->diary($whistle, $head, [
                ['case_created', 'Investigation opened from a Speak Up report.', 21],
                ['document_requested', 'Procurement files requested for the three awards.', 18],
                ['confidential_view', 'Confidential case file opened.', 4],
            ]);
        }

        // ── 3. No human subject yet ─────────────────────────────────
        $assets = $this->make($tid, [
            'title' => 'Unreconciled write-offs in the suspense account',
            'category' => 'asset_misappropriation',
            'source' => 'control_test_failure',
            'priority' => 'High',
            'status' => 'under_investigation',
            'control_entity_id' => $branch?->id,
            'lead_investigator_id' => $officer->id,
            'reported_date' => now()->subWeeks(2)->toDateString(),
            'commenced_date' => now()->subWeeks(2)->toDateString(),
            'target_completion_date' => now()->subDays(3)->toDateString(),
            'estimated_financial_impact' => 900000,
            'background' => 'A failed control test found sixteen write-offs posted to suspense with no supporting approval.',
        ], $head, $officer);

        InvestigationSubject::create([
            'tenant_id' => $tid,
            'investigation_id' => $assets->id,
            'subject_type' => 'system_process',
            'name' => 'Suspense account write-off posting route',
            'role_in_case' => 'primary_subject',
            'outcome' => 'pending',
        ]);

        $this->diary($assets, $officer, [
            ['case_created', 'Investigation opened from a failed control test.', 14],
            ['evidence_collected', 'Sixteen write-off postings extracted from the core banking system.', 12],
        ]);

        // ── 4. Suspended, waiting on the police ─────────────────────
        $suspended = $this->make($tid, [
            'title' => 'ATM cassette shortfall referred to the police',
            'category' => 'fraud',
            'source' => 'system_alert',
            'priority' => 'High',
            'status' => 'suspended',
            'control_entity_id' => $branch?->id,
            'lead_investigator_id' => $officer->id,
            'reported_date' => now()->subMonths(5)->toDateString(),
            'commenced_date' => now()->subMonths(5)->addDays(2)->toDateString(),
            'target_completion_date' => now()->subMonths(3)->toDateString(),
            'estimated_financial_impact' => 2100000,
            'background' => 'A cassette shortfall of ₦2.1m was reported to the police; the internal investigation is held pending their report.',
        ], $head, $officer);

        $this->diary($suspended, $officer, [
            ['case_created', 'Investigation opened following an ATM alert.', 150],
            ['report_issued', 'Police report lodged at the divisional headquarters.', 120],
            ['status_changed', 'Status changed from under_investigation to suspended.', 118],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function make(int $tenantId, array $attributes, User $creator, ?User $lead): Investigation
    {
        $next = Investigation::withoutGlobalScopes()->where('tenant_id', $tenantId)->count() + 1;

        $investigation = Investigation::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'reference' => sprintf('INV-%d-%03d', now()->year, $next),
            'currency' => 'NGN',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            ...$attributes,
        ]);

        $leadId = $investigation->lead_investigator_id;

        if ($leadId) {
            InvestigationTeamMember::firstOrCreate(
                ['investigation_id' => $investigation->id, 'user_id' => $leadId],
                ['tenant_id' => $tenantId, 'role' => 'lead', 'assigned_at' => $investigation->reported_date, 'assigned_by' => $creator->id],
            );
        }

        if ($lead && $lead->id !== $leadId) {
            InvestigationTeamMember::firstOrCreate(
                ['investigation_id' => $investigation->id, 'user_id' => $lead->id],
                ['tenant_id' => $tenantId, 'role' => 'reviewer', 'assigned_at' => $investigation->reported_date, 'assigned_by' => $creator->id],
            );
        }

        return $investigation;
    }

    /** @param array<int, array{0: string, 1: string, 2: int}> $entries */
    private function diary(Investigation $investigation, User $actor, array $entries): void
    {
        foreach ($entries as [$type, $title, $daysAgo]) {
            InvestigationActivity::withoutGlobalScopes()->create([
                'tenant_id' => $investigation->tenant_id,
                'investigation_id' => $investigation->id,
                'activity_type' => $type,
                'title' => $title,
                'activity_date' => now()->subDays($daysAgo),
                'performed_by' => $actor->id,
            ]);
        }
    }

    private function userWithRole(int $tenantId, string $role): ?User
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereHas('roles', fn ($q) => $q->where('name', $role))
            ->first();
    }
}
