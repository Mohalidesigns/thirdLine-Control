<?php

namespace App\Http\Requests;

use App\Models\ImprovementAction;
use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImprovementActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create improvements');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'description_rich' => ['nullable', 'array', new RichTextRule],
            'category' => ['nullable', 'string', 'max:100'],
            'priority' => ['required', Rule::in(ImprovementAction::PRIORITIES)],
            'source_type' => ['required', Rule::in(ImprovementAction::SOURCES)],
            'source_id' => ['nullable', 'integer'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'due_at' => ['nullable', 'date', 'after_or_equal:today'],
            'benefit_description' => ['nullable', 'string'],
            'effort_estimate' => ['nullable', 'string', 'max:100'],
            'control_id' => ['nullable', 'exists:controls,id'],
            'risk_id' => ['nullable', 'exists:risks,id'],
        ];
    }
}
