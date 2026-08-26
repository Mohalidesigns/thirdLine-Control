<?php

namespace App\Http\Controllers;

use App\Models\Evidence;
use App\Models\Investigation;
use App\Services\EvidenceService;
use App\Services\InvestigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Investigation exhibits (CR-04 §B.2, §C.7).
 *
 * There is no investigation_evidence table. Exhibits go into the shared
 * repository, which means they inherit its extension allowlist, its
 * retention policy, its legal hold and its access log — every view and
 * download of an exhibit is already recorded with an IP, which is a far
 * better answer than the source module's download counter.
 *
 * What this controller adds is chain of custody: who COLLECTED the exhibit
 * and where it came from, which is the question a disciplinary panel asks
 * and the uploader column cannot answer.
 */
class InvestigationEvidenceController extends Controller
{
    public function __construct(
        private EvidenceService $evidence,
        private InvestigationService $investigations,
    ) {}

    public function store(Request $request, Investigation $investigation): RedirectResponse
    {
        $this->authorize('update', $investigation);

        $validated = $request->validate([
            'file' => [
                'required', 'file', 'max:20480',
                'extensions:'.implode(',', EvidenceService::ALLOWED_EXTENSIONS),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value instanceof UploadedFile && $value->getSize() === 0) {
                        $fail('An empty file cannot be stored as evidence.');
                    }
                },
            ],
            'contains_personal_data' => ['required', 'boolean'],
            'personal_data_categories' => ['required_if:contains_personal_data,true', 'nullable', 'array'],
            'personal_data_categories.*' => [Rule::in(Evidence::PII_CATEGORIES)],
            'classification' => ['nullable', Rule::in(['Public', 'Internal', 'Confidential', 'Restricted'])],
            // Chain of custody.
            'collected_by' => ['nullable', 'tenant_user'],
            'collected_on' => ['nullable', 'date'],
            'collection_source' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $evidence = $this->evidence->store(
            $request->file('file'),
            'investigation',
            $investigation->id,
            $validated,
            $request->user(),
        );

        $this->investigations->recordEvidenceCollection($investigation, $evidence, [
            'collected_by' => $validated['collected_by'] ?? $request->user()->id,
            'collected_on' => $validated['collected_on'] ?? now()->toDateString(),
            'collection_source' => $validated['collection_source'] ?? null,
            'description' => $validated['description'] ?? null,
        ], $request->user());

        return back()->with('success', 'Exhibit filed and recorded on the chronology.');
    }
}
