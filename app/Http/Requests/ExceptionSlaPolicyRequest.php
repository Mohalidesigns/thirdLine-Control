<?php

namespace App\Http\Requests;

use App\Models\ControlException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** CR-01 (R1): the SLA table admin — the only home a day count may have. */
class ExceptionSlaPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated on 'configure exception-routing'
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'severity' => ['required', Rule::in(ControlException::SEVERITIES)],
            'acknowledge_within_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'respond_within_days' => ['required', 'integer', 'min:1', 'max:120'],
            'reminder_offsets' => ['nullable', 'array'],
            'reminder_offsets.*' => ['integer', 'min:-60', 'max:60'],
            'max_reminders' => ['required', 'integer', 'min:0', 'max:50'],
            'escalate_after_days' => ['required', 'integer', 'min:0', 'max:120'],
            'auto_escalate_to_matrix' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
