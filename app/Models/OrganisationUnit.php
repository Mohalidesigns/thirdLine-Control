<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class OrganisationUnit extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'parent_id', 'code', 'name', 'type', 'head_user_id',
        // Regulatory profile (Phase 8) — drives obligation applicability.
        'is_legal_entity', 'entity_type', 'licence_categories', 'jurisdiction',
        'rc_number', 'fiscal_year_end_month', 'fiscal_year_end_day', 'incorporated_on',
    ];

    protected $casts = [
        'is_legal_entity' => 'boolean',
        'licence_categories' => 'array',
        'fiscal_year_end_month' => 'integer',
        'fiscal_year_end_day' => 'integer',
        'incorporated_on' => 'date',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(OrganisationUnit::class, 'parent_id');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function processes(): HasMany
    {
        return $this->hasMany(BusinessProcess::class, 'unit_id');
    }

    public function obligationAssignments(): HasMany
    {
        return $this->hasMany(ObligationAssignment::class, 'entity_id');
    }

    /**
     * Fiscal year end for this entity, falling back to the tenant's
     * fiscal_year_start_month (R7 — never assume a December year end).
     */
    public function fiscalYearEnd(int $year): Carbon
    {
        if ($this->fiscal_year_end_month) {
            $endOfMonth = Carbon::create($year, $this->fiscal_year_end_month, 1)->endOfMonth();

            return $this->fiscal_year_end_day
                ? $endOfMonth->setUnitNoOverflow('day', $this->fiscal_year_end_day, 'month')->endOfDay()
                : $endOfMonth;
        }

        $startMonth = $this->tenant?->fiscal_year_start_month ?: 1;

        return Carbon::create($year, $startMonth, 1)
            ->addYear()->subDay()->endOfDay();
    }
}
