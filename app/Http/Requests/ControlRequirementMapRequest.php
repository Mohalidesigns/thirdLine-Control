<?php

namespace App\Http\Requests;

use App\Models\ControlRequirementMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ControlRequirementMapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'control_id' => ['required', 'exists:controls,id'],
            'requirement_id' => ['required', 'exists:framework_requirements,id'],
            'coverage' => ['required', Rule::in(ControlRequirementMap::COVERAGE_LEVELS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
