<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DistributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('distribute controls');
    }

    public function rules(): array
    {
        return [
            'entity_ids' => ['required', 'array', 'min:1'],
            'entity_ids.*' => ['integer', 'exists:organisation_units,id'],
            'due_at' => ['nullable', 'date', 'after_or_equal:today'],
            'owner_ids' => ['nullable', 'array'],
            'owner_ids.*' => ['integer', 'tenant_user'],
            'tasks' => ['nullable', 'array'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string'],
            'tasks.*.due_at' => ['nullable', 'date'],
            'tasks.*.evidence_required' => ['boolean'],
        ];
    }
}
