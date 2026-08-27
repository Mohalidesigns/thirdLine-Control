<?php

namespace App\Http\Requests;

use App\Models\Initiative;
use App\Models\InitiativeMilestone;
use App\Rules\RichTextRule;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage initiatives');
    }

    public function rules(): array
    {
        return [
            'objective_id' => ['nullable', 'exists:objectives,id'],
            'entity_id' => ['nullable', 'exists:entities,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:4000'],
            'description_rich' => ['nullable', 'array', new RichTextRule],
            'owner_id' => ['nullable', 'tenant_user'],
            'status' => ['required', Rule::in(Initiative::STATUSES)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // Money crosses the wire as integer minor units (R7) — the form
            // multiplies up, the server never sees a float.
            'budget_minor' => ['nullable', 'integer', 'min:0'],
            'spend_minor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['required', Rule::in(Money::SUPPORTED)],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'progress_mode' => ['required', Rule::in(Initiative::PROGRESS_MODES)],
            'weight' => ['required', 'integer', 'min:1', 'max:1000'],

            'milestones' => ['nullable', 'array', 'max:40'],
            'milestones.*.title' => ['required', 'string', 'max:255'],
            'milestones.*.due_date' => ['nullable', 'date'],
            'milestones.*.status' => ['nullable', Rule::in(InitiativeMilestone::STATUSES)],
            'milestones.*.weight' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
