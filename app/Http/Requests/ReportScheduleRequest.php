<?php

namespace App\Http\Requests;

use App\Models\ReportDefinition;
use App\Models\ReportSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller authorises against ReportSchedulePolicy.
    }

    public function rules(): array
    {
        return [
            'report_definition_id' => ['required', 'integer', 'exists:report_definitions,id'],
            'name' => ['required', 'string', 'max:150'],
            // Five space-separated cron fields. Anything else is rejected at
            // the edge rather than failing silently at 03:00.
            'cron' => ['required', 'string', 'max:100', 'regex:/^(\S+\s+){4}\S+$/'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'parameters' => ['nullable', 'array'],
            'output_format' => ['required', Rule::in(ReportDefinition::FORMATS)],
            'recipients' => ['nullable', 'array'],
            'recipients.user_ids' => ['nullable', 'array'],
            'recipients.user_ids.*' => ['integer', 'tenant_user'],
            'recipients.role_names' => ['nullable', 'array'],
            'recipients.role_names.*' => ['string', 'exists:roles,name'],
            'delivery_method' => ['required', Rule::in(ReportSchedule::DELIVERY_METHODS)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
