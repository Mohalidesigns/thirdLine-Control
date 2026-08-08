<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicySection extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'policy_id', 'parent_id', 'sequence', 'heading', 'body',
        'is_mandatory', 'control_ids', 'requirement_ids',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'is_mandatory' => 'boolean',
        'control_ids' => 'array',
        'requirement_ids' => 'array',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sequence');
    }
}
