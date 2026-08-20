<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
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
            // Includes soft-deleted rows — a deleted user's address stays
            // reserved so their historical attribution is never ambiguous.
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:150'],
            'unit_id' => ['nullable', 'integer', Rule::exists('organisation_units', 'id')],
            'reports_to' => ['nullable', 'integer',
                Rule::exists('users', 'id')->where('tenant_id', $this->user()->tenant_id)],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'password_mode' => ['required', Rule::in(['invite', 'temporary'])],
            'temporary_password' => ['required_if:password_mode,temporary', 'nullable', Password::defaults()],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'A user with this email address already exists.',
            'roles.required' => 'Assign at least one role.',
        ];
    }
}
