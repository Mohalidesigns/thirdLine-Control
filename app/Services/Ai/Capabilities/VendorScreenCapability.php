<?php

namespace App\Services\Ai\Capabilities;

use App\Models\Document;
use App\Models\User;
use App\Services\Ai\CapabilityRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * §C.6 #8 — vendor and third-party screening.
 *
 * Summarises a vendor's risk profile from documents already in the platform
 * plus whatever structured detail the caller supplies, and drafts the
 * due-diligence questions a reviewer should be asking.
 *
 * No apply(). "Adverse findings" about a named company is the one output in
 * this phase where being wrong has consequences for a third party, not just
 * for the tenant — a model-generated allegation written automatically into
 * a vendor's risk record, and then read as fact by a procurement committee,
 * is a defamation problem with an audit trail. A person decides what, if
 * anything, that summary becomes.
 *
 * The capability also does not search the web. Every input is supplied by
 * the caller or drawn from the tenant's own indexed documents, so the
 * "adverse findings" it reports are findings present in the tenant's own
 * material — not something the model recalls about a company, which would
 * be unsourced and uncheckable.
 */
class VendorScreenCapability extends Capability
{
    public function key(): string
    {
        return 'vendor.screen';
    }

    public function request(User $user, array $input, ?Model $subject = null): CapabilityRequest
    {
        $vendor = trim((string) ($input['vendor_name'] ?? $subject?->title ?? ''));

        return new CapabilityRequest(
            capabilityKey: $this->key(),
            user: $user,
            variables: [
                'vendor_name' => $vendor,
                'service_provided' => (string) ($input['service'] ?? ''),
                'criticality' => (string) ($input['criticality'] ?? 'not stated'),
                'data_shared' => (string) ($input['data_shared'] ?? 'not stated'),
                'supplied_profile' => (array) ($input['profile'] ?? []),
                'documents' => $this->documents($subject, $input),
            ],
            subject: $subject instanceof Document ? $subject : null,
            retrievalQuery: trim($vendor.' '.(string) ($input['service'] ?? '')),
        );
    }

    /**
     * Document metadata and description only.
     *
     * KnowledgeIndexer already excludes confidential documents from the
     * index, so retrieval will not surface a contract marked confidential;
     * this list applies the same rule to the explicitly-named document, so
     * neither route around it exists.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, array<string, mixed>>
     */
    private function documents(?Model $subject, array $input): array
    {
        $ids = array_filter(array_map('intval', (array) ($input['document_ids'] ?? [])));

        if ($subject instanceof Document) {
            $ids[] = $subject->getKey();
        }

        if ($ids === []) {
            return [];
        }

        return Document::query()
            ->whereKey(array_unique($ids))
            ->where('is_confidential', false)
            ->get(['id', 'reference', 'title', 'description', 'document_type', 'status'])
            ->map(fn (Document $document) => [
                'reference' => $document->reference,
                'title' => $document->title,
                'type' => $document->document_type,
                'status' => $document->status,
                'description' => $document->description,
            ])->all();
    }
}
