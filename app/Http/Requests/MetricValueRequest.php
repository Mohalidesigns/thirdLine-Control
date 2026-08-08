<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MetricValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('capture', $this->route('metric'));
    }

    public function rules(): array
    {
        return [
            'value' => ['required', 'numeric'],
            'target' => ['nullable', 'numeric'],
            'period_label' => ['nullable', 'string', 'max:40'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'supporting_evidence_id' => ['nullable', 'exists:evidence,id'],
        ];
    }
}
