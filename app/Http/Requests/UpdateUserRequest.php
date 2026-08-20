<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage users') ?? false;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user'))],
            'phone' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:150'],
            'unit_id' => ['nullable', 'integer', Rule::exists('organisation_units', 'id')],
            'reports_to' => ['nullable', 'integer',
                Rule::exists('users', 'id')->where('tenant_id', $this->user()->tenant_id),
                Rule::notIn([$this->route('user')?->id])],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'A user with this email address already exists.',
            'roles.required' => 'Assign at least one role.',
            'reports_to.not_in' => 'A user cannot be their own line manager.',
        ];
    }
}
