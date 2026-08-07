<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantSecurityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage settings');
    }

    public function rules(): array
    {
        return [
            'mfa_enforced_roles' => ['present', 'array'],
            'mfa_enforced_roles.*' => ['string', Rule::exists('roles', 'name')],
            'mfa_grace_period_days' => ['required', 'integer', 'min:0', 'max:90'],
            'mfa_sms_opt_in' => ['required', 'boolean'],
            'break_glass_daily_cap' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }
}
