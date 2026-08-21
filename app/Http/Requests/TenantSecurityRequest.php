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
            // Activity-log retention (CR3): a tenant may extend the window
            // beyond the platform default, never shorten it — enforced in
            // ArchiveAuditTrail, mirrored here for an honest form error.
            'audit_retention_months' => ['nullable', 'integer', 'min:12', 'max:600'],
        ];
    }
}
