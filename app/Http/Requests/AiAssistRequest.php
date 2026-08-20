<?php

namespace App\Http\Requests;

use App\Services\Ai\AiService;
use App\Services\Ai\CapabilityRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invoking a capability. authorize() answers only "may this user use this
 * assistant at all" — the gateway re-checks the capability's domain
 * permission and the subject's own policy, so a request that slips past a
 * stale UI still stops at the pipeline.
 */
class AiAssistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('use ai') ?? false;
    }

    public function rules(): array
    {
        return [
            'capability' => ['required', 'string', Rule::in(CapabilityRegistry::keys())],
            'subject_type' => ['nullable', 'string', 'max:60'],
            'subject_id' => ['nullable', 'integer', 'min:1'],

            // Free-text inputs. Capped because an unbounded paste is a token
            // bill and a context-window failure, not a feature.
            'prompt' => ['nullable', 'string', 'max:8000'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'question' => ['nullable', 'string', 'max:2000'],
            'focus' => ['nullable', 'string', 'max:500'],
            'document_text' => ['nullable', 'string', 'max:120000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'regulator' => ['nullable', 'string', 'max:120'],
            'assertion' => ['nullable', 'string', 'max:1000'],

            'section' => ['nullable', 'string', 'max:200'],
            'audience' => ['nullable', 'string', 'max:200'],
            'period' => ['nullable', 'string', 'max:60'],
            'tone' => ['nullable', 'string', 'max:200'],
            'word_limit' => ['nullable', 'integer', 'min:50', 'max:2000'],
            'figures' => ['nullable', 'array', 'max:50'],

            'vendor_name' => ['nullable', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:500'],
            'criticality' => ['nullable', 'string', 'max:60'],
            'data_shared' => ['nullable', 'string', 'max:500'],
            'profile' => ['nullable', 'array', 'max:40'],
            'document_ids' => ['nullable', 'array', 'max:20'],
            'document_ids.*' => ['integer', 'exists:documents,id'],

            'conversation' => ['nullable', 'array', 'max:20'],
            'conversation.*.role' => ['required_with:conversation', 'string', Rule::in(['user', 'assistant'])],
            'conversation.*.content' => ['required_with:conversation', 'string', 'max:4000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $capability = (string) $this->input('capability');

            if ($capability === '' || ! $this->user()) {
                return;
            }

            if (! app(AiService::class)->isAvailable($capability, $this->user())) {
                $validator->errors()->add(
                    'capability',
                    'This assistant is not enabled for your organisation, or you do not have permission to use it.',
                );
            }
        });
    }
}
