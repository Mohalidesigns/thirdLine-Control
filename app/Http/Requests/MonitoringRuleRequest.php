<?php

namespace App\Http\Requests;

use App\Models\DataSourceDataset;
use App\Models\MonitoringRule;
use App\Rules\RichTextRule;
use App\Services\RuleEngineService;
use App\Services\SamplingService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MonitoringRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage monitoring-rules');
    }

    public function rules(): array
    {
        return [
            'control_id' => ['nullable', 'exists:controls,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'description_rich' => ['nullable', 'array', new RichTextRule],
            'rule_type' => ['required', Rule::in(MonitoringRule::TYPES)],
            'dataset_ids' => ['required', 'array', 'min:1', 'max:5'],
            'dataset_ids.*' => ['integer', 'exists:data_source_datasets,id'],
            'rule_definition' => ['required', 'array'],
            'severity' => ['required', Rule::in(['Critical', 'High', 'Medium', 'Low'])],
            'frequency' => ['required', Rule::in(MonitoringRule::FREQUENCIES)],
            'schedule_cron' => ['nullable', 'string', 'max:100'],
            'sample_config' => ['nullable', 'array'],
            'sample_config.method' => ['nullable', Rule::in(SamplingService::METHODS)],
            'sample_config.size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'sample_config.stratum_field' => ['nullable', 'string', 'max:120'],
            'sample_config.value_field' => ['nullable', 'string', 'max:120'],
            'tolerance' => ['nullable', 'array'],
            'exception_template' => ['nullable', 'array'],
            'exception_template.title' => ['nullable', 'string', 'max:255'],
            'exception_template.description' => ['nullable', 'string', 'max:4000'],
            'exception_template.severity' => ['nullable', Rule::in(['Critical', 'High', 'Medium', 'Low'])],
            'auto_create_exception' => ['nullable', 'boolean'],
            'auto_create_incident' => ['nullable', 'boolean'],
            'owner_id' => ['nullable', 'tenant_user'],
        ];
    }

    /**
     * Two checks that cannot be expressed as validation rules: the
     * datasets must belong to this tenant (a foreign id in dataset_ids
     * would otherwise read another tenant's data), and the rule
     * definition must satisfy its type's schema — including the SQL
     * guard for custom_sql, which runs here so a rejected statement
     * never reaches the database (12.3, R5).
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $datasetIds = array_map('intval', (array) $this->input('dataset_ids', []));

                $ownCount = DataSourceDataset::query()
                    ->whereIn('id', $datasetIds)
                    ->count();

                if ($ownCount !== count(array_unique($datasetIds))) {
                    $validator->errors()->add('dataset_ids', 'One or more datasets are not available to this tenant.');

                    return;
                }

                try {
                    app(RuleEngineService::class)->validateDefinition(
                        (string) $this->input('rule_type'),
                        (array) $this->input('rule_definition', []),
                        $datasetIds,
                    );
                } catch (ValidationException $e) {
                    foreach ($e->validator->errors()->messages() as $key => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($key, $message);
                        }
                    }
                }
            },
        ];
    }
}
