<?php

namespace App\Http\Requests;

use App\Models\ExceptionAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** CR1.5: the department progresses, completes, or the control function validates. */
class ExceptionActionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorizes via ExceptionActionPolicy
    }

    public function rules(): array
    {
        return [
            'progress_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'progress_note' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'validation_status' => ['nullable', Rule::in(ExceptionAction::VALIDATION_STATUSES)],
            'validation_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
