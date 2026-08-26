<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ArchiveInvestigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('archive', $this->route('investigation'));
    }

    public function rules(): array
    {
        return [
            // Archived cases leave every list, count and KPI. That needs a
            // reason on the record, not in someone's memory.
            'archive_reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
