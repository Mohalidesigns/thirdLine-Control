<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evidence extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const PII_CATEGORIES = [
        'Account numbers', 'BVN', 'Identity documents', 'Names & addresses',
        'Phone / email', 'Transaction data', 'Biometric data', 'Other',
    ];

    protected $table = 'evidence';

    protected $fillable = [
        'tenant_id', 'linked_type', 'linked_id', 'file_name', 'storage_path',
        'mime_type', 'file_size', 'checksum', 'contains_personal_data',
        'personal_data_categories', 'classification', 'retention_policy_id',
        'retention_expiry_date', 'legal_hold', 'legal_hold_reason',
        'uploaded_by', 'uploaded_at', 'disposal_requested_by',
        'disposal_requested_at', 'disposal_approved_by', 'disposed_at',
    ];

    protected $casts = [
        'contains_personal_data' => 'boolean',
        'personal_data_categories' => 'array',
        'retention_expiry_date' => 'date',
        'legal_hold' => 'boolean',
        'uploaded_at' => 'datetime',
        'disposal_requested_at' => 'datetime',
        'disposed_at' => 'datetime',
        'file_size' => 'integer',
    ];

    /**
     * Legal hold overrides everything: no deletion path may proceed while
     * it is set (FR-9.7). Enforced at the model layer as the last line.
     */
    public static function booted(): void
    {
        static::deleting(function (Evidence $evidence) {
            if ($evidence->legal_hold) {
                throw new \LogicException("Evidence #{$evidence->id} is under legal hold and cannot be deleted.");
            }
        });
    }

    public function linked(): MorphTo
    {
        return $this->morphTo('linked', 'linked_type', 'linked_id');
    }

    public function retentionPolicy(): BelongsTo
    {
        return $this->belongsTo(RetentionPolicy::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(EvidenceAccessLog::class);
    }

    public function logAccess(string $action): void
    {
        $this->accessLogs()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'ip_address' => request()?->ip(),
        ]);
    }
}
