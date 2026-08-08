<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned prompt. Deliberately NOT BelongsToTenant: resolution needs
 * both the shipped library (tenant_id NULL) and the tenant's overrides in
 * one query, the same pattern FeatureFlag uses. PromptRegistry owns that
 * resolution; nothing else should query this table directly.
 */
class AiPrompt extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'tenant_id', 'key', 'version', 'name', 'description',
        'system_prompt', 'user_template', 'output_schema', 'variables',
        'model_hint', 'temperature', 'max_tokens', 'is_active',
        'created_by', 'approved_by', 'approved_at', 'changelog', 'eval_score',
    ];

    protected $casts = [
        'output_schema' => 'array',
        'variables' => 'array',
        'version' => 'integer',
        'max_tokens' => 'integer',
        'temperature' => 'float',
        'eval_score' => 'float',
        'is_active' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(AiInteraction::class, 'prompt_id');
    }

    /** The shipped library plus this tenant's overrides, nothing else. */
    public function scopeVisibleTo(Builder $query, ?int $tenantId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('tenant_id')
            ->when($tenantId, fn (Builder $w) => $w->orWhere('tenant_id', $tenantId)));
    }

    /**
     * Field-by-field difference against another version, for the prompt
     * history diff on the governance page.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function diffAgainst(?self $other): array
    {
        if (! $other) {
            return [];
        }

        $fields = ['system_prompt', 'user_template', 'model_hint', 'max_tokens', 'temperature'];
        $diff = [];

        foreach ($fields as $field) {
            if ($this->{$field} != $other->{$field}) {
                $diff[$field] = ['from' => $other->{$field}, 'to' => $this->{$field}];
            }
        }

        if (json_encode($this->output_schema) !== json_encode($other->output_schema)) {
            $diff['output_schema'] = ['from' => $other->output_schema, 'to' => $this->output_schema];
        }

        return $diff;
    }
}
