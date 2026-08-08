<?php

namespace App\Services\Ai\Capabilities;

use App\Models\RegulatoryChange;
use App\Models\RegulatoryObligation;
use App\Models\User;
use App\Services\Ai\AcceptedDraft;
use App\Services\Ai\CapabilityRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * §C.6 #2 — regulatory intelligence.
 *
 * The R10 capability. Every obligation this produces is written with
 * verification_status = 'unverified' and stays excluded from generated
 * regulatory submissions until a person checks it against the regulator's
 * own document. A model that has read a circular is a good first pass at
 * an obligation register; it is not a source of legal fact, and a
 * hallucinated section number in a filing is a regulatory incident.
 *
 * The change itself lands as 'New', so it enters the existing Phase 8
 * impact-assessment workflow rather than skipping to Assessed.
 */
class RegulatoryParseCapability extends Capability
{
    public function key(): string
    {
        return 'regulatory.parse';
    }

    public function request(User $user, array $input, ?Model $subject = null): CapabilityRequest
    {
        $text = (string) ($input['document_text'] ?? $subject?->summary ?? '');

        return new CapabilityRequest(
            capabilityKey: $this->key(),
            user: $user,
            variables: [
                'circular_text' => $text,
                'known_reference' => (string) ($input['reference'] ?? $subject?->reference ?? ''),
                'regulator_hint' => (string) ($input['regulator'] ?? ''),
            ],
            subject: $subject,
            // Retrieve the existing register so the model can diff against
            // what is already recorded rather than proposing duplicates.
            retrievalQuery: mb_substr($text, 0, 2000),
        );
    }

    public function isApplicable(): bool
    {
        return true;
    }

    public function apply(AcceptedDraft $draft, User $approver): Model
    {
        return DB::transaction(function () use ($draft, $approver) {
            $change = RegulatoryChange::create([
                'tenant_id' => $approver->tenant_id,
                'title' => $this->fit($draft->get('summary'), 255) ?? 'Regulatory change (AI draft)',
                'reference' => $this->fit($draft->get('reference'), 100),
                'published_at' => $this->date($draft->get('published_at')),
                'effective_at' => $this->date($draft->get('effective_at')),
                'summary' => $draft->get('summary'),
                // 'New', not 'Impact Assessed'. The model's read of a
                // circular is an input to the assessment, not the assessment.
                'status' => 'New',
                'source' => 'AI',
                'affected_control_ids' => $this->ids($draft->get('affected_control_ids', [])),
            ]);

            $this->draftObligations($change, $draft, $approver);

            $change->auditAction('ai_drafted', null, $draft->provenance());
            $draft->recordApplication($change);

            return $change;
        });
    }

    /**
     * Proposed obligations, every one unverified (R10).
     *
     * They are written as inactive as well: an obligation that is live
     * generates instances and compliance calendar entries, and an unverified
     * deadline generating a real due date is how a bank ends up chasing a
     * date the regulator never set.
     */
    private function draftObligations(RegulatoryChange $change, AcceptedDraft $draft, User $approver): void
    {
        foreach ((array) $draft->get('obligations', []) as $proposed) {
            if (! is_array($proposed) || blank($proposed['title'] ?? null)) {
                continue;
            }

            RegulatoryObligation::create([
                'tenant_id' => $approver->tenant_id,
                'regulator_id' => $change->regulator_id,
                'obligation_ref' => RegulatoryObligation::nextReference('OBL'),
                'title' => $this->fit($proposed['title'], 255),
                'description' => $proposed['description'] ?? null,
                'obligation_type' => $this->fit($proposed['type'] ?? null, 60),
                'frequency' => $this->fit($proposed['frequency'] ?? null, 40),
                'due_rule' => $this->fit($proposed['due_rule'] ?? null, 255),
                'penalty_description' => $proposed['penalty'] ?? null,
                'legal_reference' => $this->fit($proposed['legal_reference'] ?? null, 255),
                // The whole point of R10.
                'verification_status' => 'unverified',
                'is_active' => false,
            ]);
        }
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            // A model inventing "the second Tuesday after publication" is
            // not a date. Dropping it is better than storing a guess.
            return null;
        }
    }

    /** @return array<int, int> */
    private function ids(mixed $value): array
    {
        return array_values(array_filter(array_map(
            fn ($v) => is_numeric($v) ? (int) $v : null,
            (array) $value,
        )));
    }
}
