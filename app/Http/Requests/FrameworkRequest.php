<?php

namespace App\Http\Requests;

use App\Models\Framework;
use App\Rules\RichTextRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FrameworkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route + policy gate access; rules only here.
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'issuing_body' => ['nullable', 'string', 'max:255'],
            'jurisdiction' => ['required', 'string', 'max:8'],
            'version' => ['required', 'string', 'max:30'],
            'category' => ['required', Rule::in(Framework::CATEGORIES)],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_certifiable' => ['boolean'],
            'source_url' => ['nullable', 'url', 'max:500'],
            'verification_status' => ['required', Rule::in(Framework::VERIFICATION_STATUSES)],
            'description' => ['nullable', 'string'],
            'description_rich' => ['nullable', 'array', new RichTextRule],
            'is_active' => ['boolean'],
        ];
    }
}
