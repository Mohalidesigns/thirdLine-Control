<?php

namespace App\Http\Requests;

use App\Models\SpeakUpCase;
use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', SpeakUpCase::class);
    }

    public function rules(): array
    {
        return [
            'case_type' => ['required', Rule::in(SpeakUpCase::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:8000'],
            'description_rich' => ['nullable', 'array', new RichTextRule],
            'confidentiality' => ['required', Rule::in(SpeakUpCase::CONFIDENTIALITY_LEVELS)],
            'severity' => ['required', Rule::in(SpeakUpCase::SEVERITIES)],
            'is_anonymous' => ['nullable', 'boolean'],
            'channel' => ['nullable', 'string', 'max:40'],
            'entity_id' => ['nullable', 'exists:organisation_units,id'],
            'subject_persons' => ['nullable', 'array', 'max:20'],
            'subject_persons.*.name' => ['required', 'string', 'max:255'],
            'subject_persons.*.role' => ['nullable', 'string', 'max:120'],
            'lead_investigator_id' => ['nullable', 'tenant_user'],
            'access_user_ids' => ['nullable', 'array', 'max:50'],
            'access_user_ids.*' => ['integer', 'tenant_user'],
            'related_incident_id' => ['nullable', 'exists:incidents,id'],
            'related_complaint_id' => ['nullable', 'exists:complaints,id'],
        ];
    }
}
