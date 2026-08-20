<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route + policy gate access; rules only here.
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'document_type' => ['required', Rule::in(Document::TYPES)],
            'folder_id' => ['nullable', 'exists:document_folders,id'],
            'owner_id' => ['nullable', 'tenant_user'],
            'review_due_at' => ['nullable', 'date', 'after:today'],
            'is_confidential' => ['boolean'],
            'access_role_ids' => ['required_if:is_confidential,true', 'nullable', 'array'],
            'access_role_ids.*' => ['integer', 'exists:roles,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'supersedes_document_id' => ['nullable', 'exists:documents,id'],
            // 20MB cap — Africa-first budget (R6): keep uploads reasonable.
            'file' => [$this->isMethod('post') ? 'required' : 'nullable', 'file', 'max:20480'],
            'change_summary' => ['nullable', 'string', 'max:500'],
        ];
    }
}
