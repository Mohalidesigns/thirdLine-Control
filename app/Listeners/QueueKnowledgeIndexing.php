<?php

namespace App\Listeners;

use App\Jobs\IndexKnowledgeRecord;
use App\Services\Ai\KnowledgeIndexer;
use Illuminate\Database\Eloquent\Model;

/**
 * Wires the RAG index to model saves (Part C §C.7).
 *
 * Registered in AppServiceProvider against the saved and deleted events of
 * every indexable model. The synchronous part is one UPDATE marking chunks
 * stale; the rebuild is queued, so a control owner saving a description is
 * never waiting on an embedding pass.
 *
 * Everything here is wrapped: a broken index must never break a business
 * write. That is the same rule the audit trail follows (R3), for the same
 * reason — a subsystem that can veto a save is a subsystem that will
 * eventually stop the business.
 */
class QueueKnowledgeIndexing
{
    public function __construct(private KnowledgeIndexer $indexer) {}

    public function saved(Model $record): void
    {
        $this->guard(function () use ($record) {
            if (! KnowledgeIndexer::sourceTypeFor($record)) {
                return;
            }

            $this->indexer->markStale($record);

            IndexKnowledgeRecord::dispatch($record::class, (int) $record->getKey());
        });
    }

    public function deleted(Model $record): void
    {
        $this->guard(function () use ($record) {
            if (! KnowledgeIndexer::sourceTypeFor($record)) {
                return;
            }

            IndexKnowledgeRecord::dispatch($record::class, (int) $record->getKey(), deleted: true);
        });
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable) {
            // Intentionally silent. The nightly ai:reindex sweep is the
            // backstop, and a stale chunk still answers questions.
        }
    }
}
