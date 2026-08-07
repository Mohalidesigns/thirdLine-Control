<?php

namespace App\Http\Requests;

use App\Models\NotificationPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Users manage only their own preferences; the controller writes
        // against $request->user() exclusively.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array'],
            'preferences.*.event_key' => ['required', 'string', 'max:100'],
            'preferences.*.channel' => ['required', Rule::in(NotificationPreference::CHANNELS)],
            'preferences.*.is_enabled' => ['required', 'boolean'],
            'preferences.*.digest_frequency' => ['required', Rule::in(NotificationPreference::DIGEST_FREQUENCIES)],
            'preferences.*.quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'preferences.*.quiet_hours_end' => ['nullable', 'date_format:H:i', 'required_with:preferences.*.quiet_hours_start'],
        ];
    }
}
