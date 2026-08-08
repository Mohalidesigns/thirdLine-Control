<?php

namespace App\Services;

use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\SubmissionPack;
use App\Models\User;
use App\Notifications\SubmissionActionNotification;
use App\Submissions\GeneratedPack;
use App\Submissions\SubmissionPackRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Generation and maker-checker filing of regulatory submission packs (13.4).
 *
 * The rules that cannot be routed around, all enforced here rather than in a
 * controller:
 *
 *   · the reviewer is never the generator, and the approver is never either
 *     of them — with no administrator bypass (R2);
 *   · a pack below the completeness threshold, or carrying a blocking
 *     validation error, cannot be submitted;
 *   · content sourced from unverified regulatory text is excluded from the
 *     document and listed instead (R10);
 *   · an approved pack is immutable — a change means a new version that
 *     supersedes it, so the document on the regulator's desk always has an
 *     unchanged counterpart here.
 */
class SubmissionPackService
{
    public function __construct(
        private SubmissionPackRegistry $registry,
        private ReportDesignerService $reports,
        private NotificationDispatcher $dispatcher,
    ) {}

    public function threshold(): int
    {
        return (int) config('dashboards.submission_completeness_threshold');
    }

    public function generate(string $packType, User $user, array $options = []): SubmissionPack
    {
        $generator = $this->registry->get($packType);
        $period = $options['period_label'] ?? $generator->defaultPeriodLabel();

        $result = $generator->generate($user, $options + ['period_label' => $period]);

        $pack = DB::transaction(fn () => SubmissionPack::create([
            'pack_ref' => SubmissionPack::nextReference('SUB', true, 'pack_ref'),
            'pack_type' => $packType,
            'period_label' => $period,
            'obligation_instance_id' => $options['obligation_instance_id'] ?? null,
            'status' => 'Draft',
            'generated_at' => now(),
            'generated_by' => $user->id,
        ] + $this->resultAttributes($result)));

        $pack->auditAction('submission.generated', null, [
            'pack_type' => $packType,
            'period' => $period,
            'completeness' => $pack->completeness_score,
        ]);

        return $pack;
    }

    /**
     * Rebuild against current data, preserving everything a human typed. Only
     * a pack that is still being worked on can be regenerated — once it is
     * approved, the document is evidence.
     */
    public function regenerate(SubmissionPack $pack, User $user): SubmissionPack
    {
        $this->assertMutable($pack);

        $generator = $this->registry->get($pack->pack_type);

        $result = $generator->generate($user, [
            'period_label' => $pack->period_label,
            'existing' => $pack->content ?? [],
            'evidence_ids' => $pack->evidence_ids ?? [],
        ]);

        $pack->update($this->resultAttributes($result));
        $pack->auditAction('submission.regenerated', null, ['completeness' => $pack->completeness_score]);

        return $pack;
    }

    /**
     * Record a human's answers. Field values are merged by key, so a partial
     * save never blanks what is not on screen, and the completeness score and
     * blocking errors are recomputed from the merged result.
     */
    public function update(SubmissionPack $pack, User $user, array $answers): SubmissionPack
    {
        $this->assertMutable($pack);

        $content = $pack->content ?? [];

        $content['sections'] = array_map(function (array $section) use ($answers) {
            $section['fields'] = array_map(function (array $field) use ($answers) {
                if (($field['source'] ?? 'input') !== 'system' && array_key_exists($field['key'], $answers)) {
                    $field['value'] = $answers[$field['key']];
                }

                return $field;
            }, $section['fields'] ?? []);

            return $section;
        }, $content['sections'] ?? []);

        // Re-running the generator over the merged content is what keeps the
        // validation honest: the answers are re-checked by the same rules
        // that would have caught them at generation.
        $result = $this->registry->get($pack->pack_type)->generate($user, [
            'period_label' => $pack->period_label,
            'existing' => $content,
            'evidence_ids' => $pack->evidence_ids ?? [],
        ]);

        $pack->update($this->resultAttributes($result));
        $pack->auditAction('submission.updated', null, ['completeness' => $pack->completeness_score]);

        return $pack;
    }

    public function sendForReview(SubmissionPack $pack, User $user): SubmissionPack
    {
        $this->assertTransition($pack, 'Under Review');

        $pack->update([
            'status' => 'Under Review',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
        ]);

        $pack->auditAction('submission.sent_for_review');
        $this->notifyOwners($pack, 'is ready for review');

        return $pack;
    }

    /**
     * THE first gate. A pack is reviewed by someone other than the person who
     * generated it — checked here as well as in the policy, because the
     * policy answers the UI and this answers everything else.
     */
    public function review(SubmissionPack $pack, User $reviewer, string $decision, ?string $notes = null): SubmissionPack
    {
        if ($pack->status !== 'Under Review') {
            throw ValidationException::withMessages([
                'status' => 'Only a pack under review can be reviewed.',
            ]);
        }

        if ($pack->generated_by === $reviewer->id) {
            throw ValidationException::withMessages([
                'reviewer' => 'A submission pack must be reviewed by someone other than the person who generated it.',
            ]);
        }

        if ($decision === 'return') {
            $pack->update([
                'status' => 'Draft',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $notes,
            ]);

            $pack->auditAction('submission.returned', null, ['reviewer' => $reviewer->id, 'notes' => $notes]);

            return $pack;
        }

        $pack->update([
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        $pack->auditAction('submission.reviewed', null, ['reviewer' => $reviewer->id]);
        $this->notifyOwners($pack, 'has been reviewed and is waiting for approval');

        return $pack;
    }

    /**
     * THE second gate, and the one that locks the document. Three distinct
     * people by the time this returns: generator, reviewer, approver.
     */
    public function approve(SubmissionPack $pack, User $approver): SubmissionPack
    {
        $this->assertTransition($pack, 'Approved');

        if ($pack->reviewed_at === null) {
            throw ValidationException::withMessages([
                'review' => 'The pack has not been reviewed yet. Review precedes approval.',
            ]);
        }

        if ($pack->generated_by === $approver->id) {
            throw ValidationException::withMessages([
                'approver' => 'A submission pack cannot be approved by the person who generated it.',
            ]);
        }

        if ($pack->reviewed_by === $approver->id) {
            throw ValidationException::withMessages([
                'approver' => 'A submission pack cannot be approved by the person who reviewed it.',
            ]);
        }

        $pack->update([
            'status' => 'Approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'locked_at' => now(),
            'checksum' => $this->checksum($pack),
        ]);

        $pack->auditAction('submission.approved', null, [
            'approver' => $approver->id,
            'checksum' => $pack->checksum,
        ]);

        return $pack;
    }

    /**
     * File it. Everything the platform can check is checked one last time
     * here — completeness against the configured floor, and every blocking
     * validation error the generator raised.
     */
    public function file(SubmissionPack $pack, User $user, array $data): SubmissionPack
    {
        $this->assertTransition($pack, 'Submitted');

        if ($pack->approved_at === null) {
            throw ValidationException::withMessages([
                'approval' => 'The pack has not been approved. Approval precedes filing.',
            ]);
        }

        $this->assertFilable($pack);

        $pack->update([
            'status' => 'Submitted',
            'submitted_at' => $data['submitted_at'] ?? now(),
            'submitted_by' => $user->id,
            'submission_reference' => $data['submission_reference'] ?? null,
        ]);

        $pack->auditAction('submission.filed', null, [
            'reference' => $pack->submission_reference,
            'by' => $user->id,
        ]);

        $this->reflectOnObligation($pack, $user);

        return $pack;
    }

    public function acknowledge(SubmissionPack $pack, User $user, array $data): SubmissionPack
    {
        $this->assertTransition($pack, 'Acknowledged');

        $pack->update([
            'status' => 'Acknowledged',
            'acknowledged_at' => now(),
            'acknowledgement_ref' => $data['acknowledgement_ref'] ?? null,
            'acknowledgement_path' => $data['acknowledgement_path'] ?? $pack->acknowledgement_path,
        ]);

        $pack->auditAction('submission.acknowledged', null, ['reference' => $pack->acknowledgement_ref]);

        return $pack;
    }

    public function reject(SubmissionPack $pack, User $user, string $reason): SubmissionPack
    {
        $this->assertTransition($pack, 'Rejected');

        $pack->update(['status' => 'Rejected', 'rejection_reason' => $reason]);
        $pack->auditAction('submission.rejected_by_regulator', null, ['reason' => $reason]);
        $this->notifyOwners($pack, 'was rejected by the regulator and needs re-work');

        return $pack;
    }

    /**
     * A rejected pack is never edited back into shape — the filed version
     * stays exactly as filed, and the replacement is a new version that
     * records what it supersedes.
     */
    public function resubmit(SubmissionPack $pack, User $user): SubmissionPack
    {
        if ($pack->status !== 'Rejected') {
            throw ValidationException::withMessages([
                'status' => 'Only a rejected pack is re-submitted.',
            ]);
        }

        $generator = $this->registry->get($pack->pack_type);

        $result = $generator->generate($user, [
            'period_label' => $pack->period_label,
            'existing' => $pack->content ?? [],
            'evidence_ids' => $pack->evidence_ids ?? [],
        ]);

        $replacement = DB::transaction(function () use ($pack, $user, $result) {
            $replacement = SubmissionPack::create([
                'pack_ref' => SubmissionPack::nextReference('SUB', true, 'pack_ref'),
                'pack_type' => $pack->pack_type,
                'period_label' => $pack->period_label,
                'obligation_instance_id' => $pack->obligation_instance_id,
                'status' => 'Draft',
                'generated_at' => now(),
                'generated_by' => $user->id,
                'supersedes_id' => $pack->id,
                'version_no' => $pack->version_no + 1,
            ] + $this->resultAttributes($result));

            $pack->update(['status' => 'Resubmitted']);

            return $replacement;
        });

        $replacement->auditAction('submission.resubmitted', ['supersedes' => $pack->pack_ref], [
            'version' => $replacement->version_no,
        ]);

        return $replacement;
    }

    /**
     * Render the pack as a document. Available from review onwards; the file
     * produced after approval is the immutable one, and its checksum is on
     * the pack.
     */
    public function render(SubmissionPack $pack, User $user, string $format = 'pdf'): ReportRun
    {
        $definition = $this->definitionFor($pack);

        $run = $this->reports->runWithDocument(
            $definition,
            $user,
            $format,
            $this->document($pack, $user),
            ['period' => $pack->period_label, 'pack_ref' => $pack->pack_ref],
        );

        if ($run->status === 'Completed') {
            $pack->update(['report_run_id' => $run->id]);
        }

        return $run;
    }

    /**
     * The pack as an engine-ready document. Unverified items are already
     * absent from the sections; they are restated in an appendix so a reader
     * can see what was left out and why (R10).
     *
     * @return array<string, mixed>
     */
    public function document(SubmissionPack $pack, User $user): array
    {
        $generator = $this->registry->get($pack->pack_type);
        $content = $pack->content ?? [];

        $sections = [[
            'type' => 'cover',
            'title' => $generator->label(),
            'subtitle' => $pack->period_label,
            'fields' => [
                ['label' => 'Pack reference', 'value' => $pack->pack_ref],
                ['label' => 'Regulator', 'value' => $generator->regulatorCode()],
                ['label' => 'Period', 'value' => $pack->period_label],
                ['label' => 'Completeness', 'value' => $pack->completeness_score.'%'],
                ['label' => 'Status', 'value' => $pack->status],
            ],
        ]];

        foreach ($content['sections'] ?? [] as $section) {
            $sections[] = [
                'type' => 'table',
                'title' => $section['title'],
                'chart_type' => 'bar',
                'source' => null,
                'data' => ['kind' => 'rows', 'caption' => null],
                'table' => $this->sectionTable($section),
            ];
        }

        if (($pack->unverified_items ?? []) !== []) {
            $sections[] = [
                'type' => 'table',
                'title' => 'Appendix — content excluded as unverified',
                'chart_type' => 'bar',
                'source' => null,
                'data' => ['kind' => 'rows', 'caption' => 'Excluded from this submission pending verification against the regulator\'s own document.'],
                'table' => [
                    'columns' => ['Reference', 'Item', 'Status', 'Why it was excluded'],
                    'rows' => array_map(fn (array $item) => [
                        $item['ref'] ?? '—', $item['title'] ?? '—', $item['status'] ?? '—', $item['reason'] ?? '—',
                    ], $pack->unverified_items),
                ],
            ];
        }

        $sections[] = [
            'type' => 'signature_block',
            'title' => 'Certification',
            // Normalised to the shape every writer expects: a signature block
            // with a role and a blank name line is the point of it.
            'signatories' => array_map(fn (array $signatory) => [
                'role' => $signatory['role'] ?? '',
                'name' => $signatory['name'] ?? null,
            ], $content['signatories'] ?? $generator->signatories()),
        ];

        return [
            'code' => $pack->pack_type,
            'title' => $generator->label(),
            'subtitle' => $pack->period_label,
            'report_type' => 'regulatory',
            'confidentiality' => 'Confidential',
            'generated_at' => ($pack->generated_at ?? now())->format('d M Y H:i'),
            'generated_by' => $pack->generator?->name ?? $user->name,
            'context' => [
                'tenant' => $user->tenant?->name ?? config('app.name'),
                'period' => $pack->period_label,
                'entity' => $content['meta']['entity'] ?? 'Group-wide',
            ],
            'styling' => [],
            'header' => ['title' => $generator->label(), 'subtitle' => $generator->regulatorCode()],
            'footer' => ['text' => 'Confidential — regulatory submission '.$pack->pack_ref],
            'cover' => [],
            'sections' => $sections,
        ];
    }

    /** @return array{columns: array<int, string>, rows: array<int, array<int, mixed>>} */
    private function sectionTable(array $section): array
    {
        $rows = [];

        foreach ($section['fields'] ?? [] as $field) {
            $value = $field['value'] ?? null;

            if (is_array($value)) {
                $headings = $section['row_templates'][$field['key']] ?? [];
                $rows[] = [$field['label'], $headings !== [] ? implode(' · ', $headings) : count($value).' row(s)'];

                foreach ($value as $row) {
                    $rows[] = ['', implode(' · ', array_map(fn ($cell) => (string) $cell, (array) $row))];
                }

                continue;
            }

            $rows[] = [$field['label'], $value === null || $value === '' ? '—' : (string) $value];
        }

        return ['columns' => ['Item', 'Response'], 'rows' => $rows];
    }

    // ── Guards ───────────────────────────────────────────────────────────

    private function assertMutable(SubmissionPack $pack): void
    {
        if ($pack->isLocked()) {
            throw ValidationException::withMessages([
                'pack' => 'This pack was approved on '.$pack->approved_at->format('d M Y')
                    .' and is now immutable. Re-open it as a new version instead.',
            ]);
        }

        if (! in_array($pack->status, ['Draft', 'Rejected'], true)) {
            throw ValidationException::withMessages([
                'pack' => 'Only a pack in Draft can be edited. Return it from review first.',
            ]);
        }
    }

    private function assertTransition(SubmissionPack $pack, string $to): void
    {
        if (! $pack->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "A pack in '{$pack->status}' cannot move to '{$to}'.",
            ]);
        }
    }

    private function assertFilable(SubmissionPack $pack): void
    {
        $blocking = array_values(array_filter(
            $pack->validation_errors ?? [],
            fn (array $error) => (bool) ($error['blocking'] ?? true),
        ));

        if ($blocking !== []) {
            throw ValidationException::withMessages([
                'validation' => 'This pack still has '.count($blocking).' blocking issue(s): '
                    .implode(' ', array_column($blocking, 'message')),
            ]);
        }

        if ($pack->completeness_score < $this->threshold()) {
            throw ValidationException::withMessages([
                'completeness' => sprintf(
                    'The pack is %d%% complete and the filing floor is %d%%. Complete the outstanding answers first.',
                    $pack->completeness_score,
                    $this->threshold(),
                ),
            ]);
        }
    }

    // ── Plumbing ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function resultAttributes(GeneratedPack $result): array
    {
        return [
            'content' => $result->toArray(),
            'evidence_ids' => $result->evidenceIds,
            'unverified_items' => $result->unverifiedItems,
            'validation_errors' => $result->validationErrors,
            'completeness_score' => $result->completeness(),
        ];
    }

    /**
     * The checksum is over the answered content, not the rendered file: it is
     * what proves the numbers approved are the numbers filed, whichever
     * format the regulator asked for.
     */
    private function checksum(SubmissionPack $pack): string
    {
        return hash('sha256', json_encode([
            'pack_ref' => $pack->pack_ref,
            'pack_type' => $pack->pack_type,
            'period' => $pack->period_label,
            'content' => $pack->content,
        ], JSON_THROW_ON_ERROR));
    }

    /** The system report definition every pack of this type renders through. */
    private function definitionFor(SubmissionPack $pack): ReportDefinition
    {
        $generator = $this->registry->get($pack->pack_type);

        return ReportDefinition::firstOrCreate(
            ['code' => 'SUB-'.str_replace('/', '-', $pack->pack_type)],
            [
                'name' => $generator->label(),
                'description' => $generator->description(),
                'report_type' => 'regulatory',
                'output_formats' => ['pdf', 'docx', 'xlsx'],
                'sections' => [],
                'confidentiality' => 'Confidential',
                'is_system' => true,
                'is_active' => true,
            ],
        );
    }

    /**
     * Filing a pack discharges the obligation instance behind it — otherwise
     * the calendar keeps chasing a return that has already gone in.
     */
    private function reflectOnObligation(SubmissionPack $pack, User $user): void
    {
        $instance = $pack->obligationInstance;

        if (! $instance || ! in_array($instance->status, ['Not Started', 'In Progress', 'Overdue', 'Rejected'], true)) {
            return;
        }

        $instance->update([
            'status' => 'Submitted',
            'submitted_at' => $pack->submitted_at,
            'submitted_by' => $user->id,
            'submission_reference' => $pack->submission_reference,
        ]);

        $instance->auditAction('obligation.submitted_via_pack', null, ['pack_ref' => $pack->pack_ref]);
    }

    /**
     * Everyone who could take the next step is told. The people who could
     * not — the generator on a review request, for instance — are not
     * excluded here: the gates refuse them, and telling them the pack moved
     * is legitimate.
     */
    private function notifyOwners(SubmissionPack $pack, string $what): void
    {
        $recipients = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Control Function Head', 'Control Officer']))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $this->dispatcher->send(
            $recipients,
            'submission.awaiting-action',
            new SubmissionActionNotification($pack, $what),
        );
    }

    /** Where an acknowledgement letter is stored, under the report disk. */
    public function storeAcknowledgement(SubmissionPack $pack, string $contents, string $extension): string
    {
        $path = sprintf('submissions/%d/%s-acknowledgement.%s', $pack->tenant_id, $pack->pack_ref, $extension);

        Storage::disk(config('dashboards.report_disk'))->put($path, $contents);

        return $path;
    }
}
