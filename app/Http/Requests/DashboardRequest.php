<?php

namespace App\Http\Requests;

use App\Dashboards\WidgetRegistry;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller authorises against DashboardPolicy.
    }

    public function rules(): array
    {
        $keys = app(WidgetRegistry::class)->keys();

        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['nullable', Rule::in(Dashboard::VISIBILITIES)],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'refresh_interval_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],

            'widgets' => ['nullable', 'array', 'max:'.config('dashboards.max_widgets')],
            'widgets.*.widget_type' => ['required', Rule::in(DashboardWidget::TYPES)],
            'widgets.*.title' => ['required', 'string', 'max:120'],
            'widgets.*.subtitle' => ['nullable', 'string', 'max:200'],
            // The allowlist, enforced at the edge as well as in the service:
            // a widget may only ever name a registered source.
            'widgets.*.data_source_key' => ['nullable', Rule::in($keys)],
            'widgets.*.query_config' => ['nullable', 'array'],
            'widgets.*.display_config' => ['nullable', 'array'],
            'widgets.*.drill_down_config' => ['nullable', 'array'],
            'widgets.*.position' => ['nullable', 'array'],
            'widgets.*.position.x' => ['nullable', 'integer', 'min:0', 'max:11'],
            'widgets.*.position.y' => ['nullable', 'integer', 'min:0', 'max:200'],
            'widgets.*.position.w' => ['nullable', 'integer', 'min:1', 'max:12'],
            'widgets.*.position.h' => ['nullable', 'integer', 'min:1', 'max:12'],
            'widgets.*.refresh_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'widgets.*.permission_required' => ['nullable', 'string', 'max:120'],
            'widgets.*.is_visible' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'widgets.*.data_source_key.in' => 'That widget points at a dataset the platform does not publish.',
        ];
    }
}
