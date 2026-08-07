<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPack extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'code', 'name', 'jurisdiction', 'version', 'checksum', 'published_at',
        'installed_at', 'installed_by', 'changelog', 'verification_summary',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'installed_at' => 'datetime',
        'verification_summary' => 'array',
    ];

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    public function isInstalled(): bool
    {
        return $this->installed_at !== null;
    }
}
