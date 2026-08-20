<?php

namespace App\Http\Requests;

use App\Models\ExceptionResponse;
use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** CR1.3: the secure-link answer from a respondent outside the system. */
class PublicExceptionResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the token IS the authorization; the controller validates it
    }

    public function rules(): array
    {
        return [
            'responder_name' => ['required', 'string', 'max:255'],
            'responder_email' => ['required', 'email', 'max:255'],
            'position' => ['required', Rule::in(ExceptionResponse::POSITIONS)],
            'management_comment' => ['required', 'string', 'max:10000'],
            'management_comment_rich' => ['nullable', 'array', new RichTextRule],
            'root_cause' => ['nullable', 'string', 'max:10000'],
            'root_cause_rich' => ['nullable', 'array', new RichTextRule],
            'root_cause_category' => ['nullable', Rule::in(ExceptionResponse::ROOT_CAUSE_CATEGORIES)],
            'agreed_action_plan' => ['nullable', 'string', 'max:10000'],
            'agreed_action_plan_rich' => ['nullable', 'array', new RichTextRule],
            'proposed_target_date' => ['nullable', 'date', 'after:today'],
        ];
    }
}
