<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use App\Models\Concerns\HasRichText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrossBorderTransfer extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, HasRichText;

    public const STATUSES = ['pending_authorisation', 'authorised', 'completed'];

    protected $fillable = [
        'tenant_id', 'entity_id', 'reference', 'direction', 'data_categories',
        'contains_personal_data', 'description', 'destination_country',
        'recipient', 'lawful_basis_id', 'lawful_basis_note', 'volume_records',
        'transferred_at', 'recorded_by', 'authorised_by', 'authorised_at', 'status',
    ];

    /** Spec §9 — Editor.js documents in {field}_rich; the plain column is the derived mirror. */
    protected array $richText = ['description', 'lawful_basis_note'];

    protected $casts = [
        'data_categories' => 'array',
        'contains_personal_data' => 'boolean',
        'volume_records' => 'integer',
        'transferred_at' => 'datetime',
        'authorised_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function lawfulBasis(): BelongsTo
    {
        return $this->belongsTo(TransferLawfulBasis::class, 'lawful_basis_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function authoriser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }
}
