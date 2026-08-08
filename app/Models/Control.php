<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Control extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const STATUSES = ['Draft', 'Pending Approval', 'Active', 'Under Review', 'Retired'];

    public const TYPES = ['Preventive', 'Detective', 'Corrective'];

    public const NATURES = ['Manual', 'Automated', 'Hybrid', 'IT-Dependent Manual'];

    public const FREQUENCIES = ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Semi-annual', 'Annual', 'Event-driven'];

    public const COSO_COMPONENTS = [
        'Control Environment', 'Risk Assessment', 'Control Activities',
        'Information & Communication', 'Monitoring Activities',
    ];

    public const DESIGN_RATINGS = ['Adequate', 'Inadequate', 'Not Assessed'];

    public const OPERATING_RATINGS = ['Effective', 'Partially Effective', 'Ineffective', 'Not Tested'];

    public const OVERALL_RATINGS = ['Strong', 'Moderate', 'Weak'];

    public const FUNCTION_GROUPINGS = ['Identify', 'Protect', 'Detect', 'Respond', 'Recover', 'Govern'];

    public const CONTROL_LEVELS = ['Strategic', 'Operational', 'Transactional'];

    public const IMPLEMENTATION_STATUSES = [
        'Not Started', 'In Progress', 'Implemented', 'Partially Implemented', 'Not Applicable',
    ];

    protected $fillable = [
        'tenant_id', 'control_ref', 'external_ref', 'title', 'description', 'objective',
        'type', 'nature', 'category_id', 'coso_component', 'process_id', 'unit_id',
        'department', 'owner_id', 'frequency', 'test_frequency', 'is_key_control',
        'framework_refs', 'control_documentation', 'notes',
        'design_effectiveness', 'operating_effectiveness', 'overall_rating', 'last_assessed_at',
        'status', 'current_version',
        'library_level', 'parent_control_id', 'function_grouping', 'control_level',
        'implementation_status', 'implementation_progress', 'is_distributable',
        'is_template', 'created_by', 'approved_by', 'approved_at', 'sync_status',
    ];

    protected $casts = [
        'is_key_control' => 'boolean',
        'is_template' => 'boolean',
        'is_distributable' => 'boolean',
        'framework_refs' => 'array',
        'approved_at' => 'datetime',
        'last_assessed_at' => 'datetime',
        'current_version' => 'integer',
        'implementation_progress' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ControlCategory::class, 'category_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(BusinessProcess::class, 'process_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class, 'unit_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ControlVersion::class)->orderByDesc('version_no');
    }

    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(Risk::class, 'risk_control_map')
            ->withPivot(['contribution_weight', 'mapped_by', 'mapped_at'])
            ->withTimestamps();
    }

    public function testScripts(): HasMany
    {
        return $this->hasMany(TestScript::class);
    }

    public function activeTestScript(): ?TestScript
    {
        return $this->testScripts()->where('status', 'Active')->orderByDesc('version_no')->first();
    }

    public function testInstances(): HasMany
    {
        return $this->hasMany(TestInstance::class);
    }

    public function effectivenessRatings(): HasMany
    {
        return $this->hasMany(EffectivenessRating::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(ControlAssessment::class)->latest();
    }

    /**
     * Overall score derived from the latest design + operating assessments:
     * Adequate + Effective = Strong; any Inadequate/Ineffective = Weak;
     * anything else assessed = Moderate; unassessed = null.
     */
    public function deriveOverallRating(): ?string
    {
        $design = $this->design_effectiveness;
        $operating = $this->operating_effectiveness;

        if ($design === 'Not Assessed' && in_array($operating, ['Not Tested', null], true)) {
            return null;
        }

        if ($design === 'Inadequate' || $operating === 'Ineffective') {
            return 'Weak';
        }

        if ($design === 'Adequate' && $operating === 'Effective') {
            return 'Strong';
        }

        return 'Moderate';
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(ControlException::class);
    }

    /** Framework requirements this control claims to satisfy (Phase 8). */
    public function requirementMappings(): HasMany
    {
        return $this->hasMany(ControlRequirementMap::class);
    }

    public function requirements(): BelongsToMany
    {
        return $this->belongsToMany(FrameworkRequirement::class, 'control_requirement_map', 'control_id', 'requirement_id')
            ->withPivot(['coverage', 'mapped_by', 'mapped_at', 'approved_by', 'approved_at'])
            ->withTimestamps();
    }

    public function latestPublishedRating(): ?EffectivenessRating
    {
        return $this->effectivenessRatings()
            ->where('status', 'Published')
            ->orderByDesc('approved_at')
            ->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'Active');
    }

    /** Controls with no mapped risk — orphan controls (FR-2.5). */
    public function scopeOrphans(Builder $query): Builder
    {
        return $query->whereDoesntHave('risks')->where('is_template', false);
    }

    // ── Group vs entity library (Phase 9.1) ──────────────────────────

    /** The group master this entity control was distributed from. */
    public function parentControl(): BelongsTo
    {
        return $this->belongsTo(Control::class, 'parent_control_id');
    }

    /** Entity instances created by distributing this group control. */
    public function entityInstances(): HasMany
    {
        return $this->hasMany(Control::class, 'parent_control_id');
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(ControlDistribution::class);
    }

    public function isGroupControl(): bool
    {
        return $this->library_level === 'group';
    }

    public function scopeGroupLevel(Builder $query): Builder
    {
        return $query->where('library_level', 'group');
    }
}
