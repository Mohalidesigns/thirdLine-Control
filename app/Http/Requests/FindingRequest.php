<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'control_id' => ['nullable', 'exists:controls,id'],
            'observation' => ['required', 'string'],
            'severity' => ['required', Rule::in(['Critical', 'High', 'Medium', 'Low'])],
            'risk_implication' => ['nullable', 'string'],
            'recommendation' => ['nullable', 'string'],
            'management_response' => ['nullable', 'string'],
            'agreed_action' => ['nullable', 'string'],
            'responsible_party_id' => ['nullable', 'tenant_user'],
            'target_date' => ['nullable', 'date'],
        ];
    }
}
