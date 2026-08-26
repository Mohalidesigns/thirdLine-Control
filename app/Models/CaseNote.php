<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An investigation note (11.4). A note with author_id NULL is a reply from
 * an anonymous reporter on their token — the absence of an author is the
 * anonymity guarantee, not missing data.
 */
class CaseNote extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'case_id', 'author_id', 'note', 'is_privileged', 'is_reporter_visible',
    ];

    protected $casts = [
        'is_privileged' => 'boolean',
        'is_reporter_visible' => 'boolean',
    ];

    public function speakUpCase(): BelongsTo
    {
        return $this->belongsTo(SpeakUpCase::class, 'case_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopeShareable(Builder $query): Builder
    {
        return $query->where('is_privileged', false);
    }
}
