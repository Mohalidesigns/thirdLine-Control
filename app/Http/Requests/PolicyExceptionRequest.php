<?php

namespace App\Http\Requests;

use App\Models\PolicyException;
use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;

class PolicyExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PolicyException::class);
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['nullable', 'exists:organisation_units,id'],
            'justification' => ['required', 'string', 'max:4000'],
            'justification_rich' => ['nullable', 'array', new RichTextRule],
            'risk_assessment' => ['nullable', 'string', 'max:4000'],
            'compensating_measures' => ['nullable', 'string', 'max:4000'],
            'compensating_measures_rich' => ['nullable', 'array', new RichTextRule],
            'requested_from' => ['required', 'date'],
            // A waiver is time-boxed by definition: an open-ended deviation
            // is a policy change, not an exception.
            'requested_to' => ['required', 'date', 'after:requested_from'],
            'review_at' => ['nullable', 'date', 'after_or_equal:requested_from'],
        ];
    }
}
