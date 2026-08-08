<?php

namespace App\Http\Requests;

use App\Models\SodConflictRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SodConflictRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage sod-rules');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'system_key' => ['nullable', 'string', 'max:60'],
            'function_a' => ['required', 'string', 'max:255'],
            'function_b' => ['required', 'string', 'max:255'],
            'conflict_type' => ['required', Rule::in(SodConflictRule::CONFLICT_TYPES)],
            'risk_level' => ['required', Rule::in(SodConflictRule::RISK_LEVELS)],
            'mitigating_control_id' => ['nullable', 'exists:controls,id'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (strcasecmp(trim((string) $this->input('function_a')), trim((string) $this->input('function_b'))) === 0) {
                    $validator->errors()->add('function_b', 'A conflict needs two different functions.');
                }
            },
        ];
    }
}
