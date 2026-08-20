<?php

namespace App\Http\Controllers;

use App\Models\Evidence;
use App\Models\RetentionPolicy;
use App\Services\EvidenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceController extends Controller
{
    public function __construct(private EvidenceService $evidenceService) {}

    /**
     * Upload with the mandatory PII declaration (FR-9.2): the request is
     * rejected unless contains_personal_data is answered, and categories
     * are required when the answer is yes.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // DEF-010/DEF-011: extension allowlist and a non-empty body —
            // a zero-byte file satisfies a mandatory-evidence gate while
            // proving nothing.
            'file' => [
                'required', 'file', 'max:20480',
                'extensions:'.implode(',', EvidenceService::ALLOWED_EXTENSIONS),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value instanceof UploadedFile && $value->getSize() === 0) {
                        $fail('An empty file cannot be stored as evidence.');
                    }
                },
            ],
            'linked_type' => ['required', Rule::in(array_keys(EvidenceService::LINKABLE))],
            'linked_id' => ['required', 'integer'],
            'contains_personal_data' => ['required', 'boolean'],
            'personal_data_categories' => ['required_if:contains_personal_data,true', 'nullable', 'array'],
            'personal_data_categories.*' => [Rule::in(Evidence::PII_CATEGORIES)],
            'classification' => ['nullable', Rule::in(['Public', 'Internal', 'Confidential', 'Restricted'])],
        ]);

        // DEF-009: attaching evidence is an authoring act — gate it the way
        // download() already gates reading. The required permission follows
        // the record the evidence is attached to.
        abort_unless(
            $request->user()->hasAnyPermission(EvidenceService::ATTACH_PERMISSIONS[$validated['linked_type']]),
            403,
            'You do not hold a permission that allows attaching evidence to this record type.'
        );

        $this->evidenceService->store(
            $request->file('file'),
            $validated['linked_type'],
            (int) $validated['linked_id'],
            $validated,
            $request->user(),
        );

        return back()->with('success', 'Evidence uploaded and classified.');
    }

    public function download(Evidence $evidence): StreamedResponse
    {
        abort_unless(auth()->user()->hasAnyRole([
            'System Administrator', 'Control Function Head', 'Control Officer',
        ]) || $evidence->uploaded_by === auth()->id(), 403);

        return $this->evidenceService->download($evidence);
    }

    public function legalHold(Request $request, Evidence $evidence): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['System Administrator', 'Control Function Head']), 403);

        $validated = $request->validate([
            'hold' => ['required', 'boolean'],
            'reason' => ['required_if:hold,true', 'nullable', 'string', 'max:1000'],
        ]);

        $this->evidenceService->setLegalHold($evidence, $validated['hold'], $validated['reason'] ?? null, $request->user());

        return back()->with('success', $validated['hold'] ? 'Legal hold applied — disposal suspended.' : 'Legal hold lifted.');
    }

    /** Disposal queue for the control function (FR-9.8). */
    public function disposalQueue(): Response
    {
        return Inertia::render('Admin/EvidenceDisposal', [
            'queued' => Evidence::query()
                ->whereNull('disposed_at')
                ->whereNotNull('disposal_requested_at')
                ->with(['retentionPolicy:id,name,disposal_action', 'uploader:id,name'])
                ->orderBy('retention_expiry_date')
                ->paginate(20),
            'policies' => RetentionPolicy::orderBy('name')->get(),
        ]);
    }

    public function approveDisposal(Request $request, Evidence $evidence): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['System Administrator', 'Control Function Head']), 403);

        if (! $evidence->disposal_requested_by) {
            $this->evidenceService->approveDisposalFirst($evidence, $request->user());

            return back()->with('success', 'First disposal approval recorded — a second approver must sign off.');
        }

        $this->evidenceService->approveDisposalFinal($evidence, $request->user());

        return back()->with('success', 'Evidence disposed of; the disposal log is on the audit trail.');
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['System Administrator', 'Control Function Head']), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'evidence_class' => ['required', 'string', 'max:255'],
            'retention_months' => ['required', 'integer', 'between:1,600'],
            'disposal_action' => ['required', Rule::in(['Delete', 'Anonymise'])],
            'requires_dual_approval' => ['boolean'],
            'legal_basis_note' => ['nullable', 'string'],
            'is_default' => ['boolean'],
        ]);

        if ($validated['is_default'] ?? false) {
            RetentionPolicy::query()->update(['is_default' => false]);
        }

        RetentionPolicy::create($validated);

        return back()->with('success', 'Retention policy saved.');
    }
}
