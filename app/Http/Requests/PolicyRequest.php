<?php

namespace App\Http\Requests;

use App\Models\Policy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $policy = $this->route('policy');

        return $policy
            ? $this->user()->can('update', $policy)
            : $this->user()->can('create', Policy::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'policy_type' => ['required', Rule::in(Policy::TYPES)],
            'category_id' => ['nullable', 'exists:policy_categories,id'],
            'document_id' => ['nullable', 'exists:documents,id'],
            'owner_id' => ['required', 'tenant_user'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'review_frequency' => ['required', Rule::in(Policy::REVIEW_FREQUENCIES)],
            'applies_to' => ['nullable', 'array'],
            'applies_to.all_staff' => ['nullable', 'boolean'],
            'applies_to.role_names' => ['nullable', 'array'],
            'applies_to.role_names.*' => ['string', 'max:100'],
            'applies_to.unit_ids' => ['nullable', 'array'],
            'applies_to.unit_ids.*' => ['integer', 'exists:organisation_units,id'],
            'applies_to.user_ids' => ['nullable', 'array'],
            'applies_to.user_ids.*' => ['integer', 'tenant_user'],
            'requires_attestation' => ['nullable', 'boolean'],
            'attestation_frequency' => [
                'nullable',
                Rule::requiredIf(fn () => $this->boolean('requires_attestation')),
                Rule::in(Policy::ATTESTATION_FREQUENCIES),
            ],
            'supersedes_policy_id' => ['nullable', 'exists:policies,id'],
            'mandatory_training' => ['nullable', 'boolean'],

            'sections' => ['nullable', 'array', 'max:200'],
            'sections.*.id' => ['nullable', 'integer'],
            'sections.*.parent_id' => ['nullable', 'integer'],
            'sections.*.sequence' => ['nullable', 'integer', 'min:0'],
            'sections.*.heading' => ['required', 'string', 'max:255'],
            'sections.*.body' => ['nullable', 'string'],
            'sections.*.is_mandatory' => ['nullable', 'boolean'],
            'sections.*.control_ids' => ['nullable', 'array'],
            'sections.*.control_ids.*' => ['integer', 'exists:controls,id'],
            'sections.*.requirement_ids' => ['nullable', 'array'],
            'sections.*.requirement_ids.*' => ['integer', 'exists:framework_requirements,id'],
        ];
    }
}
