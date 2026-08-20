<?php

namespace App\Http\Requests;

use App\Dashboards\WidgetRegistry;
use App\Models\DashboardWidget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardWidgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller authorises against DashboardPolicy.
    }

    public function rules(): array
    {
        return [
            'widget_type' => ['required', Rule::in(DashboardWidget::TYPES)],
            'title' => ['required', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:200'],
            'data_source_key' => ['nullable', Rule::in(app(WidgetRegistry::class)->keys())],
            'query_config' => ['nullable', 'array'],
            'display_config' => ['nullable', 'array'],
            'drill_down_config' => ['nullable', 'array'],
            'position' => ['nullable', 'array'],
            'position.x' => ['nullable', 'integer', 'min:0', 'max:11'],
            'position.y' => ['nullable', 'integer', 'min:0', 'max:200'],
            'position.w' => ['nullable', 'integer', 'min:1', 'max:12'],
            'position.h' => ['nullable', 'integer', 'min:1', 'max:12'],
            'refresh_seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
            'permission_required' => ['nullable', 'string', 'max:120'],
            'is_visible' => ['nullable', 'boolean'],
        ];
    }
}
