<?php

namespace App\Http\Requests;

use App\Services\Ai\CapabilityRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage ai') ?? false;
    }

    public function rules(): array
    {
        return [
            'capability_key' => ['required', Rule::in(CapabilityRegistry::keys())],
            'is_enabled' => ['required', 'boolean'],
            'model' => ['nullable', 'string', 'max:60'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'max_tokens' => ['nullable', 'integer', 'min:256', 'max:128000'],
            'monthly_token_budget' => ['nullable', 'integer', 'min:0'],
            'requires_approval_role_id' => ['nullable', 'exists:roles,id'],
            'min_confidence_to_surface' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
