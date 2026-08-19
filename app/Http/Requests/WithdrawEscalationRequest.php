<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** CR1.4: withdrawal ends an escalation issued in error — reason mandatory. */
class WithdrawEscalationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorizes via ExceptionEscalationPolicy::withdraw
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
