<?php

namespace Database\Seeders;

use App\Models\ConsequenceAction;
use App\Models\Control;
use App\Models\ControlEntity;
use App\Models\ControlException;
use App\Models\Evidence;
use App\Models\ImprovementAction;
use App\Models\Investigation;
use App\Models\InvestigationActivity;
use App\Models\InvestigationFinding;
use App\Models\InvestigationSubject;
use App\Models\InvestigationTeamMember;
use App\Models\SpeakUpCase;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EvidenceService;
use App\Services\InvestigationReportBuilder;
use App\Services\LinkageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * CR-04 demo pack.
 *
 * Four investigations, chosen to show the four things the module exists to
 * do rather than four variations of the same one:
 *
 *   1. A COMPLETE completed fraud case, raised from a control exception.
 *      Two named subjects with recorded outcomes and rationales, a
 *      Critical finding against the control that failed, three exhibits in
 *      the shared evidence repository with chain of custody, a dismissal
 *      and a partial recovery, both recommendations tracked as improvement
 *      actions, the provenance edge into the graph — and the draft report,
 *      generated through the real builder and the shared report pipeline
 *      so it carries a genuine checksum and thirteen sections built from
 *      those records.
 *
 *      That last part is the point. A completed investigation with no
 *      exhibits, no tracked remediation and no report is not a completed
 *      investigation; it is a status column that says so.
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
    /**
     * The pack's own fingerprint. Its presence means this seeder has run
     * here before; its absence means it has not, whatever else the register
     * holds.
     */
    private const MARKER_TITLE = 'Suppressed cash lodgements at the counter';

    public function run(): void
    {
        $tenant = Tenant::first();

        if (! $tenant) {
            return;
        }

        // Guard on THIS PACK, not on "has anyone used the module yet".
        // The first version refused to run whenever a single investigation
        // existed, which meant the demo pack could never be added to an
        // install where someone had already opened a real case — exactly
        // when you most want it, and exactly what happened.
        $alreadySeeded = Investigation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('title', self::MARKER_TITLE)
            ->exists();

        if ($alreadySeeded) {
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
            'title' => self::MARKER_TITLE,
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
            'reference' => InvestigationFinding::nextReference('INVF'),
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
            'reference' => ConsequenceAction::nextReference('CON'),
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
            'reference' => ConsequenceAction::nextReference('CON'),
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

        $processChange = ConsequenceAction::create([
            'tenant_id' => $tid,
            'investigation_id' => $fraud->id,
            'reference' => ConsequenceAction::nextReference('CON'),
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

        // ── What makes case 1 a COMPLETE one ────────────────────────
        // A completed investigation with no exhibits, no tracked
        // remediation and no report is not a completed investigation —
        // it is a status column that says so. The rest of this block is
        // the difference, and it runs through the real services rather
        // than writing rows, so the demo exercises the same code an
        // officer does.
        $this->attachExhibits($fraud, $officer);
        $this->closeTheRemediationLoop($fraud, $finding, $processChange, $head);
        $this->linkToOrigin($fraud, $exception);
        $this->issueReport($fraud, $officer);

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

    /**
     * Exhibits, with the chain of custody CR-04 §C.7 added to the shared
     * repository: who COLLECTED it and where it came from, which is the
     * question a disciplinary panel asks and the uploader column cannot
     * answer.
     *
     * Real files on the evidence disk, so the download route and the
     * checksum in the report's evidence register are the genuine article
     * rather than dangling paths.
     */
    private function attachExhibits(Investigation $investigation, User $officer): void
    {
        $exhibits = [
            [
                'file_name' => 'cbs-gl-extract-apr-may.csv',
                'source' => 'Core banking extract, Ubahorep option 13:13',
                'pii' => true,
                'categories' => ['Account numbers', 'Transaction data'],
                'body' => "posting_date,teller_id,account,amount,narration\n"
                    ."2026-04-14,STF-40118,0123456789,180000,Cash lodgement\n"
                    ."2026-04-21,STF-40118,0123456789,240000,Cash lodgement\n"
                    ."2026-05-06,STF-40118,0123456789,315000,Cash lodgement\n",
                'days' => 68,
            ],
            [
                'file_name' => 'till-reconciliation-register.csv',
                'source' => 'Branch 042 register, photographed at the counter',
                'pii' => false,
                'categories' => null,
                'body' => "date,performed_by,evidence_attached\n"
                    ."2026-04-14,,no\n2026-04-15,,no\n2026-04-16,,no\n",
                'days' => 65,
            ],
            [
                'file_name' => 'interview-note-teller.txt',
                'source' => 'Interview under caution, Branch 042, witnessed by the unit head',
                'pii' => true,
                'categories' => ['Names & addresses'],
                'body' => "Interview under caution — 2026-06-25\n\n"
                    .'The subject accepted that eleven lodgements were taken at the counter and not posted, '
                    ."and that the daily till reconciliation had not been performed for the period.\n",
                'days' => 62,
            ],
        ];

        foreach ($exhibits as $exhibit) {
            $path = 'evidence/'.now()->subDays($exhibit['days'])->format('Y/m').'/'.$exhibit['file_name'];

            Storage::disk(EvidenceService::DISK)->put($path, $exhibit['body']);

            Evidence::withoutGlobalScopes()->create([
                'tenant_id' => $investigation->tenant_id,
                'linked_type' => Investigation::class,
                'linked_id' => $investigation->id,
                'file_name' => $exhibit['file_name'],
                'storage_path' => $path,
                'mime_type' => str_ends_with($exhibit['file_name'], '.csv') ? 'text/csv' : 'text/plain',
                'file_size' => strlen($exhibit['body']),
                'checksum' => hash('sha256', $exhibit['body']),
                'contains_personal_data' => $exhibit['pii'],
                'personal_data_categories' => $exhibit['categories'],
                'classification' => 'Confidential',
                'uploaded_by' => $officer->id,
                'uploaded_at' => now()->subDays($exhibit['days']),
                'collected_by' => $officer->id,
                'collected_on' => now()->subDays($exhibit['days'])->toDateString(),
                'collection_source' => $exhibit['source'],
                'description' => 'Exhibit in '.$investigation->reference.'.',
            ]);
        }
    }

    /**
     * §F.1, both directions. The finding's recommendation becomes tracked
     * work, and the approved process change becomes its own. Without this
     * the report's Recommendations column reads "Not yet raised" on a case
     * that is finished, which is the exact gap the section exists to close.
     */
    private function closeTheRemediationLoop(
        Investigation $investigation,
        InvestigationFinding $finding,
        ConsequenceAction $processChange,
        User $owner,
    ): void {
        $remediation = ImprovementAction::withoutGlobalScopes()->create([
            'tenant_id' => $investigation->tenant_id,
            'reference' => ImprovementAction::nextReference('IMP'),
            'source_type' => 'investigation',
            'source_id' => $finding->id,
            'title' => 'Attach the reconciliation output before a checklist line can be signed off',
            'description' => $finding->recommendation,
            'priority' => 'Critical',
            'owner_id' => $owner->id,
            'due_at' => now()->addWeeks(3)->toDateString(),
            'status' => 'In Progress',
            'control_id' => $finding->control_id,
        ]);

        $finding->update(['improvement_action_id' => $remediation->id]);

        $processChange->update([
            'improvement_action_id' => ImprovementAction::withoutGlobalScopes()->create([
                'tenant_id' => $investigation->tenant_id,
                'reference' => ImprovementAction::nextReference('IMP'),
                'source_type' => 'investigation',
                'source_id' => $investigation->id,
                'title' => 'Process change: second signature on charge waivers',
                'description' => $processChange->description,
                'priority' => 'High',
                'owner_id' => $owner->id,
                'due_at' => $processChange->due_date,
                'status' => 'Approved',
            ])->id,
        ]);
    }

    /**
     * §D.2. The morph is the source of truth and the edge is the view; the
     * service writes both in one transaction, so a seeder that writes rows
     * directly has to put the edge back or the Atlas graph shows an
     * investigation with no provenance.
     */
    private function linkToOrigin(Investigation $investigation, ?ControlException $exception): void
    {
        if (! $exception) {
            return;
        }

        app(LinkageService::class)->link(
            'investigation', $investigation->id,
            'exception', $exception->id,
            'relates_to',
        );
    }

    /**
     * The draft report, generated through the real builder and the shared
     * report pipeline — so the demo carries a genuine ReportRun with a
     * checksum, an expiring download token and thirteen sections built from
     * the records above, not a row that claims one exists.
     *
     * Authenticated deliberately: ReportRun is tenant-stamped from the
     * acting user, and the investigation's visibility scope has to admit
     * the actor for the builder to read its own children.
     */
    private function issueReport(Investigation $investigation, User $officer): void
    {
        $previous = Auth::user();
        Auth::login($officer);

        try {
            app(InvestigationReportBuilder::class)->generate($investigation->refresh(), $officer);
        } catch (\Throwable $e) {
            // A demo pack must never be the reason a seed run fails. The
            // investigation is complete either way; the report is a
            // re-runnable artefact.
            $this->command?->warn('Investigation report could not be generated: '.$e->getMessage());
        } finally {
            $previous ? Auth::login($previous) : Auth::logout();
        }
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
