<?php

namespace App\Http\Requests;

use App\Models\Framework;
use App\Models\FrameworkRequirement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FrameworkRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ref_code' => ['required', 'string', 'max:60'],
            'parent_id' => ['nullable', 'exists:framework_requirements,id'],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'guidance' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'requirement_type' => ['required', Rule::in(FrameworkRequirement::TYPES)],
            'is_testable' => ['boolean'],
            'suggested_frequency' => ['nullable', 'string', 'max:30'],
            'suggested_evidence' => ['nullable', 'array'],
            'suggested_evidence.*' => ['string', 'max:255'],
            'verification_status' => ['required', Rule::in(Framework::VERIFICATION_STATUSES)],
            'source_reference' => ['nullable', 'string', 'max:500'],
        ];
    }
}
