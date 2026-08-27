<?php

namespace App\Http\Requests;

use App\Models\InvestigationSubject;
use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvestigationSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('investigation'));
    }

    /**
     * DEF-M02 — the Add-subject dialog could never save.
     *
     * This request is shared by store and update. The outcome rules below
     * are written for update, where a dialog posts the whole subject back
     * including its outcome. The create dialog has no outcome field at
     * all — an outcome is recorded later, from a different dialog — so
     * `outcome` arrived absent, `required_unless:outcome,pending` saw a
     * value that was not 'pending', and demanded a rationale for an
     * outcome nobody had reached. Every attempt to name a subject 422'd.
     *
     * Defaulting the absent case to the column's own default makes the
     * two paths agree, and keeps the pairing rule intact for the dialog
     * that genuinely records an outcome.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('outcome')) {
            $this->merge(['outcome' => 'pending']);
        }
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
            'outcome_rationale_rich' => ['nullable', 'array', new RichTextRule],
        ];
    }
}
