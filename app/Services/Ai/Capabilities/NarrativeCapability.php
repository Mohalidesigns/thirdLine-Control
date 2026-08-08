<?php

namespace App\Services\Ai\Capabilities;

use App\Models\User;
use App\Services\Ai\CapabilityRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * §C.6 #6 — narrative generation.
 *
 * Drafts the management commentary that sits above the numbers in a board
 * report or a regulatory pack.
 *
 * No apply(). The draft is returned to the review panel and the author
 * pastes what they want into the section they are writing, which means the
 * narrative in a filed pack was placed there by the person who signed it.
 * Writing straight into a SubmissionPack would be worse than pointless: an
 * approved pack is immutable and its completeness score and unverified-item
 * list are computed from what a person put in it (Phase 13), so a
 * side-channel write would corrupt both.
 *
 * The prompt is grounded strictly in retrieved records and the caller's
 * supplied figures. Where the data does not support a claim, the contract
 * requires it in data_gaps rather than in the prose — a board narrative
 * that fills its own gaps is the most dangerous output in this phase.
 */
class NarrativeCapability extends Capability
{
    public function key(): string
    {
        return 'narrative.generate';
    }

    public function request(User $user, array $input, ?Model $subject = null): CapabilityRequest
    {
        $section = (string) ($input['section'] ?? 'Management commentary');
        $audience = (string) ($input['audience'] ?? 'the Board Audit Committee');

        return new CapabilityRequest(
            capabilityKey: $this->key(),
            user: $user,
            variables: [
                'section_title' => $section,
                'audience' => $audience,
                'period' => (string) ($input['period'] ?? ''),
                'tone' => (string) ($input['tone'] ?? 'formal, factual, no advocacy'),
                'word_limit' => (int) ($input['word_limit'] ?? 350),
                // Figures the caller has already computed. The model may
                // interpret these; it may not invent others.
                'supplied_figures' => (array) ($input['figures'] ?? []),
            ],
            subject: $subject,
            retrievalQuery: trim($section.' '.(string) ($input['focus'] ?? '')),
        );
    }
}
