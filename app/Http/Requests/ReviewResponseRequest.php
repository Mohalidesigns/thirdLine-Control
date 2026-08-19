<?php

namespace App\Http\Requests;

use App\Models\ExceptionAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** CR1.4: accept or reject, with a reason on rejection. */
class ReviewResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorizes via the escalation/response policies
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'review_note' => ['nullable', 'string', 'max:5000'],
            'rejection_reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:5000'],
            // Optional shaping of the committed action when accepting.
            'action_title' => ['nullable', 'string', 'max:255'],
            'action_type' => ['nullable', Rule::in(ExceptionAction::ACTION_TYPES)],
            'action_target_date' => ['nullable', 'date', 'after:today'],
        ];
    }
}
