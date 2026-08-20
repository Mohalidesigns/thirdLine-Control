<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The public Speak Up intake form (11.4 + CR).
 *
 * Two submission modes:
 *
 *   - **confidential** — the disclosed route. Technical metadata is
 *     captured (CR); the NDPA notice must be acknowledged before the
 *     submission is accepted, and the client_meta payload is validated
 *     here so nothing unvetted reaches persistence.
 *   - **anonymous** — the genuinely anonymous route (where the tenant
 *     keeps it enabled). No identifying field, no metadata, no client
 *     payload: whatever the browser sends on this path is discarded.
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
            'mode' => ['nullable', 'in:anonymous,confidential'],
            // Legacy flag kept for older cached clients; `mode` wins.
            'anonymous' => ['nullable', 'boolean'],
            'notice_acknowledged' => ['nullable', 'boolean'],
            'client_meta' => ['nullable', 'array'],
            'client_meta.platform' => ['nullable', 'string', 'max:80'],
            'client_meta.screen_resolution' => ['nullable', 'string', 'max:30'],
            'client_meta.color_depth' => ['nullable', 'integer', 'between:0,64'],
            'client_meta.timezone' => ['nullable', 'string', 'max:80'],
            'client_meta.timezone_offset' => ['nullable', 'integer', 'between:-840,840'],
            'client_meta.locale' => ['nullable', 'string', 'max:120'],
            'client_meta.hardware_concurrency' => ['nullable', 'integer', 'between:0,1024'],
            'client_meta.device_memory' => ['nullable', 'numeric', 'between:0,1024'],
            'client_meta.touch_support' => ['nullable', 'boolean'],
        ];
    }
}
