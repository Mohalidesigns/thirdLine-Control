<?php

namespace App\Http\Requests;

use App\Models\Complaint;
use App\Rules\RichTextRule;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        $complaint = $this->route('complaint');

        return $complaint
            ? $this->user()->can('update', $complaint)
            : $this->user()->can('create', Complaint::class);
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(Complaint::CHANNELS)],
            // Receipt may be backdated for a letter, never postdated — the
            // acknowledgement clock runs from this moment.
            'received_at' => ['nullable', 'date', 'before_or_equal:now'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_reference' => ['nullable', 'string', 'max:100'],
            'customer_contact' => ['nullable', 'array'],
            'customer_contact.email' => ['nullable', 'email', 'max:255'],
            'customer_contact.phone' => ['nullable', 'string', 'max:40'],
            'category_id' => ['nullable', 'exists:complaint_categories,id'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:8000'],
            'description_rich' => ['nullable', 'array', new RichTextRule],
            'product' => ['nullable', 'string', 'max:120'],
            'amount_disputed_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', Rule::in(Money::SUPPORTED), 'required_with:amount_disputed_minor'],
            'entity_id' => ['nullable', 'exists:organisation_units,id'],
            'branch' => ['nullable', 'string', 'max:120'],
            'assigned_to' => ['nullable', 'tenant_user'],
        ];
    }

    /**
     * acknowledged_at is written by the server on the acknowledge action and
     * is stripped here so a crafted payload can never pre-stop the clock.
     */
    protected function prepareForValidation(): void
    {
        $this->request->remove('acknowledged_at');
        $this->request->remove('acknowledgement_due_at');
        $this->request->remove('tracking_id');
        $this->request->remove('penalty_exposure_minor');
        $this->request->remove('sla_breached');
    }
}
