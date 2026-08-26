<?php

namespace App\Http\Requests;

use App\Models\ConsequenceAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvestigationConsequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recommendConsequence', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'action_type' => ['required', Rule::in(ConsequenceAction::ACTION_TYPES)],
            'investigation_subject_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:10000'],
            'due_date' => ['nullable', 'date'],
            'evidence_id' => ['nullable', 'exists:evidence,id'],
        ];
    }
}
