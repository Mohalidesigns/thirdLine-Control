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
        'footer_config', 'confidentiality', 'owner_id', 'last_edited_by', 'approved_by', 'approved_at',
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

    /** Shipped definitions are reviewed with the release; everything else needs an approver's sign-off. */
    public function isApproved(): bool
    {
        return $this->is_system || $this->approved_at !== null;
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('is_system', true)->orWhereNotNull('approved_at'));
    }

    /** Whether this user may open material at the given classification. */
    public static function levelOpenTo(User $user, ?string $level): bool
    {
        return match ($level) {
            'Board' => $user->hasAnyRole(['Executive Viewer', 'Control Function Head', 'System Administrator']),
            'Confidential' => $user->can('export reports'),
            default => $user->can('view dashboards'),
        };
    }

    public function isOpenTo(User $user): bool
    {
        return static::levelOpenTo($user, $this->confidentiality);
    }

    public function scopeOpenTo(Builder $query, User $user): Builder
    {
        $levels = array_values(array_filter(self::CONFIDENTIALITY, fn ($level) => static::levelOpenTo($user, $level)));

        return $query->whereIn('confidentiality', $levels);
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
