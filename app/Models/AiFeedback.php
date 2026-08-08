<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFeedback extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $table = 'ai_feedback';

    public const CATEGORIES = [
        'incorrect', 'incomplete', 'irrelevant', 'unsafe', 'formatting', 'other',
    ];

    protected $fillable = [
        'tenant_id', 'interaction_id', 'user_id', 'rating',
        'was_useful', 'issue_category', 'comment',
    ];

    protected $casts = [
        'rating' => 'integer',
        'was_useful' => 'boolean',
    ];

    public function interaction(): BelongsTo
    {
        return $this->belongsTo(AiInteraction::class, 'interaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
