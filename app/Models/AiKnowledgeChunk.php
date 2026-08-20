<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A chunk of one indexed record.
 *
 * Not Auditable: re-indexing a control rewrites its chunks on every save,
 * and an audit trail of derived text would bury the trail that matters
 * under machine noise. What a user asked and what the model answered is
 * audited on ai_interactions; the index is a cache of tenant content that
 * is already audited at source.
 */
class AiKnowledgeChunk extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'source_type', 'source_id', 'chunk_index', 'title',
        'content', 'content_hash', 'embedding', 'metadata', 'permission_key',
        'token_count', 'indexed_at', 'is_stale',
    ];

    protected $casts = [
        'embedding' => 'array',
        'metadata' => 'array',
        'chunk_index' => 'integer',
        'source_id' => 'integer',
        'token_count' => 'integer',
        'is_stale' => 'boolean',
        'indexed_at' => 'datetime',
    ];

    /**
     * THE retrieval filter (Part C §C.7). A chunk is only a candidate when
     * the requesting user holds its permission_key. This runs in SQL so an
     * unreadable record is never a row the ranker even sees, let alone one
     * the model is shown.
     *
     * @param  array<int, string>  $permissions
     */
    public function scopePermittedTo(Builder $query, array $permissions): Builder
    {
        if ($permissions === []) {
            // No permissions, no candidates. Not "everything" — a user with
            // nothing must retrieve nothing.
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('permission_key', $permissions);
    }

    /**
     * @param  array<int, string>  $sources
     */
    public function scopeFromSources(Builder $query, array $sources): Builder
    {
        return $sources === [] ? $query : $query->whereIn('source_type', $sources);
    }

    public function sourceDefinition(): array
    {
        return config("ai.retrieval.sources.{$this->source_type}", []);
    }
}
