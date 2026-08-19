<?php

namespace App\Http\Requests;

use App\Models\ControlException;
use App\Models\ExceptionRoutingRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** CR-01: routing rule admin — pre-fills the issue form, never bypasses it. */
class ExceptionRoutingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated on 'configure exception-routing'
    }

    public function rules(): array
    {
        return [
            'sequence' => ['required', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['boolean'],
            'match_unit_id' => ['nullable', 'exists:organisation_units,id'],
            'match_process_id' => ['nullable', 'exists:business_processes,id'],
            'match_control_category_id' => ['nullable', 'exists:control_categories,id'],
            'match_severity' => ['nullable', Rule::in(ControlException::SEVERITIES)],
            'match_source_type' => ['nullable', 'string', 'max:50'],
            'route_to_type' => ['required', Rule::in(ExceptionRoutingRule::ROUTE_TO_TYPES)],
            'route_to_role' => ['nullable', 'string', 'max:100', 'required_if:route_to_type,role'],
            'route_to_user_id' => ['nullable', 'exists:users,id', 'required_if:route_to_type,user'],
            'route_to_unit_id' => ['nullable', 'exists:organisation_units,id'],
            'cc_role' => ['nullable', 'string', 'max:100'],
            'cc_user_ids' => ['nullable', 'array'],
            'cc_user_ids.*' => ['integer', 'exists:users,id'],
            'sla_policy_id' => ['nullable', 'exists:exception_sla_policies,id'],
        ];
    }
}
