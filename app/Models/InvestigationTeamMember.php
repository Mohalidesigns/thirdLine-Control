<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who is on an investigation (CR-04 §C.2). This table is also the access
 * list the visibility scope reads, so adding someone to the team and
 * granting them sight of the case are one act, not two.
 */
class InvestigationTeamMember extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const ROLES = ['lead', 'investigator', 'reviewer', 'observer', 'subject_matter_expert'];

    protected $fillable = [
        'tenant_id', 'investigation_id', 'user_id', 'role',
        'assigned_at', 'assigned_by', 'notes',
    ];

    protected $casts = ['assigned_at' => 'datetime'];

    public function investigation(): BelongsTo
    {
        return $this->belongsTo(Investigation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
