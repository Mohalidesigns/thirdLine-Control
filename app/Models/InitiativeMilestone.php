<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InitiativeMilestone extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUSES = ['Pending', 'In Progress', 'Complete', 'Missed'];

    protected $fillable = [
        'tenant_id', 'initiative_id', 'title', 'due_date', 'completed_at',
        'completed_by', 'status', 'weight', 'sort_order',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'weight' => 'integer',
        'sort_order' => 'integer',
    ];

    public function initiative(): BelongsTo
    {
        return $this->belongsTo(Initiative::class);
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isComplete(): bool
    {
        return $this->status === 'Complete';
    }
}
