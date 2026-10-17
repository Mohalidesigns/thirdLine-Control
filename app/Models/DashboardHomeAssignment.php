<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The board a role lands on at /dashboard, set from Reporting → Dashboards. */
class DashboardHomeAssignment extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['tenant_id', 'role_id', 'dashboard_id', 'assigned_by'];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }
}
