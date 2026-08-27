<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SodConflictRule extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasRichText, SoftDeletes;

    public const CONFLICT_TYPES = [
        'create_approve', 'create_pay', 'maintain_reconcile', 'request_authorise', 'custom',
    ];

    public const RISK_LEVELS = ['Critical', 'High', 'Medium', 'Low'];

    protected $fillable = [
        'tenant_id', 'name', 'description', 'system_key', 'function_a', 'function_b',
        'conflict_type', 'risk_level', 'mitigating_control_id', 'is_active',
    ];

    /** Spec §9 — Editor.js documents in {field}_rich; the plain column is the derived mirror. */
    protected array $richText = ['description'];

    protected $casts = ['is_active' => 'boolean'];

    public function mitigatingControl(): BelongsTo
    {
        return $this->belongsTo(Control::class, 'mitigating_control_id');
    }

    public function violations(): HasMany
    {
        return $this->hasMany(SodViolation::class, 'rule_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
