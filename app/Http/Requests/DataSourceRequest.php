<?php

namespace App\Http\Requests;

use App\Models\DataSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Credentials and connection settings are write-only: they arrive here,
 * are encrypted by the model, and are never sent back. A blank
 * `credentials` on an update means "leave the stored secret alone", not
 * "clear it" — the controller strips the key rather than writing null
 * over a working password (R9).
 */
class DataSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage data-sources');
    }

    public function rules(): array
    {
        $sourceId = $this->route('data_source')?->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('data_sources', 'name')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->ignore($sourceId)
                    ->whereNull('deleted_at'),
            ],
            'source_type' => ['required', Rule::in(DataSource::SOURCE_TYPES)],
            'system_category' => ['required', Rule::in(DataSource::SYSTEM_CATEGORIES)],
            'vendor_key' => ['required', Rule::in(DataSource::VENDOR_KEYS)],
            'auth_type' => ['required', Rule::in(DataSource::AUTH_TYPES)],
            'connection_config' => ['nullable', 'array'],
            'credentials' => ['nullable', 'string', 'max:8000'],
            'schedule_cron' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
            'is_active' => ['nullable', 'boolean'],
            'failure_threshold' => ['nullable', 'integer', 'min:1', 'max:100'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
            'data_residency_note' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', 'tenant_user'],
        ];
    }

    public function messages(): array
    {
        return [
            'timezone.timezone' => 'Give a valid IANA timezone, for example Africa/Lagos.',
        ];
    }
}
