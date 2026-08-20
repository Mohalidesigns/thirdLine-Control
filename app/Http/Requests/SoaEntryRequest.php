<?php

namespace App\Http\Requests;

use App\Models\SoaEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SoaEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage soa');
    }

    public function rules(): array
    {
        return [
            'requirement_id' => ['required', 'integer', Rule::exists('framework_requirements', 'id')],
            'is_applicable' => ['required', 'boolean'],
            // Excluding an Annex A control from scope without saying why is
            // exactly what a certification auditor rejects.
            'justification' => ['required_if:is_applicable,false', 'nullable', 'string', 'max:2000'],
            'implementation_status' => ['required', Rule::in(SoaEntry::IMPLEMENTATION_STATUSES)],
        ];
    }
}
