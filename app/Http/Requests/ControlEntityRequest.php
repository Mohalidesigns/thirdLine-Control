<?php

namespace App\Http\Requests;

use App\Models\ControlEntity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ControlEntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorises via ControlEntityPolicy
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'control_unit_id' => ['required', 'integer',
                Rule::exists('control_units', 'id')->where('tenant_id', $tenantId)],
            'parent_id' => ['nullable', 'integer',
                Rule::exists('control_entities', 'id')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'description_rich' => ['nullable', 'array'],
            'entity_kind' => ['required', Rule::in(ControlEntity::KINDS)],
            // R-A: a branch row must bridge the operational tree. The
            // service re-checks; this is the first, user-facing gate.
            'organisation_unit_id' => [
                Rule::requiredIf(fn () => $this->input('entity_kind') === 'branch'),
                'nullable', 'integer',
                Rule::exists('organisation_units', 'id')->where('tenant_id', $tenantId),
            ],
            'business_process_id' => ['nullable', 'integer',
                Rule::exists('business_processes', 'id')->where('tenant_id', $tenantId)],
            'owner_id' => ['nullable', 'integer', 'tenant_user'],
            'risk_rating' => ['nullable', Rule::in(ControlEntity::RISK_RATINGS)],
            'review_frequency' => ['nullable', Rule::in(ControlEntity::REVIEW_FREQUENCIES)],
            'last_reviewed_at' => ['nullable', 'date'],
            'next_review_due_at' => ['nullable', 'date'],
            'is_template' => ['boolean'],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
