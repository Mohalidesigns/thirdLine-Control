<?php

namespace App\Http\Requests;

use App\Models\Control;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ControlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route + policy gate access; rules only here.
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'objective' => ['nullable', 'string'],
            'type' => ['required', Rule::in(Control::TYPES)],
            'nature' => ['required', Rule::in(Control::NATURES)],
            'category_id' => ['nullable', 'exists:control_categories,id'],
            'coso_component' => ['nullable', Rule::in(Control::COSO_COMPONENTS)],
            'process_id' => ['nullable', 'exists:business_processes,id'],
            'unit_id' => ['nullable', 'exists:organisation_units,id'],
            'department' => ['nullable', 'string', 'max:255'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'frequency' => ['required', Rule::in(Control::FREQUENCIES)],
            'test_frequency' => ['nullable', Rule::in(Control::FREQUENCIES)],
            'is_key_control' => ['boolean'],
            'library_level' => ['nullable', Rule::in(['group', 'entity'])],
            'function_grouping' => ['nullable', Rule::in(Control::FUNCTION_GROUPINGS)],
            'control_level' => ['nullable', Rule::in(Control::CONTROL_LEVELS)],
            'is_distributable' => ['boolean'],
            'framework_refs' => ['nullable', 'array'],
            'framework_refs.*' => ['string', 'max:255'],
            'control_documentation' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'change_reason' => [$this->isMethod('put') || $this->isMethod('patch') ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }
}
