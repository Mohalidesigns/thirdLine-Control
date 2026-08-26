<?php

namespace App\Http\Requests;

use App\Models\Investigation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvestigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Investigation::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'description_rich' => ['nullable', 'array'],
            'category' => ['required', Rule::in(Investigation::CATEGORIES)],
            'source' => ['required', Rule::in(Investigation::SOURCES)],
            'priority' => ['required', Rule::in(Investigation::PRIORITIES)],
            'is_confidential' => ['nullable', 'boolean'],
            'control_entity_id' => ['nullable', 'exists:control_entities,id'],
            'organisation_unit_id' => ['nullable', 'exists:organisation_units,id'],
            'lead_investigator_id' => ['nullable', 'tenant_user'],
            'team_member_ids' => ['nullable', 'array', 'max:30'],
            'team_member_ids.*' => ['integer', 'tenant_user'],
            'reported_date' => ['nullable', 'date'],
            'target_completion_date' => ['nullable', 'date', 'after_or_equal:reported_date'],
            'estimated_financial_impact' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            // Provenance is set at creation and never afterwards (§D.2).
            'origin_type' => ['nullable', Rule::in(['case', 'exception', 'incident', 'complaint', 'test_instance'])],
            'origin_id' => ['nullable', 'required_with:origin_type', 'integer'],
        ];
    }
}
