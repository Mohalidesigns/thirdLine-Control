<?php

namespace App\Http\Requests;

use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestScriptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'control_id' => ['required', 'exists:controls,id'],
            'title' => ['required', 'string', 'max:255'],
            'objective' => ['nullable', 'string'],
            'objective_rich' => ['nullable', 'array', new RichTextRule],
            'sampling_guidance' => ['nullable', 'string'],
            'sampling_guidance_rich' => ['nullable', 'array', new RichTextRule],
            'check_items' => ['required', 'array', 'min:1'],
            'check_items.*.question' => ['required', 'string'],
            'check_items.*.guidance' => ['nullable', 'string'],
            'check_items.*.expected_result' => ['nullable', 'string'],
            'check_items.*.is_mandatory' => ['boolean'],
            'check_items.*.default_severity_on_fail' => ['required', Rule::in(['Critical', 'High', 'Medium', 'Low'])],
        ];
    }
}
