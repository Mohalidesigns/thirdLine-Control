<?php

namespace App\Http\Requests;

use App\Models\Investigation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteInvestigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('complete', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            // The service refuses completion without these too — the rule
            // belongs to the workflow, not to one form.
            'risk_rating' => ['required', Rule::in(Investigation::RISK_RATINGS)],
            'completed_date' => ['nullable', 'date'],
            'conclusion' => ['nullable', 'string', 'max:50000'],
            'conclusion_rich' => ['nullable', 'array'],
            'confirmed_financial_loss' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
