<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataSourceDataset extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const PII_CLASSIFICATIONS = ['none', 'internal', 'personal', 'sensitive_personal'];

    protected $fillable = [
        'tenant_id', 'data_source_id', 'name', 'description', 'extraction_config',
        'schema_definition', 'primary_key_fields', 'incremental_field', 'retention_days',
        'row_count_last', 'pii_classification', 'pii_fields', 'dpo_authorised_by',
        'dpo_authorised_at', 'dpo_authorisation_note', 'is_active',
    ];

    protected $casts = [
        'extraction_config' => 'array',
        'schema_definition' => 'array',
        'primary_key_fields' => 'array',
        'pii_fields' => 'array',
        'retention_days' => 'integer',
        'row_count_last' => 'integer',
        'dpo_authorised_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * An extraction_config can carry a query or a path that reveals the
     * shape of the source system; it stays server-side. Controllers hand
     * the UI an explicit, sanitised projection instead.
     */
    protected $hidden = ['extraction_config'];

    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(DataSnapshot::class, 'dataset_id');
    }

    public function dpoAuthoriser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dpo_authorised_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Sensitive personal data is hashed at ingestion unless the tenant's
     * DPO has explicitly authorised retaining it in clear (12.7, R5).
     */
    public function requiresHashing(): bool
    {
        return $this->pii_classification === 'sensitive_personal' && $this->dpo_authorised_at === null;
    }

    /** Fields carrying personal data, per the dataset's own declaration. */
    public function personalFields(): array
    {
        return array_values(array_filter((array) ($this->pii_fields ?? []), 'is_string'));
    }
}
