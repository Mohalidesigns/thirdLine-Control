<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasVersions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReportDefinition extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasVersions, SoftDeletes;

    public const TYPES = ['operational', 'management', 'board', 'regulatory', 'ad_hoc'];

    public const FORMATS = ['pdf', 'docx', 'xlsx', 'pptx', 'html', 'csv'];

    /** Section types the designer can place (13.3). */
    public const SECTION_TYPES = [
        'cover', 'toc', 'narrative', 'table', 'chart', 'kpi_row',
        'page_break', 'appendix', 'signature_block',
    ];

    /**
     * Confidentiality drives distribution: a Board-classified report is
     * never mailed to a recipient who cannot already read board material
     * (13.3 business rule).
     */
    public const CONFIDENTIALITY = ['Public', 'Internal', 'Confidential', 'Board'];

    protected $fillable = [
        'tenant_id', 'code', 'name', 'description', 'report_type', 'output_formats',
        'sections', 'parameters', 'styling', 'cover_page_config', 'header_config',
        'footer_config', 'confidentiality', 'owner_id', 'approved_by', 'approved_at',
        'version_no', 'is_system', 'is_active', 'regulator_id', 'obligation_id',
    ];

    protected $casts = [
        'output_formats' => 'array',
        'sections' => 'array',
        'parameters' => 'array',
        'styling' => 'array',
        'cover_page_config' => 'array',
        'header_config' => 'array',
        'footer_config' => 'array',
        'approved_at' => 'datetime',
        'version_no' => 'integer',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** @var array<int, string> */
    protected array $versioned = [
        'name', 'description', 'report_type', 'output_formats', 'sections',
        'parameters', 'styling', 'cover_page_config', 'header_config',
        'footer_config', 'confidentiality',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function regulator(): BelongsTo
    {
        return $this->belongsTo(Regulator::class);
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(RegulatoryObligation::class, 'obligation_id')->withoutGlobalScope('tenant');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return array<int, string> */
    public function formats(): array
    {
        $formats = array_values(array_intersect($this->output_formats ?? [], self::FORMATS));

        return $formats !== [] ? $formats : ['pdf'];
    }

    public function supportsFormat(string $format): bool
    {
        return in_array($format, $this->formats(), true);
    }
}
