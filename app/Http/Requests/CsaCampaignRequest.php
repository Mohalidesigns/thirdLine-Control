<?php

namespace App\Http\Requests;

use App\Models\CsaCampaign;
use App\Models\CsaQuestionnaire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CsaCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage campaigns');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'campaign_type' => ['required', Rule::in(CsaCampaign::TYPES)],
            'period_label' => ['nullable', 'string', 'max:100'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after:opens_at'],
            'reminder_schedule' => ['nullable', 'array'],
            'is_anonymous' => ['boolean'],
            'variance_threshold' => ['nullable', 'integer', 'min:0', 'max:5'],
            'response_rate_target' => ['nullable', 'integer', 'min:1', 'max:100'],
            'scoring_method' => ['nullable', Rule::in(CsaQuestionnaire::SCORING_METHODS)],
            'pass_threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'instructions' => ['nullable', 'string'],
            'scope_definition' => ['nullable', 'array'],
            'scope_definition.assignments' => ['nullable', 'array'],
            'scope_definition.assignments.*.respondent_id' => ['required_with:scope_definition.assignments', 'integer', 'tenant_user'],
            'scope_definition.assignments.*.entity_id' => ['nullable', 'integer', 'exists:organisation_units,id'],
            'scope_definition.assignments.*.control_id' => ['nullable', 'integer', 'exists:controls,id'],
            'scope_definition.invited_count' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // An anonymous campaign is only meaningful for surveys.
        if ($this->input('campaign_type') !== 'survey') {
            $this->merge(['is_anonymous' => false]);
        }
    }
}
