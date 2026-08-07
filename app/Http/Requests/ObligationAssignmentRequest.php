<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ObligationAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'obligation_id' => ['required', 'exists:regulatory_obligations,id'],
            'entity_id' => ['required', 'exists:organisation_units,id'],
            'owner_id' => ['required', 'exists:users,id'],
            'reviewer_id' => ['nullable', 'different:owner_id', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'reviewer_id.different' => 'The reviewer must be someone other than the obligation owner.',
        ];
    }
}
