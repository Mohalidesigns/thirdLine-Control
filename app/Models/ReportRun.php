<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportRun extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory;

    public const STATUSES = ['Queued', 'Running', 'Completed', 'Failed', 'Expired'];

    protected $fillable = [
        'tenant_id', 'report_definition_id', 'schedule_id', 'run_ref', 'parameters',
        'requested_by', 'started_at', 'completed_at', 'status', 'output_path',
        'output_format', 'file_size', 'page_count', 'checksum', 'distributed_to',
        'error_message', 'download_token', 'download_count', 'expires_at',
    ];

    protected $hidden = ['download_token'];

    protected $casts = [
        'parameters' => 'array',
        'distributed_to' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
        'file_size' => 'integer',
        'page_count' => 'integer',
        'download_count' => 'integer',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'schedule_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeDownloadable(Builder $query): Builder
    {
        return $query->where('status', 'Completed')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'Completed'
            && $this->output_path
            && ! $this->expires_at?->isPast();
    }
}
