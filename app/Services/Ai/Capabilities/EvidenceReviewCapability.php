<?php

namespace App\Services\Ai\Capabilities;

use App\Models\CheckResult;
use App\Models\Evidence;
use App\Models\User;
use App\Services\Ai\CapabilityRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * §C.6 #4 — test evidence review.
 *
 * Advisory only, and structurally so: this class has no apply(), so there
 * is no code path by which its output reaches a check result. That is the
 * literal reading of the contract — "Advisory only; never sets a result" —
 * and the R4 test asserts it by attempting exactly that and expecting the
 * attempt to fail.
 *
 * It reviews evidence METADATA and extracted text, not the files. Evidence
 * in this platform routinely contains customer statements and account
 * detail; shipping the artefacts themselves to a model would breach R5 for
 * a judgement that can be made from filename, type, date, size and whatever
 * text extraction the tester already has on screen.
 */
class EvidenceReviewCapability extends Capability
{
    public function key(): string
    {
        return 'evidence.review';
    }

    public function request(User $user, array $input, ?Model $subject = null): CapabilityRequest
    {
        $checkResult = $subject instanceof CheckResult ? $subject : null;
        $checkItem = $checkResult?->checkItem;
        $instance = $checkResult?->testInstance;

        return new CapabilityRequest(
            capabilityKey: $this->key(),
            user: $user,
            variables: [
                'assertion' => $checkItem?->question ?? (string) ($input['assertion'] ?? ''),
                'procedure' => $checkItem?->guidance,
                'expected_evidence' => $checkItem?->expected_result,
                'testing_period' => $instance?->period_label,
                'tester_comment' => $checkResult?->comment,
                'evidence' => $this->evidenceMetadata($checkResult),
            ],
            subject: $checkResult,
            retrievalQuery: (string) ($checkItem?->question ?? ''),
            sources: ['control'],
        );
    }

    /**
     * Filename, type, size, dates and classification — never the file, and
     * never a filename belonging to an artefact flagged as containing
     * personal data. Even a filename leaks there: "Statement_Adeyemi_
     * 0123456789.pdf" is a customer name and an account number.
     *
     * The model can still do its job on what remains. Whether evidence
     * supports an assertion turns on what kind of artefact it is, when it
     * was captured relative to the testing period, and whether anything is
     * missing — all of which survive this filter.
     *
     * @return array<int, array<string, mixed>>
     */
    private function evidenceMetadata(?CheckResult $result): array
    {
        if (! $result) {
            return [];
        }

        return Evidence::query()
            ->where('linked_type', $result->getMorphClass())
            ->where('linked_id', $result->getKey())
            ->get()
            ->map(fn (Evidence $evidence) => array_filter([
                'filename' => $evidence->contains_personal_data
                    ? '[withheld: artefact flagged as containing personal data]'
                    : $evidence->file_name,
                'type' => $evidence->mime_type,
                'size_bytes' => $evidence->file_size,
                'classification' => $evidence->classification,
                'contains_personal_data' => (bool) $evidence->contains_personal_data,
                'uploaded_at' => $evidence->uploaded_at?->toDateString()
                    ?? $evidence->created_at?->toDateString(),
            ], fn ($v) => $v !== null && $v !== ''))
            ->values()
            ->all();
    }
}
