<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpotCheck extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const STATUSES = ['Draft', 'In Progress', 'Completed', 'Report Issued'];

    protected $fillable = [
        'tenant_id', 'reference', 'title', 'scope_description', 'unit_id', 'process_id',
        'control_ids', 'is_surprise', 'conducted_by', 'date_conducted',
        'report_template_id', 'status', 'report_issued_at',
    ];

    protected $casts = [
        'control_ids' => 'array',
        'is_surprise' => 'boolean',
        'date_conducted' => 'date',
        'report_issued_at' => 'datetime',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'unit_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'process_id');
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function reportTemplate(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }
}
