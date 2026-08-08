<?php

namespace App\Services\Ai\Capabilities;

use App\Models\Control;
use App\Models\ControlRequirementMap;
use App\Models\FrameworkRequirement;
use App\Models\User;
use App\Services\Ai\AcceptedDraft;
use App\Services\Ai\CapabilityRequest;
use App\Services\Ai\Exceptions\AiDecisionRequiredException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * §C.6 #7 — framework mapping assistant.
 *
 * The contract is explicit: "every proposal goes to the maker-checker
 * queue". So applying writes ControlRequirementMap rows with mapped_by set
 * and approved_by deliberately null — they land in the existing Phase 8
 * approval queue and a second person still has to approve each one.
 *
 * That matters more here than it looks. Mapping coverage is what drives the
 * framework coverage heatmap and, downstream, what a bank tells a regulator
 * it has covered. An unapproved mapping that silently counted as coverage
 * would let a model create a compliance assertion nobody made.
 */
class FrameworkMapCapability extends Capability
{
    public function key(): string
    {
        return 'framework.map';
    }

    public function request(User $user, array $input, ?Model $subject = null): CapabilityRequest
    {
        $requirement = $subject instanceof FrameworkRequirement ? $subject : null;

        return new CapabilityRequest(
            capabilityKey: $this->key(),
            user: $user,
            variables: [
                'requirement' => $requirement ? [
                    'ref' => $requirement->ref_code,
                    'title' => $requirement->title,
                    'description' => $requirement->description,
                    'guidance' => $requirement->guidance,
                    'framework' => $requirement->framework?->name,
                ] : (array) ($input['requirement'] ?? []),
                'candidate_controls' => $this->candidates($requirement, $input),
                'coverage_levels' => ControlRequirementMap::COVERAGE_LEVELS,
            ],
            subject: $requirement,
            retrievalQuery: trim(($requirement?->title ?? '').' '.($requirement?->description ?? '')),
        );
    }

    public function isApplicable(): bool
    {
        return true;
    }

    public function apply(AcceptedDraft $draft, User $approver): Model
    {
        $requirement = $draft->interaction->subject;

        if (! $requirement instanceof FrameworkRequirement) {
            throw new AiDecisionRequiredException('This mapping draft is not attached to a requirement.');
        }

        $mappings = array_values(array_filter(
            (array) $draft->get('mappings', []),
            fn ($m) => is_array($m) && is_numeric($m['control_id'] ?? null),
        ));

        if ($mappings === []) {
            throw new AiDecisionRequiredException('This draft proposes no mapping to record.');
        }

        return DB::transaction(function () use ($mappings, $requirement, $draft, $approver) {
            $first = null;

            foreach ($mappings as $mapping) {
                $control = Control::find((int) $mapping['control_id']);

                // The model may only propose against controls it was shown,
                // and the candidate list was built from what this user can
                // read. A control that has vanished, or was never a
                // candidate, is skipped rather than resolved.
                if (! $control) {
                    continue;
                }

                $record = ControlRequirementMap::updateOrCreate(
                    [
                        'tenant_id' => $approver->tenant_id,
                        'control_id' => $control->id,
                        'requirement_id' => $requirement->id,
                    ],
                    [
                        'coverage' => $this->oneOf(
                            $mapping['coverage'] ?? null,
                            ControlRequirementMap::COVERAGE_LEVELS,
                            'partial',
                        ),
                        'notes' => $this->note($mapping),
                        'mapped_by' => $approver->id,
                        'mapped_at' => now(),
                        // The maker-checker gate. Never set here.
                        'approved_by' => null,
                        'approved_at' => null,
                    ],
                );

                $record->auditAction('ai_mapping_proposed', null, [
                    ...$draft->provenance(),
                    'model_confidence' => $mapping['confidence'] ?? null,
                ]);

                $first ??= $record;
            }

            if (! $first) {
                throw new AiDecisionRequiredException(
                    'None of the proposed mappings referenced a control you can access.',
                );
            }

            $draft->recordApplication($first);

            return $first;
        });
    }

    /** @param array<string, mixed> $mapping */
    private function note(array $mapping): string
    {
        return trim(sprintf(
            "AI-proposed mapping, pending approval.\nRationale: %s\nModel confidence: %s",
            $mapping['rationale'] ?? 'not stated',
            isset($mapping['confidence']) ? (string) $mapping['confidence'] : 'not stated',
        ));
    }

    /**
     * Candidate controls, pre-filtered by keyword so the model is choosing
     * from a shortlist rather than being handed the whole library. A tenant
     * with nine hundred controls would otherwise blow the context window and
     * pay for the privilege.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, array<string, mixed>>
     */
    private function candidates(?FrameworkRequirement $requirement, array $input): array
    {
        $terms = array_slice(array_filter(
            preg_split('/\W+/', mb_strtolower((string) ($requirement?->title ?? $input['requirement']['title'] ?? ''))) ?: [],
            fn (string $t) => mb_strlen($t) > 3,
        ), 0, 8);

        return Control::query()
            ->where('status', 'Active')
            ->when($terms !== [], fn ($q) => $q->where(function ($w) use ($terms) {
                foreach ($terms as $term) {
                    $w->orWhere('title', 'like', "%{$term}%")
                        ->orWhere('objective', 'like', "%{$term}%");
                }
            }))
            ->orderBy('control_ref')
            ->limit(40)
            ->get(['id', 'control_ref', 'title', 'objective', 'type', 'nature'])
            ->map(fn (Control $control) => [
                'control_id' => $control->id,
                'ref' => $control->control_ref,
                'title' => $control->title,
                'objective' => $control->objective,
                'type' => $control->type,
            ])->all();
    }
}
