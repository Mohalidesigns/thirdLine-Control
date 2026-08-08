<?php

namespace App\Http\Requests;

use App\Models\AiFeedback;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Accept, edit or reject a draft.
 *
 * A rejection reason is required, not optional. The rejection reasons are
 * the honest quality metric for a capability, and a reject button that
 * takes no reason produces a governance page full of a number with no
 * explanation behind it.
 */
class AiDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route model is authorised through AiInteractionPolicy in the
        // controller; this only screens the shape of the request.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'edits' => ['nullable', 'array'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'min:5', 'max:2000'],
            'category' => ['nullable', Rule::in(AiFeedback::CATEGORIES)],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required_if' => 'Please say why you are rejecting this suggestion — it is what improves the next version.',
            'reason.min' => 'Please give a little more detail than that.',
        ];
    }
}
