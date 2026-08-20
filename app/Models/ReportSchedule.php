<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportSchedule extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    public const DELIVERY_METHODS = ['email', 'in_app', 'sftp', 'api', 'download_only'];

    protected $fillable = [
        'tenant_id', 'report_definition_id', 'name', 'cron', 'timezone', 'parameters',
        'output_format', 'recipients', 'delivery_method', 'is_active',
        'last_run_at', 'next_run_at', 'last_status', 'created_by',
    ];

    protected $casts = [
        'parameters' => 'array',
        'recipients' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class, 'schedule_id');
    }

    public function scopeDue(Builder $query, ?string $at = null): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $at ?? now());
    }
}
