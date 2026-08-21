<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Per-request activity state (CR3). Two jobs:
 *
 *  1. batchId() — one UUID per request, stamped on every audit row the
 *     request produces, so a logical operation that writes several rows
 *     (a workflow transition plus its CRUD diffs) reads as one batch.
 *  2. markLogged()/wasLogged() — the dedup flag: once any domain, CRUD or
 *     auth write has recorded this request, the LogRequestActivity
 *     fallback stands down instead of writing a second, vaguer row.
 *
 * Registered as a scoped singleton so state never leaks across requests.
 */
class ActivityContext
{
    private ?string $batchId = null;

    private bool $logged = false;

    public function batchId(): string
    {
        return $this->batchId ??= (string) Str::uuid();
    }

    public function markLogged(): void
    {
        $this->logged = true;
    }

    public function wasLogged(): bool
    {
        return $this->logged;
    }

    /**
     * Called by LogRequestActivity as the request enters the stack, so
     * writes made outside any request (console, a previous test-case
     * setUp) can never suppress this request's fallback row.
     */
    public function reset(): void
    {
        $this->batchId = null;
        $this->logged = false;
    }
}
