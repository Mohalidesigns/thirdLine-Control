<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CR-03 §D: one run of the checklist importer — dry run or commit. The
 * diff report stored here is the audit record of what an operator was
 * shown before they committed, which is the whole point of separating
 * the two steps.
 */
class ControlFunctionImport extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = ['Dry Run', 'Committed', 'Failed', 'Discarded'];

    protected $fillable = [
        'tenant_id', 'reference', 'source_name', 'source_hash', 'source_version',
        'status', 'rows_total', 'rows_unresolved',
        'controls_added', 'controls_changed',
        'items_added', 'items_changed', 'items_removed', 'scripts_versioned',
        'diff_report', 'error', 'created_by', 'committed_at',
    ];

    protected $casts = [
        'diff_report' => 'array',
        'committed_at' => 'datetime',
        'rows_total' => 'integer',
        'rows_unresolved' => 'integer',
        'controls_added' => 'integer',
        'controls_changed' => 'integer',
        'items_added' => 'integer',
        'items_changed' => 'integer',
        'items_removed' => 'integer',
        'scripts_versioned' => 'integer',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ControlFunctionImportRow::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** A run with an unresolved row is never committable (§D.1 step 4). */
    public function isCommittable(): bool
    {
        return $this->status === 'Dry Run' && $this->rows_unresolved === 0;
    }
}
