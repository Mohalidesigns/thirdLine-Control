<?php

namespace App\Http\Requests;

use App\Models\Framework;
use App\Models\RegulatoryObligation;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ObligationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'regulator_id' => ['required', 'exists:regulators,id'],
            'framework_id' => ['nullable', 'exists:frameworks,id'],
            'requirement_id' => ['nullable', 'exists:framework_requirements,id'],
            'obligation_ref' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'obligation_type' => ['required', Rule::in(RegulatoryObligation::TYPES)],
            'applies_to' => ['nullable', 'array'],
            'applies_to.entity_types' => ['nullable', 'array'],
            'applies_to.entity_types.*' => ['string', 'max:60'],
            'applies_to.licence_categories' => ['nullable', 'array'],
            'applies_to.licence_categories.*' => ['string', 'max:60'],
            'applies_to.jurisdictions' => ['nullable', 'array'],
            'applies_to.jurisdictions.*' => ['string', 'max:8'],
            'trigger_type' => ['required', Rule::in(RegulatoryObligation::TRIGGER_TYPES)],
            'frequency' => ['required', Rule::in(RegulatoryObligation::FREQUENCIES)],
            'due_rule' => ['required', 'array'],
            'due_rule.type' => ['required', Rule::in(['fixed_date', 'relative', 'event_relative'])],
            'due_rule.month' => ['nullable', 'integer', 'between:1,12'],
            'due_rule.day' => ['nullable', 'integer', 'between:1,31'],
            'due_rule.anchor' => ['nullable', 'string', 'max:40'],
            'due_rule.event' => ['nullable', 'string', 'max:60'],
            'due_rule.offset_days' => ['nullable', 'integer', 'between:-3650,3650'],
            'due_rule.offset_months' => ['nullable', 'integer', 'between:-120,120'],
            'due_rule.offset_hours' => ['nullable', 'integer', 'between:0,8760'],
            'due_rule.roll' => ['nullable', Rule::in(['next_business_day', 'previous_business_day', 'none'])],
            'grace_period_days' => ['nullable', 'integer', 'between:0,365'],
            'penalty_description' => ['nullable', 'string'],
            // R7: money arrives as integer minor units, never a decimal.
            'penalty_amount_minor' => ['nullable', 'integer', 'min:0'],
            'penalty_currency' => ['nullable', 'required_with:penalty_amount_minor', Rule::in(Money::SUPPORTED)],
            'penalty_basis' => ['nullable', 'required_with:penalty_amount_minor', Rule::in(RegulatoryObligation::PENALTY_BASES)],
            'legal_reference' => ['nullable', 'string', 'max:500'],
            'source_url' => ['nullable', 'url', 'max:500'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'verification_status' => ['required', Rule::in(Framework::VERIFICATION_STATUSES)],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'due_rule.type.required' => 'An obligation needs a due rule so the compliance calendar can date it.',
        ];
    }
}
