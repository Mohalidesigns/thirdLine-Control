<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An in-progress resumable upload (15.2), assembled chunk by chunk. */
class ChunkedUpload extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'uuid', 'file_name', 'mime_type',
        'total_size', 'total_chunks', 'chunks_received', 'status',
        'temp_path', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'total_size' => 'integer',
        'total_chunks' => 'integer',
        'chunks_received' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
