<?php

namespace App\Http\Requests;

use App\Models\VendorContract;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VendorContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage vendor-contracts');
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['nullable', 'exists:entities,id'],
            'title' => ['required', 'string', 'max:255'],
            'contract_type' => ['nullable', 'string', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'auto_renew' => ['nullable', 'boolean'],
            'renewal_notice_days' => ['required', 'integer', 'min:0', 'max:730'],
            'value_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', Rule::in(Money::SUPPORTED)],
            'sla_commitments' => ['nullable', 'array', 'max:40'],
            'sla_commitments.*.label' => ['required', 'string', 'max:255'],
            'sla_commitments.*.target' => ['nullable', 'string', 'max:255'],
            'sla_commitments.*.measure' => ['nullable', 'string', 'max:255'],
            'sla_commitments.*.obligation_id' => ['nullable', 'exists:regulatory_obligations,id'],
            'obligation_id' => ['nullable', 'exists:regulatory_obligations,id'],
            'document_id' => ['nullable', 'exists:documents,id'],
            'owner_id' => ['nullable', 'tenant_user'],
            'status' => ['required', Rule::in(VendorContract::STATUSES)],
        ];
    }
}
