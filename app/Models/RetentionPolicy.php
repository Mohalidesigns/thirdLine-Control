<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RetentionPolicy extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'evidence_class', 'retention_months',
        'disposal_action', 'requires_dual_approval', 'legal_basis_note', 'is_default',
    ];

    protected $casts = [
        'retention_months' => 'integer',
        'requires_dual_approval' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }
}
