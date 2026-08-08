<?php

namespace App\Http\Requests;

use App\Models\DataSourceDataset;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DataSourceDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage data-sources');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'extraction_config' => ['nullable', 'array'],
            'schema_definition' => ['nullable', 'array'],
            'primary_key_fields' => ['nullable', 'array', 'max:10'],
            'primary_key_fields.*' => ['string', 'max:120'],
            'incremental_field' => ['nullable', 'string', 'max:120'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'pii_classification' => ['required', Rule::in(DataSourceDataset::PII_CLASSIFICATIONS)],
            'pii_fields' => ['nullable', 'array', 'max:50'],
            'pii_fields.*' => ['string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * A dataset that says it holds personal data but names no fields is
     * a declaration nobody can act on — SnapshotService would have
     * nothing to hash. Fail at authoring time rather than silently
     * retaining a BVN in clear (12.7, R5).
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $classification = $this->input('pii_classification');
                $fields = (array) $this->input('pii_fields', []);

                if (in_array($classification, ['personal', 'sensitive_personal'], true) && $fields === []) {
                    $validator->errors()->add(
                        'pii_fields',
                        'Name the fields that carry personal data — the classification alone does not tell the platform what to protect.',
                    );
                }
            },
        ];
    }
}
