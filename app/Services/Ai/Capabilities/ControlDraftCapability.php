<?php

namespace App\Services\Ai\Capabilities;

use App\Models\CheckItem;
use App\Models\Control;
use App\Models\TestScript;
use App\Models\User;
use App\Services\Ai\AcceptedDraft;
use App\Services\Ai\CapabilityRequest;
use App\Services\ControlService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * §C.6 #1 — control drafting and RCM generation.
 *
 * Applying a draft creates a control in Draft status with its test script,
 * and nothing else. It does not activate it, does not approve it and does
 * not mark the script approved: the control still goes through the same
 * maker-checker gate as one typed by hand, and the person who accepted the
 * AI draft is recorded as its creator, so they cannot then approve it (R2,
 * R4). The assistant saves the typing, not the governance.
 */
class ControlDraftCapability extends Capability
{
    public function key(): string
    {
        return 'control.draft';
    }

    public function request(User $user, array $input, ?Model $subject = null): CapabilityRequest
    {
        $sourceText = $this->describeSubject($subject) ?: (string) ($input['prompt'] ?? '');

        return new CapabilityRequest(
            capabilityKey: $this->key(),
            user: $user,
            variables: [
                'source_kind' => $subject ? class_basename($subject) : 'free text',
                'source_description' => $sourceText,
                'additional_instructions' => (string) ($input['instructions'] ?? ''),
                'control_types' => Control::TYPES,
                'control_natures' => Control::NATURES,
                'control_frequencies' => Control::FREQUENCIES,
            ],
            subject: $subject,
            // Retrieve neighbouring controls so the draft matches the house
            // style and does not duplicate something already in the library.
            retrievalQuery: $sourceText,
            meta: ['subject_id' => $subject?->getKey()],
        );
    }

    public function isApplicable(): bool
    {
        return true;
    }

    public function apply(AcceptedDraft $draft, User $approver): Model
    {
        return DB::transaction(function () use ($draft, $approver) {
            $control = app(ControlService::class)->create([
                'title' => $this->fit($draft->get('title') ?? $draft->get('objective'), 255) ?? 'Untitled control',
                'objective' => $draft->get('objective'),
                'description' => $draft->get('description'),
                'type' => $this->oneOf($draft->get('type'), Control::TYPES, 'Preventive'),
                'nature' => $this->oneOf($draft->get('nature'), Control::NATURES, 'Manual'),
                'frequency' => $this->oneOf($draft->get('frequency'), Control::FREQUENCIES, 'Monthly'),
                'is_key_control' => (bool) $draft->get('key_control', false),
                'control_documentation' => $this->evidenceNote($draft->get('evidence_requirements', [])),
            ], $approver);

            $this->attachTestScript($control, $draft, $approver);

            // The provenance lands on the control's own audit trail, so an
            // examiner reading the control three years from now sees that it
            // was AI-drafted and who approved it without needing this module.
            $control->auditAction('ai_drafted', null, $draft->provenance());

            $draft->recordApplication($control);

            return $control;
        });
    }

    private function attachTestScript(Control $control, AcceptedDraft $draft, User $approver): void
    {
        $script = (array) $draft->get('test_script', []);
        $items = (array) ($script['check_items'] ?? []);

        if ($items === []) {
            return;
        }

        $testScript = TestScript::create([
            'tenant_id' => $control->tenant_id,
            'control_id' => $control->id,
            'version_no' => 1,
            'title' => $this->fit($script['name'] ?? null, 255) ?? 'Test script for '.$control->control_ref,
            'objective' => $script['objective'] ?? $control->objective,
            'sampling_guidance' => $script['sampling_guidance'] ?? null,
            // Draft, always. An AI-written test script is exactly the thing
            // a second person should read before anyone tests against it.
            'status' => 'Draft',
            'created_by' => $approver->id,
        ]);

        foreach (array_values($items) as $index => $item) {
            CheckItem::create([
                'test_script_id' => $testScript->id,
                'sequence' => $index + 1,
                'question' => $this->fit($item['assertion'] ?? null, 500) ?? 'Unspecified assertion',
                'guidance' => $item['procedure'] ?? null,
                'expected_result' => $item['evidence'] ?? null,
                'is_mandatory' => true,
                'default_severity_on_fail' => $this->oneOf(
                    $item['severity'] ?? null,
                    ['Critical', 'High', 'Medium', 'Low'],
                    'Medium',
                ),
            ]);
        }
    }

    /** @param array<int, mixed> $requirements */
    private function evidenceNote(array $requirements): ?string
    {
        $lines = array_filter(array_map(
            fn ($r) => is_string($r) ? trim($r) : null,
            $requirements,
        ));

        return $lines === [] ? null : "Evidence requirements (AI draft, pending review):\n- ".implode("\n- ", $lines);
    }

    private function describeSubject(?Model $subject): string
    {
        if (! $subject) {
            return '';
        }

        return trim(implode("\n", array_filter([
            $subject->title ?? null,
            $subject->description ?? null,
            $subject->guidance ?? null,
            $subject->objective ?? null,
        ])));
    }
}
