<?php

namespace App\Http\Requests;

use App\Models\ControlUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ControlUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorises via ControlUnitPolicy
    }

    public function rules(): array
    {
        $unitId = $this->route('controlUnit')?->id;

        return [
            'code' => [
                'required', 'string', 'max:20', 'alpha_num:ascii',
                Rule::unique('control_units', 'code')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->ignore($unitId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', Rule::in(ControlUnit::DOMAINS)],
            'description' => ['nullable', 'string', 'max:5000'],
            'description_rich' => ['nullable', 'array'],
            'head_user_id' => ['nullable', 'integer', 'tenant_user'],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
