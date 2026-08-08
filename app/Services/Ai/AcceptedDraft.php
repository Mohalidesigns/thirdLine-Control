<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use App\Models\User;
use App\Services\Ai\Exceptions\AiDecisionRequiredException;
use Illuminate\Database\Eloquent\Model;

/**
 * A draft a named human has accepted. The only object in the system that
 * will hand over a model-generated payload for persistence.
 *
 * The constructor is the wall, not a comment. It re-reads the interaction
 * and refuses to exist unless a human decision has actually been recorded
 * against it — so there is no way to fabricate one from a Pending draft,
 * including from inside the AI layer itself. A domain service that types
 * its parameter as AcceptedDraft cannot be handed raw model output by any
 * caller, which is what makes R4 structural rather than a convention.
 */
class AcceptedDraft
{
    public function __construct(
        public readonly AiInteraction $interaction,
        public readonly User $approver,
        private readonly array $payload,
    ) {
        // Re-read rather than trusting the in-memory model: a caller could
        // have set the attribute without saving it.
        $decision = AiInteraction::withoutGlobalScopes()
            ->whereKey($interaction->getKey())
            ->first(['human_decision', 'decided_by']);

        if (! $decision || ! in_array($decision->human_decision, ['Accepted', 'Edited'], true)) {
            throw new AiDecisionRequiredException(
                'This AI draft has not been accepted by a person and cannot be applied.',
            );
        }

        if ((int) $decision->decided_by !== (int) $approver->getKey()) {
            throw new AiDecisionRequiredException(
                'The approver on this draft does not match the person applying it.',
            );
        }
    }

    /**
     * The content to persist — post-edit where the reviewer changed it.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload, $key, $default);
    }

    /**
     * Attributes every record created from an AI draft carries, so the
     * provenance survives in the domain table and not only in the AI log.
     * A control drafted by the assistant and approved by a person should
     * say so on its own row, three years later, to an examiner who has
     * never heard of this module.
     *
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        return [
            'ai_interaction_id' => $this->interaction->getKey(),
            'ai_capability' => $this->interaction->capability_key,
            'ai_model' => $this->interaction->model,
            'ai_prompt_version' => $this->interaction->prompt_version,
            'approved_by' => $this->approver->getKey(),
        ];
    }

    /** Link the interaction to what it became. Closes the trail both ways. */
    public function recordApplication(Model $record): void
    {
        $this->interaction->update([
            'applied_type' => $record->getMorphClass(),
            'applied_id' => $record->getKey(),
        ]);

        $this->interaction->auditAction('ai_draft_applied', null, [
            'capability' => $this->interaction->capability_key,
            'applied_to' => $record->getMorphClass().'#'.$record->getKey(),
            'approver_id' => $this->approver->getKey(),
        ]);
    }
}
