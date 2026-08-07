<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'inherent_likelihood' => ['required', 'integer', 'between:1,5'],
            'inherent_impact' => ['required', 'integer', 'between:1,5'],
            'risk_owner_id' => ['nullable', 'exists:users,id'],
        ];
    }
}
