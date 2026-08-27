<?php

namespace App\Http\Requests;

use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A submission is only real if it can be proved later: the regulator's
 * reference number is mandatory here, and ObligationService additionally
 * refuses the transition without at least one evidence item.
 */
class ObligationSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'submission_reference' => ['required', 'string', 'max:120'],
            'acknowledgement_ref' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'notes_rich' => ['nullable', 'array', new RichTextRule],
        ];
    }
}
