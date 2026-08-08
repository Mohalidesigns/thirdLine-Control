<?php

namespace App\Http\Requests;

use App\Models\Metric;
use App\Models\MetricThreshold;
use App\Services\MetricService;
use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MetricRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage metrics');
    }

    public function rules(): array
    {
        $metricId = $this->route('metric')?->id;

        return [
            'metric_type' => ['required', Rule::in(Metric::TYPES)],
            'code' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/',
                Rule::unique('metrics', 'code')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->ignore($metricId)
                    ->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'category_id' => ['nullable', 'exists:risk_categories,id'],
            'formula' => ['nullable', 'string', 'max:1000'],
            'unit' => ['nullable', 'string', 'max:40'],
            'data_type' => ['required', Rule::in(Metric::DATA_TYPES)],
            'currency' => ['nullable', Rule::in(Money::SUPPORTED), 'required_if:data_type,currency'],
            'direction' => ['required', Rule::in(Metric::DIRECTIONS)],
            'frequency' => ['required', Rule::in(Metric::FREQUENCIES)],
            'owner_id' => ['nullable', 'exists:users,id'],
            'source' => ['required', Rule::in(Metric::SOURCES)],
            'calculation_expression' => ['nullable', 'string', 'max:1000', 'required_if:source,calculated'],
            'entity_id' => ['nullable', 'exists:organisation_units,id'],
            'is_active' => ['nullable', 'boolean'],
            'aggregation' => ['required', Rule::in(Metric::AGGREGATIONS)],
            'target_value' => ['nullable', 'numeric'],

            'thresholds' => ['nullable', 'array', 'max:8'],
            'thresholds.*.level' => ['required', Rule::in(MetricThreshold::LEVELS)],
            'thresholds.*.operator' => ['required', Rule::in(MetricThreshold::OPERATORS)],
            'thresholds.*.value_from' => ['required', 'numeric'],
            'thresholds.*.value_to' => ['nullable', 'numeric', 'required_if:thresholds.*.operator,between,outside'],
            'thresholds.*.colour_hex' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'thresholds.*.action_required' => ['nullable', 'string', 'max:1000'],
            'thresholds.*.escalate_to_role' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The calculation expression is parsed here, not at capture time — an
     * unsafe or unresolvable expression never reaches the database.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->filled('calculation_expression')) {
                    return;
                }

                try {
                    app(MetricService::class)->validateExpression(
                        $this->user()->tenant_id,
                        $this->input('calculation_expression'),
                    );
                } catch (ValidationException $e) {
                    $validator->errors()->add(
                        'calculation_expression',
                        $e->validator->errors()->first() ?: 'The expression could not be parsed.',
                    );
                } catch (\InvalidArgumentException $e) {
                    $validator->errors()->add('calculation_expression', $e->getMessage());
                }
            },
        ];
    }
}
