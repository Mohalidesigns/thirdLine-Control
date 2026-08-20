<?php

namespace App\Services;

use App\Models\ChunkedUpload;
use App\Models\Evidence;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Resumable chunked upload (15.2) for evidence on unreliable connections:
 * initiate → append chunks (any order, retried freely) → complete, which
 * assembles and hands the file to EvidenceService::store so a chunked
 * upload is indistinguishable from a direct one — same PII declaration,
 * same retention resolution, same audit trail.
 */
class ChunkedUploadService
{
    /** Uploads park on the evidence disk, outside the public tree. */
    private const TEMP_DIR = 'chunked-uploads';

    public const MAX_CHUNK_BYTES = 2 * 1024 * 1024;

    public const MAX_TOTAL_BYTES = 100 * 1024 * 1024;

    public function __construct(private EvidenceService $evidence) {}

    public function initiate(User $user, array $data): ChunkedUpload
    {
        if ((int) $data['total_size'] > self::MAX_TOTAL_BYTES) {
            throw ValidationException::withMessages(['total_size' => 'Uploads are capped at 100MB.']);
        }

        return ChunkedUpload::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'uuid' => (string) Str::uuid(),
            'file_name' => $data['file_name'],
            'mime_type' => $data['mime_type'] ?? null,
            'total_size' => (int) $data['total_size'],
            'total_chunks' => (int) $data['total_chunks'],
            'status' => 'pending',
            'temp_path' => self::TEMP_DIR.'/'.Str::uuid()->toString(),
            'meta' => $data['meta'] ?? [],
        ]);
    }

    /** Chunks are numbered from 0 and stored individually, so any chunk can be retried. */
    public function appendChunk(ChunkedUpload $upload, int $index, UploadedFile $chunk): ChunkedUpload
    {
        if ($upload->status !== 'pending') {
            throw ValidationException::withMessages(['upload' => 'This upload is no longer accepting chunks.']);
        }

        if ($index < 0 || $index >= $upload->total_chunks) {
            throw ValidationException::withMessages(['chunk' => 'Chunk index out of range.']);
        }

        Storage::disk(EvidenceService::DISK)->putFileAs(
            $upload->temp_path, $chunk, 'chunk-'.$index,
        );

        $received = collect(Storage::disk(EvidenceService::DISK)->files($upload->temp_path))
            ->filter(fn ($f) => str_contains(basename($f), 'chunk-'))
            ->count();

        $upload->update(['chunks_received' => $received]);

        return $upload;
    }

    /** Assemble in order and push through the normal evidence pipeline. */
    public function complete(ChunkedUpload $upload, User $user): Evidence
    {
        if ($upload->status !== 'pending') {
            throw ValidationException::withMessages(['upload' => 'This upload has already been completed or aborted.']);
        }

        if ($upload->chunks_received < $upload->total_chunks) {
            throw ValidationException::withMessages([
                'upload' => "Upload incomplete: {$upload->chunks_received} of {$upload->total_chunks} chunks received.",
            ]);
        }

        $disk = Storage::disk(EvidenceService::DISK);
        $assembledRelative = $upload->temp_path.'/assembled';
        $assembled = $disk->path($assembledRelative);

        $out = fopen($assembled, 'wb');

        for ($i = 0; $i < $upload->total_chunks; $i++) {
            $in = fopen($disk->path($upload->temp_path.'/chunk-'.$i), 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }

        fclose($out);

        $meta = $upload->meta ?? [];

        try {
            $evidence = $this->evidence->store(
                new UploadedFile($assembled, $upload->file_name, $upload->mime_type, test: true),
                (string) ($meta['linked_type'] ?? ''),
                (int) ($meta['linked_id'] ?? 0),
                $meta,
                $user,
            );
        } finally {
            // Assembled copy is stored by EvidenceService; drop the pieces
            // either way — a failed store can restart from initiate.
            $disk->deleteDirectory($upload->temp_path);
        }

        $upload->update(['status' => 'complete']);
        $evidence->auditAction('uploaded_chunked', null, [
            'chunks' => $upload->total_chunks,
            'total_size' => $upload->total_size,
        ]);

        return $evidence;
    }

    public function abort(ChunkedUpload $upload): void
    {
        Storage::disk(EvidenceService::DISK)->deleteDirectory($upload->temp_path);
        $upload->update(['status' => 'aborted']);
    }
}
