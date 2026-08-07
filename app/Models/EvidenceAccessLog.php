<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceAccessLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = [
        'evidence_id', 'user_id', 'action', 'ip_address', 'accessed_at',
    ];

    protected $casts = [
        'accessed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
