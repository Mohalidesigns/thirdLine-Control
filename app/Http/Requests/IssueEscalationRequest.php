<?php

namespace App\Http\Requests;

use App\Models\ExceptionEscalation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** CR1.1: the fan-out issue form — one entry per addressed target. */
class IssueEscalationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorizes via ExceptionEscalationPolicy::issue
    }

    public function rules(): array
    {
        return [
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.target_type' => ['required', Rule::in(ExceptionEscalation::TARGET_TYPES)],
            'targets.*.unit_id' => ['nullable', 'exists:organisation_units,id', 'required_if:targets.*.target_type,unit'],
            'targets.*.process_id' => ['nullable', 'exists:business_processes,id', 'required_if:targets.*.target_type,process'],
            'targets.*.respondent_id' => ['nullable', 'exists:users,id', 'required_if:targets.*.target_type,user'],
            'targets.*.external_name' => ['nullable', 'string', 'max:255', 'required_if:targets.*.target_type,external_party'],
            'targets.*.external_email' => ['nullable', 'email', 'max:255', 'required_if:targets.*.target_type,external_party'],
            'targets.*.external_organisation' => ['nullable', 'string', 'max:255'],
            'targets.*.cc_user_ids' => ['nullable', 'array'],
            'targets.*.cc_user_ids.*' => ['integer', 'exists:users,id'],
            'targets.*.issue_note' => ['required', 'string', 'max:5000'],
            'targets.*.required_response' => ['nullable', Rule::in(ExceptionEscalation::REQUIRED_RESPONSES)],
        ];
    }
}
