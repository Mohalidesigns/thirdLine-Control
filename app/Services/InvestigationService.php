<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\EntityLink;
use App\Models\Evidence;
use App\Models\Incident;
use App\Models\Investigation;
use App\Models\InvestigationActivity;
use App\Models\InvestigationFinding;
use App\Models\InvestigationSubject;
use App\Models\InvestigationTeamMember;
use App\Models\SpeakUpCase;
use App\Models\TestInstance;
use App\Models\User;
use App\Notifications\InvestigationAssignedNotification;
use App\Notifications\InvestigationCompletedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The investigation lifecycle (CR-04 §E.1).
 *
 * Four rules live here rather than in a controller, because a rule in a
 * controller is a rule the next entry point forgets:
 *
 *   1. transition() refuses 'completed' outright. Completion goes through
 *      complete(), which requires a risk rating, a completion date AND a
 *      resolved outcome for every named subject. You cannot close an
 *      investigation without rating it, and you cannot close one while a
 *      named person's position is unresolved.
 *   2. Archiving requires completed-or-closed status and a reason.
 *   3. Every transition writes a diary row. The chronology is a by-product
 *      of the workflow, not a step someone remembers to do.
 *   4. Segregation of duties (§D.4): a subject may never be on the team,
 *      in either direction of assignment; and the officer who owns the
 *      failed control is flagged, not blocked, as lead.
 */
class InvestigationService
{
    /**
     * The workflow map. 'completed' is deliberately absent as a
     * destination — see complete().
     */
    public const TRANSITIONS = Investigation::TRANSITIONS;

    /** Origin records an investigation may be raised from (§D.2). */
    public const ORIGIN_ALIASES = [
        'case' => SpeakUpCase::class,
        'exception' => ControlException::class,
        'incident' => Incident::class,
        'complaint' => Complaint::class,
        'test_instance' => TestInstance::class,
    ];

    public function __construct(
        private LinkageService $linkage,
        private InvestigationReportBuilder $reports,
        private NotificationDispatcher $dispatcher,
    ) {}

    // ── Opening ──────────────────────────────────────────────────────────

    /**
     * Open an investigation. The origin morph and the graph edge are
     * written in the same transaction as the record itself: provenance is
     * both a queryable column and a visible edge, and neither may exist
     * without the other (§D.2).
     */
    public function open(array $data, User $actor, ?Model $origin = null): Investigation
    {
        return DB::transaction(function () use ($data, $actor, $origin) {
            $attributes = collect($data)
                ->except(['team_member_ids', 'origin_type', 'origin_id', 'is_confidential', 'confidentiality_locked'])
                ->all();

            $confidentiality = $this->resolveConfidentiality($data, $origin);

            $investigation = Investigation::create([
                ...$attributes,
                'reference' => Investigation::nextReference('INV'),
                'status' => 'draft',
                'reported_date' => $data['reported_date'] ?? now()->toDateString(),
                'lead_investigator_id' => $data['lead_investigator_id'] ?? $actor->id,
                'origin_type' => $origin ? $origin::class : null,
                'origin_id' => $origin?->getKey(),
                'is_confidential' => $confidentiality['is_confidential'],
                'confidentiality_locked' => $confidentiality['confidentiality_locked'],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            // The lead is on the team by definition — otherwise the person
            // running the case would depend on someone remembering to add
            // them for their own visibility.
            $lead = User::find($investigation->lead_investigator_id);

            if ($lead) {
                $this->seedTeamMember($investigation, $lead, 'lead', $actor);
            }

            foreach ($this->initialTeam($investigation, $data, $origin) as $userId) {
                if ($userId === $investigation->lead_investigator_id) {
                    continue;
                }

                if ($member = User::find($userId)) {
                    $this->seedTeamMember($investigation, $member, 'investigator', $actor);
                }
            }

            // The person who opened the case is on it. Without this, an
            // officer who opens a CONFIDENTIAL investigation and names
            // somebody else as lead loses sight of it the instant they
            // save — the confidential regime does not recognise 'creator'.
            // The one exception is a Speak-Up-origin case, where the
            // allowlist on `cases` is the only door (§D.3-2): an actor who
            // is not on it does not get onto this team either.
            if ($lead && $lead->id !== $actor->id && $this->mayJoin($investigation, $actor, $origin)) {
                $this->seedTeamMember($investigation, $actor, 'investigator', $actor);
            }

            if ($origin) {
                $this->linkOrigin($investigation, $origin);
            }

            $this->recordSystemActivity($investigation, 'case_created', "Investigation {$investigation->reference} opened.", $actor, [
                'description' => $origin
                    ? 'Raised from '.class_basename($origin).' #'.$origin->getKey().'.'
                    : 'Opened directly.',
            ]);

            $investigation->auditAction('investigation.opened', null, [
                'reference' => $investigation->reference,
                'category' => $investigation->category,
                'source' => $investigation->source,
                'origin' => $origin ? class_basename($origin).'#'.$origin->getKey() : null,
            ]);

            return $investigation->refresh()->load(['teamMembers.user', 'leadInvestigator']);
        });
    }

    /**
     * §D.3-1. An investigation raised from a Speak Up report inherits its
     * confidentiality and the inheritance LOCKS: no one on the
     * investigating team can turn it off, because the protection belongs
     * to a reporter who is not on the team and cannot argue for it.
     */
    private function resolveConfidentiality(array $data, ?Model $origin): array
    {
        if ($origin instanceof SpeakUpCase) {
            return ['is_confidential' => true, 'confidentiality_locked' => true];
        }

        return [
            'is_confidential' => (bool) ($data['is_confidential'] ?? false),
            'confidentiality_locked' => false,
        ];
    }

    /**
     * §D.3-2. The team of a Speak-Up-origin investigation is seeded from
     * the case's own allowlist, never from the request: nobody gains sight
     * of a whistleblowing matter by being named on an investigation.
     *
     * @return array<int, int>
     */
    private function initialTeam(Investigation $investigation, array $data, ?Model $origin): array
    {
        if ($origin instanceof SpeakUpCase) {
            $allowlist = array_map('intval', $origin->access_user_ids ?? []);

            // The reporter is on the Speak Up allowlist by design — they
            // must be able to follow their own report and read the
            // feedback written back to them. They must NOT thereby become
            // an investigator on the case their report opened: that hands
            // them the subjects, the outcomes and the evidence register,
            // prints their name in the report's parties table, and lets a
            // reader work backwards from the team to the source. The
            // allowlist grants sight of the SUBMISSION, not a seat on the
            // investigation.
            return array_values(array_diff($allowlist, [(int) $origin->reporter_id]));
        }

        return array_map('intval', $data['team_member_ids'] ?? []);
    }

    /** §D.3-2: the Speak Up allowlist is the only door into a whistleblowing matter. */
    private function mayJoin(Investigation $investigation, User $user, ?Model $origin): bool
    {
        if (! $origin instanceof SpeakUpCase) {
            return true;
        }

        return in_array($user->id, array_map('intval', $origin->access_user_ids ?? []), true);
    }

    private function seedTeamMember(Investigation $investigation, User $user, string $role, User $actor): InvestigationTeamMember
    {
        return InvestigationTeamMember::firstOrCreate(
            ['investigation_id' => $investigation->id, 'user_id' => $user->id],
            [
                'tenant_id' => $investigation->tenant_id,
                'role' => $role,
                'assigned_at' => now(),
                'assigned_by' => $actor->id,
            ],
        );
    }

    /**
     * The morph is the source of truth; the edge is the view. Both are
     * written here so the Atlas graph and the "Raised from" banner can
     * never disagree.
     */
    private function linkOrigin(Investigation $investigation, Model $origin): void
    {
        $alias = array_search($origin::class, self::ORIGIN_ALIASES, true);

        // Not every legitimate origin is a node in the graph — a test
        // instance is not. Asked of the registry rather than hardcoded, so
        // the day a test instance becomes linkable this starts drawing it
        // without anyone remembering to come back here.
        if ($alias === false || ! array_key_exists($alias, EntityLink::NODE_TYPES)) {
            return;
        }

        $this->linkage->link('investigation', $investigation->id, $alias, $origin->getKey(), 'relates_to');
    }

    // ── Workflow ─────────────────────────────────────────────────────────

    /**
     * Move an investigation along the workflow. 'completed' is refused
     * here by design — completion carries obligations this method cannot
     * check, so it has its own entry point.
     */
    public function transition(Investigation $investigation, string $to, User $actor, ?string $note = null): Investigation
    {
        if ($to === 'completed') {
            throw ValidationException::withMessages([
                'status' => 'An investigation is completed through the completion form, which requires a risk rating and a resolved outcome for every named subject.',
            ]);
        }

        $this->assertNotArchived($investigation);

        $allowed = self::TRANSITIONS[$investigation->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "An investigation cannot move from '{$investigation->status}' to '{$to}'.",
            ]);
        }

        $from = $investigation->status;
        $changes = ['status' => $to, 'updated_by' => $actor->id];

        // The clock starts when work starts, not when the case was typed in.
        if ($to === 'under_investigation' && $investigation->commenced_date === null) {
            $changes['commenced_date'] = now()->toDateString();
        }

        if ($to === 'closed') {
            $changes['closed_date'] = now()->toDateString();
        }

        $investigation->update($changes);

        $this->recordSystemActivity($investigation, 'status_changed', "Status changed from {$from} to {$to}.", $actor, [
            'description' => $note,
        ]);

        $investigation->auditAction('investigation.status_changed', ['status' => $from], ['status' => $to, 'note' => $note]);

        return $investigation->refresh();
    }

    /**
     * Spec §7.5 / §7.3 — the soft half of the completion gate.
     *
     * These do not block. A case can legitimately conclude with nobody
     * named (a process failure with no culpable person) or with the
     * evidence held outside the platform. What they must not do is pass
     * unremarked, because each is far more often an omission than a
     * finding.
     *
     * @return array<int, string>
     */
    public function completionWarnings(Investigation $investigation): array
    {
        $warnings = [];

        if ($investigation->subjects()->count() === 0) {
            $warnings[] = 'no subject was named';
        }

        if ($investigation->evidence()->count() === 0) {
            $warnings[] = 'no evidence was attached';
        }

        if ($investigation->confirmedLossExceedsEstimate()) {
            $threshold = (int) round(Investigation::IMPACT_VARIANCE_THRESHOLD * 100);
            $warnings[] = "the confirmed loss is more than {$threshold}% above the opening estimate — revisit the priority and risk rating";
        }

        return $warnings;
    }

    /**
     * Completion, with the three obligations that make a completed
     * investigation mean something:
     *
     *   · a risk rating — an unrated investigation cannot be reported on;
     *   · a completion date;
     *   · no subject left on 'pending' — a named person whose position was
     *     never resolved is the single worst way to close a case.
     *
     * The draft report is generated inside a try/catch that reports the
     * failure but does NOT roll the completion back. Report generation
     * must never be able to strand a case in pending_review.
     */
    public function complete(Investigation $investigation, User $actor, array $data): Investigation
    {
        $this->assertNotArchived($investigation);

        if (! in_array($investigation->status, ['pending_review', 'under_investigation'], true)) {
            throw ValidationException::withMessages([
                'status' => "An investigation in '{$investigation->status}' is not ready to be completed.",
            ]);
        }

        if (empty($data['risk_rating'])) {
            throw ValidationException::withMessages([
                'risk_rating' => 'An investigation cannot be completed without a risk rating.',
            ]);
        }

        if (! in_array($data['risk_rating'], Investigation::RISK_RATINGS, true)) {
            throw ValidationException::withMessages([
                'risk_rating' => "'{$data['risk_rating']}' is not a risk rating this platform records.",
            ]);
        }

        $unresolved = $investigation->subjects()->unresolved()->count();

        if ($unresolved > 0) {
            throw ValidationException::withMessages([
                'subjects' => "{$unresolved} named subject(s) still have a pending outcome. Record an outcome for every subject before completing the investigation.",
            ]);
        }

        // Spec §7.5. The reference implementation shows a case marked
        // Completed and rated High with no findings, no subjects, no
        // evidence and no consequences — a status column asserting a
        // conclusion the record cannot support. A completed investigation
        // must at minimum say what it established and what it concluded;
        // those two produce a report with content in it.
        if ($investigation->findings()->count() === 0) {
            throw ValidationException::withMessages([
                'findings' => 'An investigation cannot be completed without at least one finding. If it established nothing, record that as a finding — a completed case with no findings cannot be reported on.',
            ]);
        }

        $conclusion = trim((string) ($data['conclusion'] ?? $investigation->conclusion));

        if ($conclusion === '') {
            throw ValidationException::withMessages([
                'conclusion' => 'An investigation cannot be completed without a conclusion. It is the one section of the report that cannot be generated from the record.',
            ]);
        }

        DB::transaction(function () use ($investigation, $actor, $data) {
            $investigation->update([
                'status' => 'completed',
                'risk_rating' => $data['risk_rating'],
                'completed_date' => $data['completed_date'] ?? now()->toDateString(),
                'conclusion' => $data['conclusion'] ?? $investigation->conclusion,
                'conclusion_rich' => $data['conclusion_rich'] ?? $investigation->conclusion_rich,
                'confirmed_financial_loss' => $data['confirmed_financial_loss'] ?? $investigation->confirmed_financial_loss,
                'updated_by' => $actor->id,
            ]);

            $this->recordSystemActivity(
                $investigation,
                'case_completed',
                "Investigation completed and rated {$investigation->risk_rating}.",
                $actor,
            );

            $investigation->auditAction('investigation.completed', null, [
                'risk_rating' => $investigation->risk_rating,
                'completed_date' => $investigation->completed_date?->toDateString(),
            ]);
        });

        $run = null;

        try {
            $run = $this->reports->generate($investigation->refresh(), $actor);
        } catch (\Throwable $e) {
            // Deliberately swallowed: the investigation IS complete. A
            // failed report is a report to re-run, not a case to strand.
            Log::error('Investigation report generation failed after completion', [
                'investigation' => $investigation->reference,
                'error' => $e->getMessage(),
            ]);
        }

        // Spec §5.3 — completion produces version 1 of the report, in
        // draft, waiting for its first reviewer. Deliberately outside the
        // try above and not conditional on it: the render is the document,
        // this is its position in the review chain, and a renderer that
        // fell over must not also cost the case its review workflow. The
        // run is attached if there is one and back-filled if there is not.
        try {
            app(InvestigationReportWorkflow::class)->openDraft($investigation->refresh(), $actor, $run);
        } catch (\Throwable $e) {
            Log::error('Investigation report draft could not be opened after completion', [
                'investigation' => $investigation->reference,
                'error' => $e->getMessage(),
            ]);
        }

        // After the report attempt, not before it: the notification tells
        // the team a draft is waiting for them, and it should not say so
        // a moment before the renderer decides otherwise.
        $this->notifyTeam($investigation->refresh(), 'investigation.completed', new InvestigationCompletedNotification($investigation));

        return $investigation->refresh();
    }

    /**
     * §F.1. An investigation may not close while a High or Critical
     * finding has nothing tracking its remediation — the same shape of
     * rule CR-01 already applies to exception closure.
     */
    public function close(Investigation $investigation, User $actor, ?string $note = null): Investigation
    {
        $untracked = $investigation->findings()->awaitingRemediation()->count();

        if ($untracked > 0) {
            throw ValidationException::withMessages([
                'findings' => "{$untracked} High or Critical finding(s) have no improvement action. Raise the remediation work before closing the investigation.",
            ]);
        }

        return $this->transition($investigation, 'closed', $actor, $note);
    }

    // ── Archive ──────────────────────────────────────────────────────────

    /**
     * Archived cases drop out of every list, count and KPI, so archiving
     * needs both a finished case and a stated reason. An investigation
     * archived "because it was noisy" is a case someone made disappear.
     */
    public function archive(Investigation $investigation, User $actor, string $reason): Investigation
    {
        if (! in_array($investigation->status, ['completed', 'closed'], true)) {
            throw ValidationException::withMessages([
                'archive' => 'Only a completed or closed investigation can be archived.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'archive_reason' => 'Archiving an investigation requires a reason.',
            ]);
        }

        $investigation->update([
            'is_archived' => true,
            'archived_at' => now(),
            'archived_by' => $actor->id,
            'archive_reason' => $reason,
            'updated_by' => $actor->id,
        ]);

        $this->recordSystemActivity($investigation, 'case_archived', 'Investigation archived.', $actor, [
            'description' => $reason,
        ]);

        $investigation->auditAction('investigation.archived', null, ['reason' => $reason]);

        return $investigation->refresh();
    }

    public function unarchive(Investigation $investigation, User $actor): Investigation
    {
        $investigation->update([
            'is_archived' => false,
            'archived_at' => null,
            'archived_by' => null,
            'archive_reason' => null,
            'updated_by' => $actor->id,
        ]);

        $investigation->auditAction('investigation.unarchived');

        return $investigation->refresh();
    }

    // ── Team ─────────────────────────────────────────────────────────────

    /**
     * Assign someone to the team, subject to the two access rules and one
     * segregation-of-duties rule:
     *
     *   · §D.4-1 — a subject of the investigation may never be on its team.
     *     Hard block, in both directions.
     *   · §D.3-4 — on a Speak-Up-origin investigation, the assignee must
     *     already be on the case's allowlist. One allowlist, enforced in
     *     both directions.
     *   · §D.4-3 — the officer who owns a control under investigation is
     *     FLAGGED as lead, not blocked. In a four-person branch it is
     *     sometimes unavoidable; what is not acceptable is it being
     *     invisible.
     */
    public function assignTeamMember(Investigation $investigation, User $member, string $role, User $actor, ?string $notes = null): InvestigationTeamMember
    {
        $this->assertNotArchived($investigation);

        if (! in_array($role, InvestigationTeamMember::ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => "'{$role}' is not an investigation team role.",
            ]);
        }

        if ($investigation->subjects()->where('user_id', $member->id)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => "{$member->name} is named as a subject of this investigation and cannot be assigned to its team.",
            ]);
        }

        $this->assertSpeakUpAllowlist($investigation, $member);

        $assignment = DB::transaction(function () use ($investigation, $member, $role, $actor, $notes) {
            $assignment = InvestigationTeamMember::updateOrCreate(
                ['investigation_id' => $investigation->id, 'user_id' => $member->id],
                [
                    'tenant_id' => $investigation->tenant_id,
                    'role' => $role,
                    'assigned_at' => now(),
                    'assigned_by' => $actor->id,
                    'notes' => $notes,
                ],
            );

            if ($role === 'lead') {
                $investigation->update(['lead_investigator_id' => $member->id, 'updated_by' => $actor->id]);
            }

            $this->recordSystemActivity(
                $investigation,
                'team_assigned',
                "{$member->name} assigned as {$role}.",
                $actor,
                ['linked' => $assignment],
            );

            return $assignment;
        });

        $this->flagControlOwnerConflict($investigation->refresh(), $actor);

        // Being on the team is what confers sight of the case, so the
        // person needs telling — but never by an email that carries the
        // substance of a confidential matter (see InvestigationNotification).
        if ($member->id !== $actor->id) {
            $this->dispatcher->send($member, 'investigation.assigned', new InvestigationAssignedNotification($investigation, $role));
        }

        $investigation->auditAction('investigation.team_assigned', null, [
            'user_id' => $member->id,
            'role' => $role,
        ]);

        return $assignment;
    }

    public function removeTeamMember(Investigation $investigation, InvestigationTeamMember $member, User $actor): void
    {
        $this->assertNotArchived($investigation);

        if ($member->user_id === $investigation->lead_investigator_id) {
            throw ValidationException::withMessages([
                'team' => 'The lead investigator cannot be removed from the team. Assign a new lead first.',
            ]);
        }

        $name = $member->user?->name ?? "User #{$member->user_id}";
        $member->delete();

        $this->recordSystemActivity($investigation, 'team_assigned', "{$name} removed from the team.", $actor);
        $investigation->auditAction('investigation.team_removed', ['user_id' => $member->user_id], null);
    }

    /**
     * §D.3-4. Adding someone to a Speak-Up-origin investigation also
     * requires them to hold access to the originating case. The allowlist
     * on `cases` remains the single gate; this module does not open a
     * second door into it.
     */
    private function assertSpeakUpAllowlist(Investigation $investigation, User $member): void
    {
        if (! $investigation->raisedFromSpeakUp()) {
            return;
        }

        $allowlist = SpeakUpCase::withoutGlobalScopes()
            ->whereKey($investigation->origin_id)
            ->value('access_user_ids');

        $allowlist = array_map('intval', is_array($allowlist) ? $allowlist : (json_decode((string) $allowlist, true) ?: []));

        if (! in_array($member->id, $allowlist, true)) {
            throw ValidationException::withMessages([
                'user_id' => "{$member->name} is not on the allowlist of the Speak Up report this investigation was raised from. Grant access on the case first — this module does not open a second door into a whistleblowing matter.",
            ]);
        }
    }

    /**
     * §D.4-3. A warning, recorded on the investigation and printed on the
     * report cover — not a block.
     *
     * The existing SodConflictRule / SodViolation machinery cannot carry
     * this and must not be bent to fit: its rules are toxic function pairs
     * from a source system, its subject_identifier is a source-system
     * staff id explicitly documented as not a platform user, and its
     * unique(tenant_id, rule_id, subject_identifier) would prevent
     * flagging the same officer on two investigations.
     */
    private function flagControlOwnerConflict(Investigation $investigation, User $actor): void
    {
        $leadId = $investigation->lead_investigator_id;

        if (! $leadId) {
            return;
        }

        $controlIds = $investigation->findings()->whereNotNull('control_id')->pluck('control_id')->all();

        // Resolved the CR-03 way, not from controls.owner_id: that column
        // is a point-in-time import snapshot and goes stale the moment a
        // desk names a new officer. The entity is the authority.
        $owned = $controlIds === []
            ? collect()
            : Control::withoutGlobalScopes()
                ->with(['homeEntity.defaultOfficer', 'homeEntity.owner', 'owner'])
                ->whereIn('id', $controlIds)
                ->get()
                ->filter(fn (Control $control) => $control->effective_owner?->id === $leadId)
                ->pluck('control_ref', 'id');

        if ($owned->isEmpty()) {
            if ($investigation->has_sod_conflict) {
                $investigation->update(['has_sod_conflict' => false, 'sod_conflict_note' => null]);
            }

            return;
        }

        $lead = $investigation->leadInvestigator?->name ?? "User #{$leadId}";
        $note = sprintf(
            '%s leads this investigation and owns or operates the control(s) under examination: %s. '
            .'Recorded as a segregation-of-duties conflict and printed on the report cover.',
            $lead,
            $owned->implode(', '),
        );

        if ($investigation->sod_conflict_note === $note && $investigation->has_sod_conflict) {
            return;
        }

        $investigation->update(['has_sod_conflict' => true, 'sod_conflict_note' => $note]);

        $this->recordSystemActivity($investigation, 'status_changed', 'Segregation-of-duties conflict flagged.', $actor, [
            'description' => $note,
        ]);

        $investigation->auditAction('investigation.sod_conflict_flagged', null, ['note' => $note]);
    }

    // ── Subjects ─────────────────────────────────────────────────────────

    /**
     * Name a subject. The §D.4-1 conflict can arrive from either
     * direction, so it is checked here as well as in assignTeamMember().
     */
    public function addSubject(Investigation $investigation, array $data, User $actor): InvestigationSubject
    {
        $this->assertNotArchived($investigation);

        $userId = $data['user_id'] ?? null;

        if ($userId && $investigation->teamMembers()->where('user_id', $userId)->exists()) {
            $name = User::find($userId)?->name ?? "User #{$userId}";

            throw ValidationException::withMessages([
                'user_id' => "{$name} is on this investigation's team and cannot also be named as a subject of it. Remove them from the team first.",
            ]);
        }

        $subject = InvestigationSubject::create([
            ...collect($data)->only([
                'subject_type', 'name', 'user_id', 'staff_id', 'account_number',
                'department', 'organisation_unit_id', 'position', 'role_in_case', 'notes',
            ])->all(),
            'tenant_id' => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'outcome' => 'pending',
        ]);

        $this->recordSystemActivity(
            $investigation,
            'comment',
            "Subject named: {$subject->name} ({$subject->role_in_case}).",
            $actor,
            ['linked' => $subject],
        );

        $investigation->auditAction('investigation.subject_added', null, [
            'subject_id' => $subject->id,
            'role_in_case' => $subject->role_in_case,
        ]);

        return $subject;
    }

    /**
     * An outcome recorded against a named person always carries a reason.
     * "Culpable" with no rationale is not defensible at a disciplinary
     * panel, and a platform that allows it is not helping.
     */
    public function recordSubjectOutcome(InvestigationSubject $subject, string $outcome, ?string $rationale, User $actor): InvestigationSubject
    {
        if (! in_array($outcome, InvestigationSubject::OUTCOMES, true)) {
            throw ValidationException::withMessages([
                'outcome' => "'{$outcome}' is not an outcome this platform records.",
            ]);
        }

        if ($outcome !== 'pending' && trim((string) $rationale) === '') {
            throw ValidationException::withMessages([
                'outcome_rationale' => 'Recording an outcome against a named person requires a written rationale.',
            ]);
        }

        $before = ['outcome' => $subject->outcome];

        $subject->update([
            'outcome' => $outcome,
            'outcome_rationale' => $rationale,
            'outcome_recorded_on' => $outcome === 'pending' ? null : now()->toDateString(),
            'outcome_recorded_by' => $outcome === 'pending' ? null : $actor->id,
        ]);

        $this->recordSystemActivity(
            $subject->investigation,
            'comment',
            "Outcome recorded for {$subject->name}: {$outcome}.",
            $actor,
            ['linked' => $subject],
        );

        $subject->auditAction('investigation.subject_outcome_recorded', $before, ['outcome' => $outcome]);

        return $subject->refresh();
    }

    // ── Findings ─────────────────────────────────────────────────────────

    public function addFinding(Investigation $investigation, array $data, User $actor): InvestigationFinding
    {
        $this->assertNotArchived($investigation);

        $finding = InvestigationFinding::create([
            ...collect($data)->only([
                'title', 'description', 'description_rich', 'severity',
                'root_cause', 'root_cause_rich', 'control_failure', 'control_failure_rich',
                'recommendation', 'recommendation_rich', 'financial_impact',
                'control_id', 'exception_id',
            ])->all(),
            'tenant_id' => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'reference' => InvestigationFinding::nextReference('INVF'),
            'raised_by' => $actor->id,
            'established_on' => $data['established_on'] ?? now()->toDateString(),
        ]);

        $this->recordSystemActivity(
            $investigation,
            'finding_added',
            "{$finding->severity} finding recorded: {$finding->title}.",
            $actor,
            ['linked' => $finding],
        );

        // A finding that names a control may have just created the §D.4-3
        // conflict, so re-evaluate the flag rather than only checking it
        // when the team changes.
        $this->flagControlOwnerConflict($investigation->refresh(), $actor);

        $investigation->auditAction('investigation.finding_added', null, [
            'finding' => $finding->reference,
            'severity' => $finding->severity,
            'control_id' => $finding->control_id,
        ]);

        return $finding;
    }

    public function updateFinding(InvestigationFinding $finding, array $data, User $actor): InvestigationFinding
    {
        $this->assertNotArchived($finding->investigation);

        $before = $finding->only(['severity', 'title', 'control_id', 'exception_id']);

        $finding->update(collect($data)->only([
            'title', 'description', 'description_rich', 'severity',
            'root_cause', 'root_cause_rich', 'control_failure', 'control_failure_rich',
            'recommendation', 'recommendation_rich', 'financial_impact',
            'control_id', 'exception_id', 'established_on',
        ])->all());

        $this->flagControlOwnerConflict($finding->investigation->refresh(), $actor);

        $finding->auditAction('investigation.finding_updated', $before, $finding->only(array_keys($before)));

        return $finding->refresh();
    }

    // ── Diary ────────────────────────────────────────────────────────────

    /**
     * A human's diary entry. Only the six manual types are accepted — the
     * system types are written by the workflow, and letting a request
     * forge one would make the chronology worthless as evidence.
     */
    public function recordActivity(Investigation $investigation, array $data, User $actor): InvestigationActivity
    {
        $type = $data['activity_type'] ?? 'comment';

        if (! in_array($type, InvestigationActivity::MANUAL_TYPES, true)) {
            throw ValidationException::withMessages([
                'activity_type' => "'{$type}' is written by the workflow, not logged by hand.",
            ]);
        }

        $activity = InvestigationActivity::create([
            'tenant_id' => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'activity_type' => $type,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'activity_date' => $data['activity_date'] ?? now(),
            'performed_by' => $actor->id,
        ]);

        $investigation->auditAction('investigation.activity_logged', null, ['activity_type' => $type]);

        return $activity;
    }

    /** The workflow's own diary rows — never reachable from a request. */
    public function recordSystemActivity(
        Investigation $investigation,
        string $type,
        string $title,
        ?User $actor = null,
        array $options = [],
    ): InvestigationActivity {
        $linked = $options['linked'] ?? null;

        return InvestigationActivity::create([
            'tenant_id' => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'activity_type' => $type,
            'title' => $title,
            'description' => $options['description'] ?? null,
            'activity_date' => $options['activity_date'] ?? now(),
            'performed_by' => $actor?->id,
            'linked_type' => $linked ? $linked::class : null,
            'linked_id' => $linked?->getKey(),
        ]);
    }

    /**
     * §D.3. Every read of a confidential investigation is written twice:
     * to the audit trail, and to the case timeline where an investigator
     * will actually see it. An access log nobody opens is not oversight.
     */
    public function recordAccess(Investigation $investigation, User $user): void
    {
        if (! $investigation->is_confidential) {
            $investigation->auditAction('viewed', null, ['user_id' => $user->id]);

            return;
        }

        InvestigationActivity::create([
            'tenant_id' => $investigation->tenant_id,
            'investigation_id' => $investigation->id,
            'activity_type' => 'confidential_view',
            'title' => "Confidential case file opened by {$user->name}.",
            'activity_date' => now(),
            'performed_by' => $user->id,
        ]);

        $investigation->auditAction('confidential_case_viewed', null, [
            'user_id' => $user->id,
            'reference' => $investigation->reference,
        ]);
    }

    // ── Evidence ─────────────────────────────────────────────────────────

    /** Chain-of-custody fields, and a diary row so the exhibit is on the chronology. */
    public function recordEvidenceCollection(Investigation $investigation, Evidence $evidence, array $custody, User $actor): Evidence
    {
        $evidence->update(collect($custody)->only([
            'collected_by', 'collected_on', 'collection_source', 'description',
        ])->all());

        $this->recordSystemActivity(
            $investigation,
            'evidence_collected',
            "Exhibit collected: {$evidence->file_name}.",
            $actor,
            [
                'description' => $custody['collection_source'] ?? null,
                'linked' => $evidence,
            ],
        );

        return $evidence->refresh();
    }

    /**
     * Everyone on the team, which on this module is exactly everyone who
     * can see the case — so the notification never reaches somebody the
     * visibility rules would refuse.
     */
    private function notifyTeam(Investigation $investigation, string $eventKey, $notification): void
    {
        $recipients = User::query()
            ->whereIn('id', $investigation->teamMembers()->pluck('user_id'))
            ->where('is_active', true)
            ->get();

        if ($recipients->isNotEmpty()) {
            $this->dispatcher->send($recipients, $eventKey, $notification);
        }
    }

    // ── Guards ───────────────────────────────────────────────────────────

    private function assertNotArchived(Investigation $investigation): void
    {
        if ($investigation->is_archived) {
            throw ValidationException::withMessages([
                'archive' => 'This investigation is archived. Restore it before making changes.',
            ]);
        }
    }
}
