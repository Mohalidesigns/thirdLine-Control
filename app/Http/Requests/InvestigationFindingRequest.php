<?php

namespace App\Http\Requests;

use App\Models\InvestigationFinding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvestigationFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:50000'],
            'description_rich' => ['nullable', 'array'],
            'severity' => ['required', Rule::in(InvestigationFinding::SEVERITIES)],
            'root_cause' => ['nullable', 'string', 'max:50000'],
            'root_cause_rich' => ['nullable', 'array'],
            'control_failure' => ['nullable', 'string', 'max:50000'],
            'control_failure_rich' => ['nullable', 'array'],
            'recommendation' => ['nullable', 'string', 'max:50000'],
            'recommendation_rich' => ['nullable', 'array'],
            'financial_impact' => ['nullable', 'numeric', 'min:0'],
            // The two links that make this module worth more here than in
            // internal audit (§F.2).
            'control_id' => ['nullable', 'exists:controls,id'],
            'exception_id' => ['nullable', 'exists:control_exceptions,id'],
            'established_on' => ['nullable', 'date'],
        ];
    }
}
