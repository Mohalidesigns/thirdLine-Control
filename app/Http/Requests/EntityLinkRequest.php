<?php

namespace App\Http\Requests;

use App\Models\EntityLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EntityLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage linkage');
    }

    public function rules(): array
    {
        $types = array_keys(EntityLink::NODE_TYPES);

        return [
            'source_type' => ['required', Rule::in($types)],
            'source_id' => ['required', 'integer', 'min:1'],
            'target_type' => ['required', Rule::in($types)],
            'target_id' => ['required', 'integer', 'min:1'],
            'relationship' => ['required', Rule::in(EntityLink::RELATIONSHIPS)],
            'strength' => ['nullable', 'integer', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
