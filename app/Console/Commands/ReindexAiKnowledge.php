<?php

namespace App\Console\Commands;

use App\Models\AiKnowledgeChunk;
use App\Services\Ai\KnowledgeIndexer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuild the RAG index.
 *
 * Two modes. Without --all it collects only what the queued listener marked
 * stale and could not rebuild — the backstop for a queue outage. With --all
 * it walks every indexable record, which is what you run after changing the
 * chunking strategy or the embedding driver, since existing vectors are then
 * no longer comparable with new ones.
 */
class ReindexAiKnowledge extends Command
{
    protected $signature = 'ai:reindex
                            {--all : Rebuild every record, not just the stale ones}
                            {--source= : Limit to one source type (control, risk, policy, …)}';

    protected $description = 'Rebuild the AI knowledge index used for retrieval-augmented answers';

    public function handle(KnowledgeIndexer $indexer): int
    {
        $sources = KnowledgeIndexer::sourceMap();

        if ($only = $this->option('source')) {
            if (! isset($sources[$only])) {
                $this->error("Unknown source '{$only}'. Known: ".implode(', ', array_keys($sources)));

                return self::FAILURE;
            }

            $sources = [$only => $sources[$only]];
        }

        $indexed = 0;
        $chunks = 0;

        foreach ($sources as $type => $class) {
            /** @var class-string<Model> $class */
            $query = $class::withoutGlobalScopes();

            if (! $this->option('all')) {
                $staleIds = AiKnowledgeChunk::withoutGlobalScopes()
                    ->where('source_type', $type)
                    ->where('is_stale', true)
                    ->distinct()
                    ->pluck('source_id');

                if ($staleIds->isEmpty()) {
                    continue;
                }

                $query->whereIn((new $class)->getKeyName(), $staleIds);
            }

            $query->chunkById(200, function ($records) use ($indexer, &$indexed, &$chunks) {
                foreach ($records as $record) {
                    $chunks += $indexer->index($record);
                    $indexed++;
                }
            });

            $this->line("  {$type}: done");
        }

        $this->info("Indexed {$indexed} record(s) into {$chunks} chunk(s).");

        return self::SUCCESS;
    }
}
