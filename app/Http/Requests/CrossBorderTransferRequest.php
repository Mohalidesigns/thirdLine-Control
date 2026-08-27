<?php

namespace App\Http\Requests;

use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrossBorderTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorises via CrossBorderTransferPolicy
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['nullable', 'integer', Rule::exists('entities', 'id')],
            'direction' => ['required', Rule::in(['outbound', 'inbound'])],
            'data_categories' => ['required', 'array', 'min:1'],
            'data_categories.*' => ['string', 'max:120'],
            'contains_personal_data' => ['required', 'boolean'],
            'description' => ['required', 'string', 'max:2000'],
            'description_rich' => ['nullable', 'array', new RichTextRule],
            'destination_country' => ['required', 'string', 'size:2'],
            'recipient' => ['required', 'string', 'max:255'],
            // The lawful basis is mandatory at every layer: form, service,
            // schema. A transfer without one is blocked, not warned (16.2).
            'lawful_basis_id' => ['required', 'integer', Rule::exists('transfer_lawful_bases', 'id')],
            'lawful_basis_note' => ['nullable', 'string', 'max:2000'],
            'lawful_basis_note_rich' => ['nullable', 'array', new RichTextRule],
            'volume_records' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
