<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ObjectVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'versionable_type', 'versionable_id', 'version_no',
        'payload', 'change_reason', 'created_by',
    ];

    protected $casts = [
        'version_no' => 'integer',
        'payload' => 'array',
    ];

    public function versionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
