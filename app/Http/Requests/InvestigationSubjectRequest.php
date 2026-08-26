<?php

namespace App\Http\Requests;

use App\Models\InvestigationSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvestigationSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::in(InvestigationSubject::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'user_id' => ['nullable', 'tenant_user'],
            'staff_id' => ['nullable', 'string', 'max:60'],
            'account_number' => ['nullable', 'string', 'max:40'],
            'department' => ['nullable', 'string', 'max:255'],
            'organisation_unit_id' => ['nullable', 'exists:organisation_units,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'role_in_case' => ['required', Rule::in(InvestigationSubject::ROLES_IN_CASE)],
            'notes' => ['nullable', 'string', 'max:5000'],
            // An outcome against a named person always carries its reason;
            // the service enforces the pairing, this states it on the form.
            'outcome' => ['nullable', Rule::in(InvestigationSubject::OUTCOMES)],
            'outcome_rationale' => ['nullable', 'required_unless:outcome,pending', 'string', 'max:5000'],
        ];
    }
}
