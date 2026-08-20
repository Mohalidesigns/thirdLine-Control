<?php

namespace App\Http\Requests;

use App\Models\ControlStakeholder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ControlStakeholderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware gates 'manage control-stakeholders'
    }

    public function rules(): array
    {
        return [
            'organisation_unit_id' => ['required', 'integer',
                Rule::exists('organisation_units', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'role' => ['required', Rule::in(ControlStakeholder::ROLES)],
            'user_id' => ['nullable', 'integer', 'tenant_user'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'notes_rich' => ['nullable', 'array'],
        ];
    }
}
