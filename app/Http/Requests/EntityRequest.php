<?php

namespace App\Http\Requests;

use App\Models\Entity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorises via EntityPolicy
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'trading_name' => ['nullable', 'string', 'max:255'],
            'entity_type' => ['required', Rule::in(Entity::ENTITY_TYPES)],
            'parent_entity_id' => ['nullable', 'integer', Rule::exists('entities', 'id')],
            'organisation_unit_id' => ['nullable', 'integer', Rule::exists('organisation_units', 'id')],
            'jurisdiction' => ['required', 'string', 'size:2'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'licence_categories' => ['nullable', 'array'],
            'licence_categories.*' => ['string', 'max:120'],
            'regulators' => ['nullable', 'array'],
            'regulators.*' => ['string', 'max:120'],
            'fiscal_year_end' => ['nullable', 'string', 'regex:/^\d{2}-\d{2}$/'],
            'functional_currency' => ['required', 'string', 'size:3',
                Rule::in(['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'EUR', 'GBP'])],
            'consolidation_method' => ['required', Rule::in(Entity::CONSOLIDATION_METHODS)],
            'ownership_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_regulated' => ['boolean'],
            // Set at creation only — a change is a re-provisioning event
            // (16.2); the model throws on a runtime edit.
            'data_residency_country' => [$this->isMethod('post') ? 'required' : 'prohibited', 'string', 'size:2'],
            'status' => ['required', Rule::in(Entity::STATUSES)],
        ];
    }
}
