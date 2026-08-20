<?php

namespace App\Http\Requests;

use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;

class SpotCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'scope_description' => ['nullable', 'string'],
            'scope_description_rich' => ['nullable', 'array', new RichTextRule],
            'unit_id' => ['nullable', 'exists:organisation_units,id'],
            'process_id' => ['nullable', 'exists:business_processes,id'],
            'control_ids' => ['nullable', 'array'],
            'control_ids.*' => ['exists:controls,id'],
            'is_surprise' => ['boolean'],
            'date_conducted' => ['required', 'date'],
        ];
    }
}
