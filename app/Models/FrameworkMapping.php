<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FrameworkMapping extends Model
{
    use Auditable, HasFactory;

    public const RELATIONSHIPS = ['equivalent', 'partial', 'supports', 'informs'];

    protected $fillable = [
        'source_requirement_id', 'target_requirement_id', 'relationship',
        'coverage_percentage', 'rationale', 'created_by', 'verified_by', 'verified_at',
    ];

    protected $casts = [
        'coverage_percentage' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(FrameworkRequirement::class, 'source_requirement_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(FrameworkRequirement::class, 'target_requirement_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
