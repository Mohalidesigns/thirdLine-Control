<?php

namespace App\Http\Controllers;

use App\Models\ChunkedUpload;
use App\Services\ChunkedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resumable chunked uploads (15.2). Scoped to the uploader: a device may
 * only touch uploads it initiated.
 */
class ChunkedUploadController extends Controller
{
    public function __construct(private ChunkedUploadService $uploads) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file_name' => ['required', 'string', 'max:255'],
            'mime_type' => ['nullable', 'string', 'max:120'],
            'total_size' => ['required', 'integer', 'min:1'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:200'],
            'meta' => ['required', 'array'],
            'meta.linked_type' => ['required', 'string'],
            'meta.linked_id' => ['required', 'integer'],
            'meta.contains_personal_data' => ['required', 'boolean'],
            'meta.personal_data_categories' => ['nullable', 'string'],
            'meta.classification' => ['nullable', 'string'],
        ]);

        $upload = $this->uploads->initiate($request->user(), $validated);

        return response()->json([
            'uuid' => $upload->uuid,
            'chunk_size' => ChunkedUploadService::MAX_CHUNK_BYTES,
        ], 201);
    }

    public function appendChunk(Request $request, string $uuid): JsonResponse
    {
        $upload = $this->ownUpload($request, $uuid);

        $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file', 'max:'.(ChunkedUploadService::MAX_CHUNK_BYTES / 1024)],
        ]);

        $upload = $this->uploads->appendChunk($upload, (int) $request->input('index'), $request->file('chunk'));

        return response()->json([
            'chunks_received' => $upload->chunks_received,
            'total_chunks' => $upload->total_chunks,
        ]);
    }

    public function complete(Request $request, string $uuid): JsonResponse
    {
        $evidence = $this->uploads->complete($this->ownUpload($request, $uuid), $request->user());

        return response()->json(['evidence_id' => $evidence->id], 201);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->uploads->abort($this->ownUpload($request, $uuid));

        return response()->json(['aborted' => true]);
    }

    private function ownUpload(Request $request, string $uuid): ChunkedUpload
    {
        return ChunkedUpload::query()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
