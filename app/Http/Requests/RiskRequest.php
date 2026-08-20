<?php

namespace App\Http\Requests;

use App\Models\Risk;
use App\Rules\RichTextRule;
use App\Services\RiskAssessmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $assessments = app(RiskAssessmentService::class);
        $maxLikelihood = $assessments->maxLevel($tenantId, 'likelihood');
        $maxImpact = $assessments->maxLevel($tenantId, 'impact');

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'description_rich' => ['nullable', 'array', new RichTextRule],
            'category' => ['nullable', 'string', 'max:255'],
            // Bounds follow the tenant's configured scales, never a
            // hard-coded 5×5 grid (R1).
            'inherent_likelihood' => ['required', 'integer', "between:1,{$maxLikelihood}"],
            'inherent_impact' => ['required', 'integer', "between:1,{$maxImpact}"],
            'risk_owner_id' => ['nullable', 'tenant_user'],

            'risk_category_id' => ['nullable', 'exists:risk_categories,id'],
            'risk_type' => ['nullable', Rule::in(Risk::TYPES)],
            'risk_source' => ['nullable', Rule::in(Risk::SOURCES)],
            'taxonomy_ref' => ['nullable', 'string', 'max:100'],
            'basel_event_type' => ['nullable', Rule::in(Risk::BASEL_EVENT_TYPES)],
            'velocity' => ['nullable', Rule::in(Risk::VELOCITIES)],
            'is_emerging' => ['nullable', 'boolean'],
            'horizon' => ['nullable', 'string', 'max:60'],
            'appetite_id' => ['nullable', 'exists:risk_appetites,id'],
            'parent_risk_id' => ['nullable', 'exists:risks,id', 'different:id'],
            'second_line_reviewer_id' => ['nullable', 'tenant_user'],
            'entity_id' => ['nullable', 'exists:organisation_units,id'],
            'process_id' => ['nullable', 'exists:business_processes,id'],
            'financial_impact_minor' => ['nullable', 'integer', 'min:0'],
            'loss_currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
