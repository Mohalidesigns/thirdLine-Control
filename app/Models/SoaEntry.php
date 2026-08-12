<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SoaEntry extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const IMPLEMENTATION_STATUSES = [
        'implemented', 'partially_implemented', 'planned', 'not_implemented',
    ];

    protected $fillable = [
        'tenant_id', 'framework_id', 'requirement_id', 'is_applicable',
        'justification', 'implementation_status', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'is_applicable' => 'boolean',
        'decided_at' => 'datetime',
    ];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(Framework::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(FrameworkRequirement::class, 'requirement_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
