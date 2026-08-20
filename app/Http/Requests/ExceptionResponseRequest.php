<?php

namespace App\Http\Requests;

use App\Models\ExceptionResponse;
use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CR1.3: the structured departmental answer. required_response-driven
 * conditional rules live in ExceptionResponseService so the portal and the
 * secure-link channel enforce the same contract.
 */
class ExceptionResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorizes via ExceptionEscalationPolicy::respond
    }

    public function rules(): array
    {
        return [
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
