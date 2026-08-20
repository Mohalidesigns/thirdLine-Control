<?php

namespace App\Services;

use App\Models\ControlException;
use App\Models\Evidence;
use App\Models\ExceptionEscalation;
use App\Models\Finding;
use App\Models\ObligationInstance;
use App\Models\RetentionPolicy;
use App\Models\TestInstance;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceService
{
    /** Evidence lives on a non-public disk and is only ever streamed after authorisation (FR-9.5/9.6). */
    public const DISK = 'local';

    public const LINKABLE = [
        'test_instance' => TestInstance::class,
        'exception' => ControlException::class,
        'finding' => Finding::class,
        // Phase 8: the proof that a regulatory filing was actually made.
        'obligation_instance' => ObligationInstance::class,
        // CR-01: departmental response attachments are evidence rows with
        // linked_type/linked_id — never a file column on the response.
        'exception-escalation' => ExceptionEscalation::class,
    ];

    /**
     * Store an upload. The PII declaration is mandatory — the controller
     * blocks the request before this point if it is missing (FR-9.2).
     */
    public function store(UploadedFile $file, string $linkedType, int $linkedId, array $meta, User $uploader): Evidence
    {
        $modelClass = self::LINKABLE[$linkedType] ?? null;

        if (! $modelClass || ! $modelClass::query()->whereKey($linkedId)->exists()) {
            throw ValidationException::withMessages(['linked' => 'Unknown record to attach evidence to.']);
        }

        $policy = $this->resolvePolicy($meta['contains_personal_data'] ?? false);

        // The write is blocked, not warned about, if the evidence disk sits
        // outside the tenant's declared residency country (16.2, R5).
        app(ResidencyGuard::class)->assertStorageAllowed(self::DISK, $uploader->tenant);

        $path = $file->store('evidence/'.now()->format('Y/m'), self::DISK);

        return Evidence::create([
            'linked_type' => $modelClass,
            'linked_id' => $linkedId,
            'file_name' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'contains_personal_data' => (bool) ($meta['contains_personal_data'] ?? false),
            'personal_data_categories' => $meta['personal_data_categories'] ?? null,
            'classification' => $meta['classification'] ?? 'Internal',
            'retention_policy_id' => $policy?->id,
            'retention_expiry_date' => $policy ? now()->addMonths($policy->retention_months)->toDateString() : null,
            'uploaded_by' => $uploader->id,
            'uploaded_at' => now(),
        ]);
    }

    /** Stream a download with an access-log entry (FR-9.6). */
    public function download(Evidence $evidence): StreamedResponse
    {
        if ($evidence->disposed_at) {
            abort(410, 'This evidence has been disposed of under the retention policy.');
        }

        $evidence->logAccess('Download');

        return Storage::disk(self::DISK)->download($evidence->storage_path, $evidence->file_name);
    }

    /**
     * First half of dual approval: flag expired evidence for disposal.
     * Run by the daily command; a human never queues their own approval.
     */
    public function queueExpiredForDisposal(): int
    {
        $queued = 0;

        Evidence::withoutGlobalScopes()
            ->whereNull('disposed_at')
            ->whereNull('disposal_requested_at')
            ->where('legal_hold', false)
            ->whereNotNull('retention_expiry_date')
            ->whereDate('retention_expiry_date', '<', now())
            ->each(function (Evidence $evidence) use (&$queued) {
                $evidence->update(['disposal_requested_at' => now()]);
                $evidence->auditAction('disposal_queued', null, [
                    'retention_expiry_date' => $evidence->retention_expiry_date?->toDateString(),
                ]);
                $queued++;
            });

        return $queued;
    }

    /** First human approval. */
    public function approveDisposalFirst(Evidence $evidence, User $approver): Evidence
    {
        $this->assertDisposable($evidence);

        if ($evidence->disposal_requested_by) {
            throw ValidationException::withMessages(['disposal' => 'First approval has already been recorded.']);
        }

        $evidence->update(['disposal_requested_by' => $approver->id]);
        $evidence->auditAction('disposal_first_approval', null, ['by' => $approver->id]);

        return $evidence;
    }

    /**
     * Second, different approver executes the disposal (FR-9.8). Writes an
     * immutable disposal record before the file is removed.
     */
    public function approveDisposalFinal(Evidence $evidence, User $approver): Evidence
    {
        $this->assertDisposable($evidence);

        if (! $evidence->disposal_requested_by) {
            throw ValidationException::withMessages(['disposal' => 'Disposal requires a first approval before final sign-off.']);
        }

        if ($evidence->disposal_requested_by === $approver->id) {
            throw ValidationException::withMessages(['disposal' => 'The same user cannot give both disposal approvals.']);
        }

        $evidence->auditAction('disposed', $evidence->only([
            'file_name', 'checksum', 'retention_policy_id', 'retention_expiry_date',
        ]), [
            'first_approval' => $evidence->disposal_requested_by,
            'final_approval' => $approver->id,
            'action' => $evidence->retentionPolicy?->disposal_action ?? 'Delete',
        ]);

        Storage::disk(self::DISK)->delete($evidence->storage_path);

        $evidence->update([
            'disposal_approved_by' => $approver->id,
            'disposed_at' => now(),
        ]);

        return $evidence;
    }

    public function setLegalHold(Evidence $evidence, bool $hold, ?string $reason, User $actor): Evidence
    {
        $evidence->update([
            'legal_hold' => $hold,
            'legal_hold_reason' => $hold ? $reason : null,
            // Lifting a hold restarts the disposal pipeline from scratch.
            'disposal_requested_at' => $hold ? null : $evidence->disposal_requested_at,
            'disposal_requested_by' => $hold ? null : $evidence->disposal_requested_by,
        ]);

        $evidence->auditAction($hold ? 'legal_hold_applied' : 'legal_hold_lifted', null, ['reason' => $reason]);

        return $evidence;
    }

    private function assertDisposable(Evidence $evidence): void
    {
        if ($evidence->legal_hold) {
            throw ValidationException::withMessages(['disposal' => 'Evidence under legal hold cannot be disposed of.']);
        }

        if ($evidence->disposed_at) {
            throw ValidationException::withMessages(['disposal' => 'This evidence has already been disposed of.']);
        }
    }

    private function resolvePolicy(bool $containsPersonalData): ?RetentionPolicy
    {
        return RetentionPolicy::query()
            ->when($containsPersonalData, fn ($q) => $q->where('evidence_class', 'Customer Personal Data'))
            ->when(! $containsPersonalData, fn ($q) => $q->where('is_default', true))
            ->first()
            ?? RetentionPolicy::query()->where('is_default', true)->first();
    }
}
