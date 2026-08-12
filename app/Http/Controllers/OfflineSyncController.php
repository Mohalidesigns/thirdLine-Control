<?php

namespace App\Http\Controllers;

use App\Services\MyWorkService;
use App\Services\OfflineSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Offline PWA endpoints (15.1): the bootstrap payload the device caches
 * in IndexedDB, and the outbox replay. Approvals and SoD transitions are
 * rejected inside OfflineSyncService, not here — the whitelist is
 * architectural, not a controller nicety.
 */
class OfflineSyncController extends Controller
{
    public function __construct(
        private MyWorkService $myWork,
        private OfflineSyncService $sync,
    ) {}

    /** Everything a device needs cached to be useful offline. */
    public function bootstrap(Request $request): JsonResponse
    {
        return response()->json([
            'generated_at' => now()->toIso8601String(),
            ...$this->myWork->feed($request->user()),
        ]);
    }

    /** Replay the device's outbox, in order, idempotently. */
    public function outbox(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'actions' => ['required', 'array', 'max:100'],
            'actions.*.client_id' => ['required', 'uuid'],
            'actions.*.type' => ['required', 'string', 'max:60'],
            'actions.*.payload' => ['required', 'array'],
            'actions.*.acted_at' => ['nullable', 'date'],
        ]);

        return response()->json([
            'outcomes' => $this->sync->process($request->user(), $validated['actions']),
            'synced_at' => now()->toIso8601String(),
        ]);
    }
}
