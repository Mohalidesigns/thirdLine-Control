<?php

namespace App\Http\Requests;

use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskAssessmentScale;
use App\Services\RiskAssessmentService;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RiskAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assess', $this->route('risk') ?? Risk::class);
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;
        $assessments = app(RiskAssessmentService::class);
        $maxLikelihood = $assessments->maxLevel($tenantId, 'likelihood');
        $maxImpact = $assessments->maxLevel($tenantId, 'impact');

        $dimensionRules = [];

        foreach (RiskAssessmentScale::DIMENSIONS as $dimension) {
            $dimensionRules["impact_dimensions.{$dimension}"] = ['nullable', 'integer', "between:1,{$maxImpact}"];
        }

        return [
            'assessment_type' => ['required', Rule::in(RiskAssessment::TYPES)],
            'likelihood_level' => ['required', 'integer', "between:1,{$maxLikelihood}"],
            'impact_level' => ['required', 'integer', "between:1,{$maxImpact}"],
            'likelihood_rationale' => ['nullable', 'string', 'max:2000'],
            'impact_rationale' => ['nullable', 'string', 'max:2000'],
            'impact_dimensions' => ['nullable', 'array'],
            ...$dimensionRules,
            'confidence' => ['nullable', Rule::in(['Low', 'Medium', 'High'])],
            'method' => ['nullable', Rule::in(RiskAssessment::METHODS)],
            'period_label' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'assessed_at' => ['nullable', 'date', 'before_or_equal:now'],

            // Quantitative path — integer minor units only (R7).
            'financial_impact_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', Rule::in(Money::SUPPORTED)],
            'loss_min_minor' => ['nullable', 'integer', 'min:0', 'required_with:loss_likely_minor'],
            'loss_likely_minor' => ['nullable', 'integer', 'min:0', 'gte:loss_min_minor'],
            'loss_max_minor' => ['nullable', 'integer', 'min:0', 'gte:loss_likely_minor'],
            'likelihood_percent' => ['nullable', 'integer', 'between:0,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'loss_likely_minor.gte' => 'The most-likely loss cannot be below the best case.',
            'loss_max_minor.gte' => 'The worst case cannot be below the most-likely loss.',
        ];
    }
}
