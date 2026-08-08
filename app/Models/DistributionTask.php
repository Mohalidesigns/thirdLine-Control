<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionTask extends Model
{
    use Auditable, HasFactory;

    public const STATUSES = ['Open', 'In Progress', 'Complete', 'Blocked'];

    protected $fillable = [
        'distribution_id', 'title', 'description', 'sequence', 'owner_id', 'due_at',
        'status', 'completed_at', 'completed_by', 'evidence_required', 'blocker_reason',
    ];

    protected $casts = [
        'due_at' => 'date',
        'completed_at' => 'datetime',
        'evidence_required' => 'boolean',
        'sequence' => 'integer',
    ];

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(ControlDistribution::class, 'distribution_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
