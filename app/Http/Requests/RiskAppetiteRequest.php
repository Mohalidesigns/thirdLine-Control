<?php

namespace App\Http\Requests;

use App\Models\RiskAppetite;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RiskAppetiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage appetite');
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['nullable', 'exists:organisation_units,id'],
            'risk_category_id' => ['nullable', 'exists:risk_categories,id'],
            'statement' => ['required', 'string', 'max:4000'],
            'appetite_level' => ['required', Rule::in(RiskAppetite::LEVELS)],
            'tolerance_lower' => ['nullable', 'numeric', 'min:0'],
            'tolerance_upper' => ['nullable', 'numeric', 'min:0', 'gte:tolerance_lower'],
            'capacity' => ['nullable', 'numeric', 'min:0', 'gte:tolerance_upper'],
            'metric_definition' => ['nullable', 'string', 'max:2000'],
            'effective_from' => ['nullable', 'date'],
            'review_due_at' => ['nullable', 'date', 'after:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'tolerance_upper.gte' => 'The upper tolerance must sit at or above the lower tolerance.',
            'capacity.gte' => 'Capacity is the absolute ceiling — it cannot be below the tolerance.',
        ];
    }
}
