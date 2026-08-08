<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One AI call, recorded like a user action (R3). Append-only by convention
 * and by the absence of any code path that deletes one: the decision fields
 * are the only ones written after creation, and each write is itself
 * audited through the Auditable trait.
 */
class AiInteraction extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = [
        'Pending', 'Completed', 'Failed', 'Rejected by Schema',
        'Budget Exceeded', 'Rate Limited', 'Unavailable',
    ];

    public const DECISIONS = ['Pending', 'Accepted', 'Edited', 'Rejected'];

    /** Statuses where the model produced something a human can act on. */
    public const ACTIONABLE_STATUSES = ['Completed'];

    protected $fillable = [
        'tenant_id', 'user_id', 'capability_key', 'prompt_id', 'prompt_version',
        'model', 'subject_type', 'subject_id', 'input_hash', 'input_redacted',
        'retrieved_context', 'raw_output', 'parsed_output', 'confidence',
        'citations', 'input_tokens', 'output_tokens', 'cost_minor', 'currency',
        'latency_ms', 'status', 'error_message', 'human_decision', 'decided_by',
        'decided_at', 'edit_diff', 'rejection_reason', 'applied_type', 'applied_id',
    ];

    protected $casts = [
        'input_redacted' => 'array',
        'retrieved_context' => 'array',
        'parsed_output' => 'array',
        'citations' => 'array',
        'edit_diff' => 'array',
        'confidence' => 'float',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cost_minor' => 'integer',
        'latency_ms' => 'integer',
        'prompt_version' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiPrompt::class, 'prompt_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    public function applied(): MorphTo
    {
        return $this->morphTo('applied');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AiFeedback::class, 'interaction_id');
    }

    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    /**
     * A draft is still open for a human decision. Anything that failed, was
     * budget-stopped or has already been decided is closed — the review
     * panel offers no buttons and the service refuses to apply it.
     */
    public function isAwaitingDecision(): bool
    {
        return $this->status === 'Completed' && $this->human_decision === 'Pending';
    }

    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->where('status', 'Completed')->where('human_decision', 'Pending');
    }

    public function scopeCapability(Builder $query, string $key): Builder
    {
        return $query->where('capability_key', $key);
    }

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }
}
