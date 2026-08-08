<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use App\Models\User;
use App\Services\Ai\Exceptions\AiDecisionRequiredException;

/**
 * What the gateway returns: a suggestion, never a decision (R4).
 *
 * The payload is private and there is no getter for it. A draft exposes
 * only preview() — enough to render a review panel — and the way to obtain
 * the payload for persistence is accept(), which requires a User, writes
 * the decision to the audit log, and returns an AcceptedDraft. Domain
 * services take AcceptedDraft, so the type system carries the rule.
 *
 * Rejecting is a first-class outcome with a mandatory reason, because the
 * rejection reasons are the honest quality signal for the capability — an
 * assistant nobody trusts shows up as a rejection rate, not as silence.
 */
class AiDraft
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $citations
     */
    public function __construct(
        public readonly AiInteraction $interaction,
        private readonly array $payload,
        public readonly float $confidence,
        public readonly array $citations = [],
        public readonly bool $insufficientContext = false,
    ) {}

    /**
     * Everything the review panel needs, and nothing a service could
     * mistake for something it may save. `content` is here because a human
     * has to read the suggestion to judge it — but reading it in a panel
     * and persisting it are different acts, and only the second needs
     * accept().
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        return [
            'interaction_id' => $this->interaction->getKey(),
            'capability' => $this->interaction->capability_key,
            'model' => $this->interaction->model,
            'prompt_version' => $this->interaction->prompt_version,
            'content' => $this->payload,
            'confidence' => round($this->confidence, 3),
            'citations' => $this->citations,
            'insufficient_context' => $this->insufficientContext,
            'low_confidence' => $this->confidence < $this->surfaceFloor(),
            'decision' => $this->interaction->human_decision,
            'status' => $this->interaction->status,
            'tokens' => $this->interaction->totalTokens(),
            'latency_ms' => $this->interaction->latency_ms,
            'created_at' => $this->interaction->created_at?->toIso8601String(),
        ];
    }

    /**
     * A person makes the suggestion real.
     *
     * $edits are the reviewer's changes, merged over the model's output and
     * recorded as a diff — the difference between "Accepted" and "Edited"
     * is the number the governance page reports as the honest measure of
     * how good the drafting actually is.
     *
     * @param  array<string, mixed>  $edits
     */
    public function accept(User $approver, array $edits = []): AcceptedDraft
    {
        if ($this->interaction->status !== 'Completed') {
            throw new AiDecisionRequiredException(
                'This draft did not complete successfully and cannot be accepted.',
            );
        }

        if ($this->interaction->human_decision !== 'Pending') {
            throw new AiDecisionRequiredException(sprintf(
                'This draft has already been %s.',
                strtolower($this->interaction->human_decision),
            ));
        }

        if ($this->insufficientContext) {
            throw new AiDecisionRequiredException(
                'This draft reported that it had insufficient information, so there is nothing to accept.',
            );
        }

        $final = $edits === [] ? $this->payload : array_replace_recursive($this->payload, $edits);
        $diff = $this->diff($this->payload, $final);

        $this->interaction->update([
            'human_decision' => $diff === [] ? 'Accepted' : 'Edited',
            'decided_by' => $approver->getKey(),
            'decided_at' => now(),
            'edit_diff' => $diff === [] ? null : $diff,
        ]);

        $this->interaction->auditAction('ai_draft_accepted', null, [
            'capability' => $this->interaction->capability_key,
            'model' => $this->interaction->model,
            'prompt_version' => $this->interaction->prompt_version,
            'confidence' => $this->confidence,
            'edited' => $diff !== [],
            'approver_id' => $approver->getKey(),
        ]);

        return new AcceptedDraft($this->interaction->refresh(), $approver, $final);
    }

    /**
     * A reason is required. "Rejected" with no reason tells the next prompt
     * author nothing, and this feedback is the only input they get.
     */
    public function reject(User $user, string $reason, ?string $category = null): AiInteraction
    {
        if ($this->interaction->human_decision !== 'Pending') {
            throw new AiDecisionRequiredException(sprintf(
                'This draft has already been %s.',
                strtolower($this->interaction->human_decision),
            ));
        }

        $this->interaction->update([
            'human_decision' => 'Rejected',
            'decided_by' => $user->getKey(),
            'decided_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $this->interaction->feedback()->create([
            'tenant_id' => $this->interaction->tenant_id,
            'user_id' => $user->getKey(),
            'was_useful' => false,
            'issue_category' => $category ?: 'other',
            'comment' => $reason,
        ]);

        $this->interaction->auditAction('ai_draft_rejected', null, [
            'capability' => $this->interaction->capability_key,
            'reason' => $reason,
            'category' => $category,
            'rejected_by' => $user->getKey(),
        ]);

        return $this->interaction->refresh();
    }

    /** Rebuild a draft from a stored interaction, for the review panel. */
    public static function fromInteraction(AiInteraction $interaction): self
    {
        return new self(
            $interaction,
            (array) ($interaction->parsed_output ?? []),
            (float) ($interaction->confidence ?? 0.0),
            (array) ($interaction->citations ?? []),
            (bool) data_get($interaction->parsed_output, 'insufficient_context', false),
        );
    }

    private function surfaceFloor(): float
    {
        return (float) config('ai.output.min_confidence_to_surface', 0.35);
    }

    /**
     * Shallow-by-key diff. Deliberately not a deep structural diff: what a
     * reviewer needs to see is which fields they changed, and a nested
     * character-level diff of a test script buries that.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(array $before, array $after): array
    {
        $diff = [];

        foreach ($after as $key => $value) {
            if (! array_key_exists($key, $before) || $before[$key] !== $value) {
                $diff[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        return $diff;
    }
}
