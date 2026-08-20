<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage ai') ?? false;
    }

    public function rules(): array
    {
        return [
            'token_budget' => ['required', 'integer', 'min:0'],
            'cost_budget_minor' => ['required', 'integer', 'min:0'],
            'hard_stop' => ['required', 'boolean'],
            'alert_thresholds' => ['nullable', 'array', 'max:5'],
            'alert_thresholds.*' => ['integer', 'min:1', 'max:100'],
        ];
    }
}
