<?php

namespace App\Http\Requests;

use App\Models\ReportDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller authorises against ReportDefinitionPolicy.
    }

    public function rules(): array
    {
        $definition = $this->route('definition');

        return [
            'code' => [
                'required', 'string', 'max:60', 'regex:/^[A-Za-z0-9\-\_\.]+$/',
                Rule::unique('report_definitions', 'code')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->ignore($definition?->id)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'report_type' => ['required', Rule::in(ReportDefinition::TYPES)],
            'confidentiality' => ['required', Rule::in(ReportDefinition::CONFIDENTIALITY)],

            'output_formats' => ['required', 'array', 'min:1'],
            'output_formats.*' => [Rule::in(ReportDefinition::FORMATS)],

            'sections' => ['required', 'array', 'min:1', 'max:60'],
            'sections.*.type' => ['required', Rule::in(ReportDefinition::SECTION_TYPES)],
            'sections.*.title' => ['nullable', 'string', 'max:150'],
            'sections.*.subtitle' => ['nullable', 'string', 'max:250'],
            'sections.*.body' => ['nullable', 'string', 'max:20000'],
            'sections.*.source' => ['nullable', 'string', 'max:80'],
            'sections.*.chart_type' => ['nullable', 'string', 'max:30'],
            'sections.*.config' => ['nullable', 'array'],
            'sections.*.items' => ['nullable', 'array', 'max:8'],
            'sections.*.signatories' => ['nullable', 'array', 'max:8'],

            'parameters' => ['nullable', 'array'],
            'styling' => ['nullable', 'array'],
            'styling.orientation' => ['nullable', Rule::in(['portrait', 'landscape'])],
            'styling.paper' => ['nullable', Rule::in(['a4', 'letter', 'a3'])],
            'cover_page_config' => ['nullable', 'array'],
            'header_config' => ['nullable', 'array'],
            'footer_config' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
