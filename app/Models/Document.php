<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use App\Models\Concerns\HasVersions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, HasVersions, SoftDeletes;

    /** Metadata versioning; file content history lives in document_versions. */
    protected array $versioned = [
        'title', 'description', 'document_type', 'folder_id', 'status',
        'owner_id', 'review_due_at', 'is_confidential', 'access_role_ids', 'tags',
    ];

    public const TYPES = [
        'policy', 'procedure', 'standard', 'guideline', 'form', 'template',
        'charter', 'report', 'certificate', 'contract', 'other',
    ];

    public const STATUSES = ['Draft', 'Under Review', 'Approved', 'Published', 'Superseded', 'Archived'];

    protected $fillable = [
        'tenant_id', 'folder_id', 'title', 'description', 'document_type', 'reference',
        'version_no', 'status', 'file_path', 'file_hash', 'mime_type', 'size_bytes',
        'owner_id', 'approver_id', 'approved_at', 'published_at', 'review_due_at',
        'next_review_at', 'is_confidential', 'access_role_ids', 'tags',
        'supersedes_document_id', 'download_count',
    ];

    protected $casts = [
        'version_no' => 'integer',
        'size_bytes' => 'integer',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
        'review_due_at' => 'date',
        'next_review_at' => 'date',
        'is_confidential' => 'boolean',
        'access_role_ids' => 'array',
        'tags' => 'array',
        'download_count' => 'integer',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_no');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'supersedes_document_id');
    }

    public function supersededBy(): HasMany
    {
        return $this->hasMany(Document::class, 'supersedes_document_id');
    }

    public function attestations(): MorphMany
    {
        return $this->morphMany(Attestation::class, 'attestable');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'Published');
    }

    public function scopeReviewDue(Builder $query, int $withinDays = 30): Builder
    {
        return $query->whereNotNull('review_due_at')
            ->whereDate('review_due_at', '<=', now()->addDays($withinDays))
            ->whereNotIn('status', ['Superseded', 'Archived']);
    }

    /** Confidential documents are gated by role id (9.6). */
    public function accessibleBy(User $user): bool
    {
        if (! $this->is_confidential) {
            return true;
        }

        $allowed = collect($this->access_role_ids ?? []);

        return $allowed->isEmpty()
            ? false
            : $user->roles->pluck('id')->intersect($allowed)->isNotEmpty();
    }
}
