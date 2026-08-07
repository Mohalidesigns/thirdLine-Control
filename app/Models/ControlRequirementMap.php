<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A control's claim to satisfy a framework requirement. The claim is
 * maker-checker: unapproved mappings never count toward coverage and
 * never appear in a generated submission.
 */
class ControlRequirementMap extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $table = 'control_requirement_map';

    public const COVERAGE_LEVELS = ['full', 'partial', 'supporting'];

    protected $fillable = [
        'tenant_id', 'control_id', 'requirement_id', 'coverage', 'notes',
        'mapped_by', 'mapped_at', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'mapped_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(FrameworkRequirement::class, 'requirement_id');
    }

    public function mapper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapped_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Qualified so the scopes survive a join against controls, which also
    // carries an approved_at column.
    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereNotNull($this->getTable().'.approved_at');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull($this->getTable().'.approved_at');
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }
}
