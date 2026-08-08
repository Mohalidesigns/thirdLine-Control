<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreatmentMilestone extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = ['Pending', 'In Progress', 'Completed', 'Overdue', 'Cancelled'];

    protected $fillable = [
        'tenant_id', 'treatment_id', 'title', 'due_at', 'status',
        'completed_at', 'owner_id', 'sequence',
    ];

    protected $casts = [
        'due_at' => 'date',
        'completed_at' => 'datetime',
        'sequence' => 'integer',
    ];

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(RiskTreatment::class, 'treatment_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
