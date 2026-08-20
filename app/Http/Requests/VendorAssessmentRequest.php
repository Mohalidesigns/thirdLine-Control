<?php

namespace App\Http\Requests;

use App\Models\VendorAssessment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VendorAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assess vendors');
    }

    public function rules(): array
    {
        return [
            'assessment_type' => ['required', Rule::in(VendorAssessment::TYPES)],
            'period_label' => ['nullable', 'string', 'max:40'],
            // The due-diligence questionnaire is the Phase 9 engine — this
            // points at the campaign whose questionnaire was used rather
            // than restating its questions here (R8).
            'campaign_id' => ['nullable', 'exists:csa_campaigns,id'],
            'response_id' => ['nullable', 'exists:csa_responses,id'],
            'risk_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'risk_band' => ['nullable', Rule::in(VendorAssessment::BANDS)],
            'conclusion' => ['nullable', 'string', 'max:4000'],
            'review_frequency_months' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }
}
