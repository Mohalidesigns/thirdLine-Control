<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSnapshot extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory;

    public const STATUSES = ['Capturing', 'Ready', 'Failed', 'Expired', 'Purged'];

    protected $fillable = [
        'tenant_id', 'dataset_id', 'snapshot_ref', 'period_label', 'captured_at',
        'row_count', 'checksum', 'storage_path', 'status', 'error_message',
        'size_bytes', 'expires_at', 'legal_hold', 'legal_hold_reason', 'purged_at',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'purged_at' => 'datetime',
        'expires_at' => 'date',
        'legal_hold' => 'boolean',
        'row_count' => 'integer',
        'size_bytes' => 'integer',
    ];

    /**
     * Legal hold is absolute and enforced at the model layer, exactly as
     * for Evidence (FR-9.7): while it is set there is no deletion path,
     * not through the purge command, not through an admin screen, not
     * through a service that forgot to check (12.7).
     */
    public static function booted(): void
    {
        static::deleting(function (DataSnapshot $snapshot) {
            if ($snapshot->legal_hold) {
                throw new \LogicException(
                    "Snapshot {$snapshot->snapshot_ref} is under legal hold and cannot be deleted."
                );
            }
        });

        static::updating(function (DataSnapshot $snapshot) {
            if ($snapshot->getOriginal('legal_hold') && $snapshot->status === 'Purged') {
                throw new \LogicException(
                    "Snapshot {$snapshot->snapshot_ref} is under legal hold and cannot be purged."
                );
            }
        });
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(DataSourceDataset::class, 'dataset_id');
    }

    public function violations(): HasMany
    {
        return $this->hasMany(SodViolation::class, 'snapshot_id');
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'Ready');
    }

    public function scopePurgeable(Builder $query): Builder
    {
        return $query->whereIn('status', ['Ready', 'Expired', 'Failed'])
            ->where('legal_hold', false)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', now());
    }
}
