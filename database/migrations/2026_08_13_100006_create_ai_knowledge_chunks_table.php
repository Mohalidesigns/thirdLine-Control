<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The RAG index (Part C §C.7). One row per chunk of one source record.
     *
     * permission_key is the load-bearing column. Retrieval joins on it
     * against the requesting user's effective permissions IN SQL, so a chunk
     * the user could not open is never a candidate — not filtered out after
     * ranking, not trimmed before the prompt, simply never selected. A user
     * must never receive a synthesised answer built from records they cannot
     * read, and the only way to guarantee that is to make the unreadable
     * rows unreachable.
     *
     * embedding is JSON rather than a vector column: MySQL 8 has no native
     * vector type here, and §C.7 explicitly permits JSON plus similarity in
     * PHP over a keyword-prefiltered candidate set.
     *
     * is_stale lets a save mark its chunks out of date without blocking the
     * write. The queued indexer rebuilds them; until it does, a stale chunk
     * is still retrievable, because a slightly old control description is a
     * better answer than a missing one.
     */
    public function up(): void
    {
        Schema::create('ai_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->unsignedInteger('chunk_index')->default(0);
            $table->string('title')->nullable();
            $table->text('content');
            $table->char('content_hash', 64);
            $table->json('embedding')->nullable();
            $table->json('metadata')->nullable();
            $table->string('permission_key', 60);
            $table->unsignedInteger('token_count')->default(0);
            $table->timestamp('indexed_at')->nullable();
            $table->boolean('is_stale')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'source_type', 'source_id', 'chunk_index'], 'ai_chunks_source_unique');
            $table->index('tenant_id');
            $table->index('source_type');
            $table->index('permission_key');
            $table->index('is_stale');
            $table->index(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id', 'permission_key']);
        });

        // Keyword recall for the hybrid retrieval. MySQL gets a real
        // FULLTEXT index; SQLite (the test connection) falls back to LIKE
        // in RetrievalService, which is correct but slower and only ever
        // runs against fixture-sized data.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE ai_knowledge_chunks ADD FULLTEXT ai_chunks_fulltext (title, content)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_chunks');
    }
};
