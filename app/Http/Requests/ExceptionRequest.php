<?php

namespace App\Http\Requests;

use App\Models\ControlException;
use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'description_rich' => ['nullable', 'array', new RichTextRule],
            'root_cause' => ['nullable', 'string'],
            'root_cause_rich' => ['nullable', 'array', new RichTextRule],
            'severity' => ['required', Rule::in(ControlException::SEVERITIES)],
            'control_id' => ['nullable', 'exists:controls,id'],
            'risk_id' => ['nullable', 'exists:risks,id'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'responsible_party_id' => ['nullable', 'exists:users,id'],
            'unit_id' => ['nullable', 'exists:organisation_units,id'],
            'target_closure_date' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
