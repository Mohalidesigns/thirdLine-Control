<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CR-01: default recipient routing for an issued exception. Evaluated in
 * sequence order; first match wins; only ever PRE-FILLS the issue form.
 */
class ExceptionRoutingRule extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const ROUTE_TO_TYPES = ['unit_head', 'process_owner', 'role', 'user'];

    protected $fillable = [
        'tenant_id', 'sequence', 'is_active',
        'match_unit_id', 'match_process_id', 'match_control_category_id',
        'match_severity', 'match_source_type',
        'route_to_type', 'route_to_role', 'route_to_user_id', 'route_to_unit_id',
        'cc_role', 'cc_user_ids', 'sla_policy_id',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'is_active' => 'boolean',
        'cc_user_ids' => 'array',
    ];

    public function matchUnit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'match_unit_id');
    }

    public function matchProcess(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'match_process_id');
    }

    public function routeToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'route_to_user_id');
    }

    public function routeToUnit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'route_to_unit_id');
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(ExceptionSlaPolicy::class, 'sla_policy_id');
    }
}
