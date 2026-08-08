<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The public whistleblowing intake form (11.4).
 *
 * Deliberately minimal: it accepts no identifying field at all, so there is
 * nothing for a well-meaning change to start persisting later. Whether the
 * reporter is authenticated makes no difference to what is stored.
 */
class WhistleblowingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:8000'],
            'concern_type' => ['nullable', 'string', 'max:60'],
            'entity_hint' => ['nullable', 'string', 'max:120'],
            'anonymous' => ['nullable', 'boolean'],
        ];
    }
}
