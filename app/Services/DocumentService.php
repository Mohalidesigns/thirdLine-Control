<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document management (9.6). Documents are governing artefacts — distinct
 * from Evidence, which is proof. Files live on a non-public disk and are
 * only streamed after authorisation; every download is logged.
 */
class DocumentService
{
    private const DISK = 'local';

    public const TRANSITIONS = [
        'Draft' => ['Under Review'],
        'Under Review' => ['Approved', 'Draft'],
        'Approved' => ['Published', 'Draft'],
        'Published' => ['Superseded', 'Archived'],
        'Superseded' => ['Archived'],
        'Archived' => [],
    ];

    // ── Folders ──────────────────────────────────────────────────────────

    public function createFolder(array $data, User $user): DocumentFolder
    {
        $folder = DocumentFolder::create($data);
        $folder->refreshPath();

        return $folder;
    }

    /** Nested folder tree with per-folder document counts — one query each. */
    public function folderTree(): array
    {
        $folders = DocumentFolder::query()
            ->withCount('documents')
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $byParent = $folders->groupBy('parent_id');

        $build = function ($parentId) use (&$build, $byParent): array {
            return ($byParent->get($parentId ?? '') ?? $byParent->get($parentId) ?? collect())
                ->map(fn ($folder) => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'documents_count' => $folder->documents_count,
                    'children' => $build($folder->id),
                ])->values()->all();
        };

        return $build(null);
    }

    // ── Documents ────────────────────────────────────────────────────────

    public function create(array $data, ?UploadedFile $file, User $user): Document
    {
        return DB::transaction(function () use ($data, $file, $user) {
            $document = Document::create([
                ...$data,
                'reference' => Document::nextReference('DOC'),
                'status' => 'Draft',
                'version_no' => 1,
                'owner_id' => $data['owner_id'] ?? $user->id,
                ...$this->fileAttributes($file),
            ]);

            $document->versions()->create([
                'version_no' => 1,
                'file_path' => $document->file_path,
                'file_hash' => $document->file_hash,
                'change_summary' => 'Initial version',
                'created_by' => $user->id,
            ]);

            return $document;
        });
    }

    /**
     * A content change bumps the version and returns the document to Draft
     * for a fresh maker-checker pass. Metadata-only edits keep the version.
     */
    public function update(Document $document, array $data, ?UploadedFile $file, User $user, ?string $changeSummary = null): Document
    {
        return DB::transaction(function () use ($document, $data, $file, $user, $changeSummary) {
            if ($file) {
                $document->fill([
                    ...$this->fileAttributes($file),
                    'version_no' => $document->version_no + 1,
                    'status' => 'Draft',
                    'approver_id' => null,
                    'approved_at' => null,
                    'published_at' => null,
                ]);

                $document->versions()->create([
                    'version_no' => $document->version_no,
                    'file_path' => $document->file_path,
                    'file_hash' => $document->file_hash,
                    'change_summary' => $changeSummary ?? 'Content updated',
                    'created_by' => $user->id,
                ]);
            }

            $document->fill($data)->save();

            return $document;
        });
    }

    public function submitForReview(Document $document): Document
    {
        $this->transition($document, 'Under Review');

        return $document;
    }

    /** Maker-checker: the approver can never be the document owner (9.6). */
    public function approve(Document $document, User $approver): Document
    {
        if ($document->owner_id === $approver->id) {
            throw ValidationException::withMessages([
                'approval' => 'A document cannot be approved by its owner.',
            ]);
        }

        $this->transition($document, 'Approved', [
            'approver_id' => $approver->id,
            'approved_at' => now(),
        ]);

        $document->versions()
            ->where('version_no', $document->version_no)
            ->update(['approved_by' => $approver->id, 'approved_at' => now()]);

        $document->auditAction('approved', null, ['by' => $approver->id]);

        return $document;
    }

    public function reject(Document $document, User $approver, ?string $reason = null): Document
    {
        $this->transition($document, 'Draft');
        $document->auditAction('rejected', null, ['by' => $approver->id, 'reason' => $reason]);

        return $document;
    }

    /** Publishing supersedes the named predecessor, keeping the chain (9.6). */
    public function publish(Document $document, User $user, array $options = []): Document
    {
        return DB::transaction(function () use ($document, $user, $options) {
            $this->transition($document, 'Published', [
                'published_at' => now(),
                'review_due_at' => $options['review_due_at']
                    ?? $document->review_due_at
                    ?? now()->addYear()->toDateString(),
            ]);

            if ($document->supersedes_document_id) {
                $predecessor = Document::find($document->supersedes_document_id);

                if ($predecessor && $predecessor->status === 'Published') {
                    $predecessor->update(['status' => 'Superseded']);
                    $predecessor->auditAction('superseded', null, ['by_document' => $document->id]);
                }
            }

            $document->auditAction('published', null, ['by' => $user->id]);

            return $document;
        });
    }

    /** Streamed download with logging — confidentiality enforced in the policy. */
    public function download(Document $document, User $user): StreamedResponse
    {
        if (! $document->file_path || ! Storage::disk(self::DISK)->exists($document->file_path)) {
            throw ValidationException::withMessages(['file' => 'The document has no stored file.']);
        }

        $document->increment('download_count');
        $document->auditAction('downloaded', null, ['by' => $user->id]);

        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);

        return Storage::disk(self::DISK)->download(
            $document->file_path,
            str($document->title)->slug().($extension ? ".{$extension}" : ''),
        );
    }

    public function transition(Document $document, string $to, array $extra = []): void
    {
        $allowed = self::TRANSITIONS[$document->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "A document cannot move from {$document->status} to {$to}.",
            ]);
        }

        $document->update(['status' => $to, ...$extra]);
    }

    private function fileAttributes(?UploadedFile $file): array
    {
        if (! $file) {
            return [];
        }

        return [
            'file_path' => $file->store('documents/'.now()->format('Y/m'), self::DISK),
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ];
    }
}
