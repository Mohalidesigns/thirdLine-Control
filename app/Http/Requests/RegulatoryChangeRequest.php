<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegulatoryChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'regulator_id' => ['required', 'exists:regulators,id'],
            'title' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:200'],
            'published_at' => ['nullable', 'date'],
            'effective_at' => ['nullable', 'date'],
            'document_url' => ['nullable', 'url', 'max:500'],
            'summary' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
