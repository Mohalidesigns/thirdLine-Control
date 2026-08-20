<?php

namespace App\Http\Requests;

use App\Models\Vendor;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage vendors');
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['nullable', 'exists:entities,id'],
            'legal_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'criticality' => ['required', Rule::in(Vendor::CRITICALITIES)],
            'services_provided' => ['nullable', 'string', 'max:4000'],
            'jurisdiction' => ['required', 'string', 'size:2'],
            'processing_country' => ['nullable', 'string', 'size:2'],
            'relationship_start' => ['nullable', 'date'],
            'relationship_end' => ['nullable', 'date', 'after_or_equal:relationship_start'],
            'annual_spend_minor' => ['nullable', 'integer', 'min:0'],
            'spend_currency' => ['required', Rule::in(Money::SUPPORTED)],
            'owner_id' => ['nullable', 'tenant_user'],
            'regulator_notification_required' => ['nullable', 'boolean'],
            'notification_obligation_id' => ['nullable', 'exists:regulatory_obligations,id'],
            'data_access_classification' => ['required', Rule::in(Vendor::DATA_CLASSIFICATIONS)],
            'sub_processors' => ['nullable', 'array', 'max:50'],
            'sub_processors.*.name' => ['required', 'string', 'max:255'],
            'sub_processors.*.country' => ['nullable', 'string', 'size:2'],
            'sub_processors.*.service' => ['nullable', 'string', 'max:255'],
            'review_frequency_months' => ['required', 'integer', 'min:1', 'max:60'],
            'status' => ['required', Rule::in(Vendor::STATUSES)],
        ];
    }
}
