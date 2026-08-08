<?php

namespace App\Http\Requests;

use App\Models\RiskAssessment;
use App\Models\RiskTreatment;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RiskTreatmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create treatments');
    }

    public function rules(): array
    {
        return [
            'strategy' => ['required', Rule::in(RiskTreatment::STRATEGIES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'owner_id' => ['required', 'exists:users,id'],
            'target_rating' => ['nullable', Rule::in(RiskAssessment::RATINGS)],
            'cost_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', Rule::in(Money::SUPPORTED)],
            'benefit_description' => ['nullable', 'string', 'max:2000'],
            'start_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'control_id' => ['nullable', 'exists:controls,id'],
            'alerts_enabled' => ['nullable', 'boolean'],
            'alert_lead_days' => ['nullable', 'integer', 'between:0,90'],
            'milestones' => ['nullable', 'array', 'max:20'],
            'milestones.*.title' => ['required', 'string', 'max:255'],
            'milestones.*.due_at' => ['nullable', 'date'],
            'milestones.*.owner_id' => ['nullable', 'exists:users,id'],
        ];
    }
}
