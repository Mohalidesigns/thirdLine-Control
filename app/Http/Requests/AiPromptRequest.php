<?php

namespace App\Http\Requests;

use App\Services\Ai\CapabilityRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Publishing a prompt version. Never an edit in place — PromptRegistry
 * always writes a new version, because ai_interactions references the
 * version that ran and rewriting one would make an audited interaction
 * unreconstructible (R3).
 */
class AiPromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage ai') ?? false;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', Rule::in(CapabilityRegistry::keys())],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'system_prompt' => ['required', 'string', 'max:60000'],
            'user_template' => ['required', 'string', 'max:60000'],
            'model_hint' => ['nullable', 'string', 'max:60'],
            'max_tokens' => ['nullable', 'integer', 'min:256', 'max:128000'],
            'changelog' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'changelog.required' => 'Describe what changed in this version — the history is unreadable without it.',
        ];
    }
}
