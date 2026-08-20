<?php

namespace App\Http\Requests;

use App\Models\AiFeedback;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'was_useful' => ['nullable', 'boolean'],
            'issue_category' => ['nullable', Rule::in(AiFeedback::CATEGORIES)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
