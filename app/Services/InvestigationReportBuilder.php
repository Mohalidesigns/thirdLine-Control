<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\ControlException;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\InvestigationActivity;
use App\Models\InvestigationReport;
use App\Models\InvestigationTeamMember;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\SpeakUpCase;
use App\Models\TestInstance;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The investigation report (CR-04 §E.3).
 *
 * The section builder is ported; the delivery pipeline is reused. Nine of
 * the thirteen sections are GENERATED from the child tables — the parties
 * table from subjects, the chronology from the diary, the evidence
 * register from the shared repository — and only four come from what an
 * investigator typed. That is what stops the report and the record drifting
 * apart.
 *
 * Delivery goes through ReportDesignerService::runWithDocument(), the same
 * hook the regulatory submission packs use for content assembled
 * elsewhere. That buys the run record, the checksum, the expiring download
 * token, the report.generated audit entry, confidentiality-aware
 * distribution and the PDF/DOCX/XLSX engines for nothing.
 *
 * Regeneration is blocked once a run exists. A new version of an
 * investigation report is an explicit act, not a side effect of pressing
 * the button twice.
 */
class InvestigationReportBuilder
{
    /** The system definition seeded by ReportDefinitionSeeder. */
    public const DEFINITION_CODE = 'INV-REPORT';

    /** The thirteen sections, in the order they are read. */
    public const SECTIONS = [
        'background', 'scope', 'objectives', 'methodology', 'parties', 'chronology',
        'findings_of_fact', 'financial_implication', 'root_cause',
        'consequence_management', 'recommendations', 'conclusion', 'evidence_register',
    ];

    public function __construct(private ReportDesignerService $reports) {}

    /**
     * Generate the draft report. Always a draft: the investigator edits it
     * and routes it through the normal review flow, exactly as in the
     * source module.
     */
    public function generate(Investigation $investigation, User $user, string $format = 'pdf'): ReportRun
    {
        if ($this->hasReport($investigation)) {
            throw ValidationException::withMessages([
                'report' => "A report has already been generated for {$investigation->reference}. Issuing a new version is a deliberate act — supersede the existing run rather than regenerating over it.",
            ]);
        }

        $run = $this->reports->runWithDocument(
            $this->definition($investigation),
            $user,
            $format,
            $this->document($investigation, $user),
            ['investigation_id' => $investigation->id, 'reference' => $investigation->reference],
        );

        if ($run->status === 'Completed') {
            // The run is put on the chronology, which is also how
            // hasReport() answers — one fact, not two that can disagree.
            InvestigationActivity::create([
                'tenant_id' => $investigation->tenant_id,
                'investigation_id' => $investigation->id,
                'activity_type' => 'report_issued',
                'title' => "Draft investigation report generated ({$run->run_ref}).",
                'activity_date' => now(),
                'performed_by' => $user->id,
                'linked_type' => ReportRun::class,
                'linked_id' => $run->id,
            ]);
        }

        return $run;
    }

    /**
     * Render an ISSUED report from its frozen snapshot.
     *
     * Deliberately bypasses the once-only guard on generate(): that guard
     * stops an investigator producing a second draft by pressing the
     * button twice, and this is not that. The document handed to the
     * engine comes from `snapshot`, so what gets rendered is what was
     * approved — not what the case looks like at the moment of rendering.
     */
    public function renderSnapshot(InvestigationReport $report, User $user, string $format = 'pdf'): ReportRun
    {
        $investigation = $report->investigation;

        if (! $report->snapshot) {
            throw ValidationException::withMessages([
                'report' => "{$report->report_number} has no frozen snapshot to render.",
            ]);
        }

        return $this->reports->runWithDocument(
            $this->definition($investigation),
            $user,
            $format,
            $report->snapshot,
            [
                'investigation_id' => $investigation->id,
                'reference' => $investigation->reference,
                'report_number' => $report->report_number,
                'version' => $report->version,
                'issued' => true,
            ],
        );
    }

    public function hasReport(Investigation $investigation): bool
    {
        return $investigation->activities()
            ->where('activity_type', 'report_issued')
            ->where('linked_type', ReportRun::class)
            ->exists();
    }

    /** @return Collection<int, ReportRun> */
    public function runsFor(Investigation $investigation)
    {
        $ids = $investigation->activities()
            ->where('activity_type', 'report_issued')
            ->where('linked_type', ReportRun::class)
            ->pluck('linked_id');

        return ReportRun::query()
            ->whereIn('id', $ids)
            ->with('requester:id,name')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * The system definition — seeded by ReportDefinitionSeeder, but
     * created on demand too, because a tenant that has not re-run the
     * seeder should meet a report rather than a stack trace.
     *
     * Its stored sections are a DESCRIPTION of the document, not a recipe
     * the designer could resolve on its own: the thirteen real sections are
     * assembled per investigation by this class. That is deliberate, and
     * the seeded narrative says so, so nobody runs it from the library
     * expecting a case file.
     */
    public function definition(Investigation $investigation): ReportDefinition
    {
        return ReportDefinition::firstOrCreate(
            ['tenant_id' => $investigation->tenant_id, 'code' => self::DEFINITION_CODE],
            [
                'name' => 'Investigation report',
                'description' => 'Parties, chronology, findings of fact, financial implication, consequence management and the evidence register for one investigation.',
                'report_type' => 'operational',
                'output_formats' => ['pdf', 'docx'],
                'sections' => [
                    ['type' => 'cover', 'title' => 'Investigation Report'],
                    [
                        'type' => 'narrative',
                        'title' => 'About this report',
                        'body' => 'Generated from a single investigation, from the Report tab of the investigation itself.',
                    ],
                ],
                'confidentiality' => 'Confidential',
                'version_no' => 1,
                'is_system' => true,
                'is_active' => true,
            ],
        );
    }

    /**
     * The engine-ready document.
     *
     * @return array<string, mixed>
     */
    public function document(Investigation $investigation, User $user): array
    {
        $investigation->loadMissing([
            'subjects', 'findings.control', 'findings.improvementAction',
            'consequenceActions.subject', 'consequenceActions.approver',
            'evidence.uploader', 'evidence.collector', 'leadInvestigator',
            'controlEntity', 'organisationUnit',
        ]);

        $sections = [$this->cover($investigation)];

        foreach ($this->sections($investigation) as $section) {
            $sections[] = $section;
        }

        $sections[] = $this->signatureBlock($investigation);

        return [
            'code' => self::DEFINITION_CODE,
            'title' => 'Investigation Report — '.$investigation->title,
            'subtitle' => $investigation->reference,
            'report_type' => 'operational',
            'confidentiality' => $investigation->is_confidential ? 'Board' : 'Confidential',
            'generated_at' => now()->format('d M Y H:i'),
            'generated_by' => $user->name,
            'context' => [
                'tenant' => $user->tenant?->name ?? config('app.name'),
                'period' => $investigation->reported_date?->format('F Y') ?? '',
                'entity' => $investigation->controlEntity?->name ?? 'Group-wide',
            ],
            'styling' => [],
            'header' => ['title' => 'Investigation Report', 'subtitle' => $investigation->reference],
            'footer' => ['text' => ($investigation->is_confidential ? 'CONFIDENTIAL — ' : '').'Investigation '.$investigation->reference],
            'cover' => [],
            'sections' => $sections,
        ];
    }

    /**
     * The thirteen content sections. Kept separate from document() so the
     * completeness of the report can be asserted without rendering a PDF.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sections(Investigation $investigation): array
    {
        return [
            $this->narrative('background', $this->backgroundBody($investigation)),
            $this->narrative('scope', $investigation->scope),
            $this->narrative('objectives', $investigation->objectives),
            $this->narrative('methodology', $investigation->methodology),
            $this->parties($investigation),
            $this->chronology($investigation),
            $this->findingsOfFact($investigation),
            $this->financialImplication($investigation),
            $this->rootCause($investigation),
            $this->consequenceManagement($investigation),
            $this->recommendations($investigation),
            $this->narrative('conclusion', $investigation->conclusion),
            $this->evidenceRegister($investigation),
        ];
    }

    // ── Sections ─────────────────────────────────────────────────────────

    private function cover(Investigation $investigation): array
    {
        $fields = [
            ['label' => 'Reference', 'value' => $investigation->reference],
            ['label' => 'Category', 'value' => $this->humanise($investigation->category)],
            ['label' => 'Source', 'value' => $this->humanise($investigation->source)],
            ['label' => 'Classification', 'value' => $investigation->is_confidential ? 'Confidential' : 'Internal'],
            ['label' => 'Risk rating', 'value' => $investigation->risk_rating ?? 'Not rated'],
            ['label' => 'Lead investigator', 'value' => $investigation->leadInvestigator?->name ?? '—'],
            ['label' => 'Control entity', 'value' => $investigation->controlEntity?->name ?? '—'],
            ['label' => 'Reported', 'value' => $investigation->reported_date?->format('d M Y') ?? '—'],
            ['label' => 'Completed', 'value' => $investigation->completed_date?->format('d M Y') ?? '—'],
        ];

        // §D.4-3. A conflict the organisation decided to live with is
        // printed on the cover, not buried in a tab.
        if ($investigation->has_sod_conflict) {
            $fields[] = [
                'label' => 'Segregation of duties',
                'value' => $investigation->sod_conflict_note ?? 'A segregation-of-duties conflict was recorded on this investigation.',
            ];
        }

        return [
            'key' => 'cover',
            'type' => 'cover',
            'title' => 'Investigation Report',
            'subtitle' => $investigation->title,
            'fields' => $fields,
        ];
    }

    private function backgroundBody(Investigation $investigation): string
    {
        $preamble = sprintf(
            'This investigation (%s) was opened on %s following a %s. It was categorised as %s.',
            $investigation->reference,
            $investigation->reported_date?->format('d F Y') ?? 'an unrecorded date',
            $this->humanise($investigation->source),
            $this->humanise($investigation->category),
        );

        // The origin is named by TYPE, never by reporter: §D.3-3 forbids
        // any reporter identity crossing from a Speak Up report into this
        // document, and "a Speak Up report" is all a reader needs.
        if ($investigation->origin_type) {
            $preamble .= ' It was raised from '.$this->originLabel($investigation).'.';
        }

        return trim($preamble."\n\n".(string) $investigation->background);
    }

    /**
     * Spec §5.3-2 / §7.2 — the investigating team, then the people the
     * investigation named.
     *
     * The team was missing from this section entirely: a report that names
     * subjects and outcomes without saying who reached them is not a
     * defensible document. It is keyed by user id on the way out because
     * the lead reaches this method twice — once as a team member with
     * `role = 'lead'`, once through the denormalised
     * `lead_investigator_id` — and the reference implementation prints
     * that person on two consecutive lines.
     *
     * @return array<int, array<int, string>>
     */
    private function teamRows(Investigation $investigation): array
    {
        $members = $investigation->teamMembers()->with('user:id,name')->get()
            ->filter(fn (InvestigationTeamMember $member) => $member->user !== null)
            ->keyBy('user_id');

        // The convenience column, only if it names somebody the team table
        // does not already carry.
        if ($investigation->lead_investigator_id && ! $members->has($investigation->lead_investigator_id)) {
            if ($lead = $investigation->leadInvestigator) {
                $synthetic = new InvestigationTeamMember(['user_id' => $lead->id, 'role' => 'lead']);
                $synthetic->setRelation('user', $lead);
                $members->put($lead->id, $synthetic);
            }
        }

        return $members
            ->sortBy(fn (InvestigationTeamMember $member) => $member->role === 'lead' ? 0 : 1)
            ->map(fn (InvestigationTeamMember $member) => [
                $member->user->name,
                'Investigation team',
                $this->humanise($member->role),
                '—',
                '—',
                '—',
            ])
            ->values()
            ->all();
    }

    private function parties(Investigation $investigation): array
    {
        $interviews = $investigation->activities()
            ->where('activity_type', 'interview_conducted')
            ->get()
            ->groupBy(fn (InvestigationActivity $activity) => strtolower($activity->title));

        $rows = $investigation->subjects->map(function ($subject) use ($interviews) {
            $interviewed = $interviews->keys()->contains(fn ($title) => str_contains($title, strtolower($subject->name)));

            return [
                $subject->name,
                $this->humanise($subject->subject_type),
                $this->humanise($subject->role_in_case),
                $subject->department ?? $subject->organisationUnit?->name ?? '—',
                $this->humanise($subject->outcome),
                $interviewed ? 'Yes' : 'No',
            ];
        })->all();

        return $this->table('parties', [
            'Name', 'Type', 'Role in case', 'Department', 'Outcome', 'Interviewed',
        ], [...$this->teamRows($investigation), ...$rows],
            'The investigating team, then every person, account or process this investigation named.');
    }

    /**
     * Spec §5.3-4 / §7.4 — two different timelines, both of which belong
     * in the report, separately labelled.
     *
     * The narrative is the investigator's account of when the INCIDENT
     * happened. The table below it is the case-handling diary: when the
     * INVESTIGATION did things. Rendering only the diary — which is what
     * this method used to do, and what the reference implementation does —
     * silently drops a narrative the create and edit forms both collect
     * and the database has been storing all along.
     */
    private function chronology(Investigation $investigation): array
    {
        $rows = $investigation->activities()
            ->with('performer:id,name')
            ->reorder('activity_date')
            ->get()
            ->map(fn (InvestigationActivity $activity) => [
                $activity->activity_date?->format('d M Y H:i') ?? '—',
                $this->humanise($activity->activity_type),
                $activity->title,
                $activity->performer?->name ?? 'System',
            ])
            ->all();

        $narrative = trim((string) $investigation->chronology);

        $caption = $narrative !== ''
            ? $narrative."\n\nCase handling timeline — what the investigation did, and when."
            : 'Case handling timeline — what the investigation did, and when.';

        return $this->table('chronology', ['When', 'Type', 'What happened', 'By'], $rows, $caption);
    }

    private function findingsOfFact(Investigation $investigation): array
    {
        $rows = $investigation->findings->map(fn ($finding) => [
            $finding->reference,
            $finding->title,
            $finding->severity,
            $finding->control?->control_ref ?? '—',
            $finding->financial_impact !== null ? $this->money($investigation, $finding->financial_impact) : '—',
        ])->all();

        $body = $investigation->findings
            ->map(fn ($finding) => "{$finding->reference} — {$finding->title} ({$finding->severity})\n".trim((string) $finding->description))
            ->implode("\n\n");

        return $this->table('findings_of_fact', ['Ref', 'Finding', 'Severity', 'Control', 'Impact'], $rows, $body ?: null);
    }

    private function financialImplication(Investigation $investigation): array
    {
        $byCategory = $investigation->findings
            ->whereNotNull('financial_impact')
            ->groupBy(fn ($finding) => $finding->control?->control_ref ?? 'Unattributed')
            ->map(fn ($group) => $group->sum('financial_impact'));

        return [
            'key' => 'financial_implication',
            'type' => 'kpi_row',
            'title' => $this->title('financial_implication'),
            // Every writer reads `caption` unconditionally, so a kpi item
            // without one renders as a failed report rather than a tidier
            // tile. Shape first, content second.
            'items' => [
                $this->kpi('Estimated impact', $this->money($investigation, $investigation->estimated_financial_impact)),
                $this->kpi('Confirmed loss', $this->money($investigation, $investigation->confirmed_financial_loss)),
                $this->kpi('Recovered', $this->money($investigation, $investigation->amount_recovered), 'Sum of implemented consequences'),
                $this->kpi('Net loss', $this->money($investigation, $investigation->netLoss())),
            ],
            'table' => [
                'columns' => ['Attributed to', 'Impact'],
                'rows' => $byCategory->map(fn ($total, $key) => [$key, $this->money($investigation, $total)])->values()->all(),
            ],
        ];
    }

    private function rootCause(Investigation $investigation): array
    {
        $grouped = $investigation->findings->groupBy(fn ($finding) => $finding->control?->control_ref ?? 'No control attributed');

        $body = $grouped->map(function ($findings, $control) {
            $lines = $findings->map(fn ($finding) => trim(sprintf(
                "%s — root cause: %s\nControl failure: %s",
                $finding->reference,
                trim((string) $finding->root_cause) ?: 'not recorded',
                trim((string) $finding->control_failure) ?: 'not recorded',
            )))->implode("\n\n");

            return "{$control}\n\n{$lines}";
        })->implode("\n\n");

        return $this->narrative('root_cause', $body);
    }

    private function consequenceManagement(Investigation $investigation): array
    {
        $rows = $investigation->consequenceActions->map(fn ($action) => [
            $action->reference,
            $this->humanise($action->action_type),
            $action->subject?->name ?? '—',
            $this->humanise($action->status),
            $action->approver?->name ?? '—',
            $action->amount_recovered !== null ? $this->money($investigation, $action->amount_recovered) : '—',
        ])->all();

        return $this->table(
            'consequence_management',
            ['Ref', 'Action', 'Subject', 'Status', 'Approved by', 'Recovered'],
            $rows,
        );
    }

    private function recommendations(Investigation $investigation): array
    {
        $rows = $investigation->findings->map(fn ($finding) => [
            $finding->reference,
            trim((string) $finding->recommendation) ?: '—',
            $finding->improvementAction?->reference ?? 'Not yet raised',
            $finding->improvementAction?->status ?? '—',
            $finding->improvementAction?->due_at?->format('d M Y') ?? '—',
        ])->all();

        return $this->table(
            'recommendations',
            ['Finding', 'Recommendation', 'Improvement action', 'Status', 'Due'],
            $rows,
            'Each recommendation is tracked as an improvement action with an owner, a due date and independent verification.',
        );
    }

    private function evidenceRegister(Investigation $investigation): array
    {
        $rows = $investigation->evidence->map(fn ($evidence) => [
            $evidence->id,
            $evidence->file_name,
            substr((string) $evidence->checksum, 0, 16).'…',
            $evidence->collector?->name ?? $evidence->uploader?->name ?? '—',
            $evidence->collected_on?->format('d M Y') ?? $evidence->uploaded_at?->format('d M Y') ?? '—',
            $evidence->collection_source ?? '—',
        ])->all();

        return $this->table(
            'evidence_register',
            ['#', 'Exhibit', 'SHA-256 (truncated)', 'Collected by', 'Collected', 'Source'],
            $rows,
            'Exhibits are held in the shared evidence repository under its retention policy and legal hold; every view and download is logged.',
        );
    }

    private function signatureBlock(Investigation $investigation): array
    {
        return [
            'key' => 'signature_block',
            'type' => 'signature_block',
            'title' => 'Sign-off',
            'signatories' => [
                ['role' => 'Lead investigator', 'name' => $investigation->leadInvestigator?->name],
                ['role' => 'Reviewer', 'name' => null],
                ['role' => 'Control Function Head', 'name' => null],
            ],
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function kpi(string $label, string $value, ?string $caption = null): array
    {
        return ['label' => $label, 'value' => $value, 'caption' => $caption, 'tone' => null];
    }

    private function narrative(string $key, ?string $body): array
    {
        return [
            'key' => $key,
            'type' => $key === 'evidence_register' ? 'appendix' : 'narrative',
            'title' => $this->title($key),
            'body' => trim((string) $body) ?: 'Not recorded.',
        ];
    }

    private function table(string $key, array $columns, array $rows, ?string $caption = null): array
    {
        return [
            'key' => $key,
            'type' => $key === 'evidence_register' ? 'appendix' : 'table',
            'title' => $this->title($key),
            'chart_type' => 'bar',
            'source' => null,
            'data' => ['kind' => 'rows', 'caption' => $caption],
            'body' => $caption,
            'table' => ['columns' => $columns, 'rows' => $rows],
        ];
    }

    private function title(string $key): string
    {
        return [
            'background' => 'Background',
            'scope' => 'Scope',
            'objectives' => 'Objectives',
            'methodology' => 'Methodology',
            'parties' => 'Parties involved',
            'chronology' => 'Chronology',
            'findings_of_fact' => 'Findings of fact',
            'financial_implication' => 'Financial implication',
            'root_cause' => 'Root cause & control failure',
            'consequence_management' => 'Consequence management',
            'recommendations' => 'Recommendations',
            'conclusion' => 'Conclusion',
            'evidence_register' => 'Evidence register',
        ][$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * The origin by TYPE only. §D.3-3: no reporter identity — no name, no
     * user id, no token hash, no Tier 2 metadata — may cross from a Speak
     * Up report into an investigation or any of its report sections. An
     * anonymous case's investigation must be runnable end to end without
     * ever resolving a person.
     */
    private function originLabel(Investigation $investigation): string
    {
        return match ($investigation->origin_type) {
            SpeakUpCase::class => 'a Speak Up report',
            ControlException::class => 'a control exception',
            Incident::class => 'an operational incident',
            Complaint::class => 'a customer complaint',
            TestInstance::class => 'a failed control test',
            default => 'another record on the platform',
        };
    }

    private function money(Investigation $investigation, float|string|null $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        return Money::fromMajor(number_format((float) $amount, 2, '.', ''), $investigation->currency ?? 'NGN')->format();
    }

    private function humanise(?string $value): string
    {
        return $value === null ? '—' : ucfirst(str_replace('_', ' ', $value));
    }
}
