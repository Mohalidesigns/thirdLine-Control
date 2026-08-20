<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CR2A.3 — attach controls to a control entity, singly or in bulk.
 */
class AttachControlsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorises via ControlEntityPolicy::attach
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'attachments' => ['required', 'array', 'min:1', 'max:200'],
            'attachments.*.control_id' => ['required', 'integer', 'distinct',
                Rule::exists('controls', 'id')->where('tenant_id', $tenantId)],
            'attachments.*.is_key' => ['boolean'],
        ];
    }
}
