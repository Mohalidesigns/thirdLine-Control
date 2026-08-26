<?php

namespace App\Http\Requests;

use App\Models\Investigation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvestigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'description_rich' => ['nullable', 'array'],
            'category' => ['required', Rule::in(Investigation::CATEGORIES)],
            'source' => ['required', Rule::in(Investigation::SOURCES)],
            'priority' => ['required', Rule::in(Investigation::PRIORITIES)],
            'is_confidential' => ['nullable', 'boolean'],
            'control_entity_id' => ['nullable', 'exists:control_entities,id'],
            'organisation_unit_id' => ['nullable', 'exists:organisation_units,id'],
            'target_completion_date' => ['nullable', 'date'],
            'estimated_financial_impact' => ['nullable', 'numeric', 'min:0'],
            'confirmed_financial_loss' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'background' => ['nullable', 'string', 'max:50000'],
            'background_rich' => ['nullable', 'array'],
            'scope' => ['nullable', 'string', 'max:50000'],
            'scope_rich' => ['nullable', 'array'],
            'objectives' => ['nullable', 'string', 'max:50000'],
            'objectives_rich' => ['nullable', 'array'],
            'methodology' => ['nullable', 'string', 'max:50000'],
            'methodology_rich' => ['nullable', 'array'],
            'conclusion' => ['nullable', 'string', 'max:50000'],
            'conclusion_rich' => ['nullable', 'array'],
        ];
    }
}
