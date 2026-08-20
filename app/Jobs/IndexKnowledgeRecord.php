<?php

namespace App\Jobs;

use App\Services\Ai\KnowledgeIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-index one record (Part C §C.7: "re-index on change via a queued
 * listener; mark stale rather than blocking the write").
 *
 * Carries the class and key rather than the model instance, so a job
 * sitting in the queue when the record is edited again picks up the current
 * state instead of replaying a stale snapshot.
 *
 * Deliberately swallows its own failures. Indexing is a search convenience;
 * a failed re-index must never surface as a failed save to a control owner
 * who was only editing a description. The chunk stays marked stale and the
 * nightly ai:reindex sweep collects it.
 */
class IndexKnowledgeRecord implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        private readonly string $modelClass,
        private readonly int $modelId,
        private readonly bool $deleted = false,
    ) {}

    public function handle(KnowledgeIndexer $indexer): void
    {
        try {
            if ($this->deleted) {
                $type = array_search($this->modelClass, KnowledgeIndexer::sourceMap(), true);

                if ($type !== false) {
                    $indexer->purge($type, $this->modelId);
                }

                return;
            }

            /** @var class-string<Model> $class */
            $class = $this->modelClass;

            $record = $class::withoutGlobalScopes()->find($this->modelId);

            if ($record) {
                $indexer->index($record);
            }
        } catch (\Throwable $e) {
            Log::warning('AI knowledge indexing failed.', [
                'model' => $this->modelClass,
                'id' => $this->modelId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /** Deduplicate: many saves of one record collapse to one re-index. */
    public function uniqueId(): string
    {
        return $this->modelClass.':'.$this->modelId;
    }
}
