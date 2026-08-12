<?php

namespace App\Models;

use App\Exceptions\ResidencyViolationException;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Entity extends Model
{
    use Auditable, BelongsToTenant, GeneratesReference, HasFactory, SoftDeletes;

    public const ENTITY_TYPES = [
        'holding', 'bank', 'merchant_bank', 'microfinance_bank', 'payment_service_bank',
        'pssp', 'ptsp', 'switching', 'mmo', 'super_agent', 'insurer', 'broker',
        'pfa', 'pfc', 'cmo', 'telco', 'corporate', 'subsidiary', 'branch',
        'representative_office',
    ];

    public const CONSOLIDATION_METHODS = ['full', 'proportional', 'equity', 'none'];

    public const STATUSES = ['active', 'dormant', 'divested'];

    protected $fillable = [
        'tenant_id', 'parent_entity_id', 'organisation_unit_id', 'reference',
        'legal_name', 'trading_name', 'entity_type', 'jurisdiction',
        'registration_number', 'tax_id', 'licence_categories', 'regulators',
        'fiscal_year_end', 'functional_currency', 'consolidation_method',
        'ownership_percentage', 'is_regulated', 'data_residency_country',
        'status', 'created_by',
    ];

    protected $casts = [
        'licence_categories' => 'array',
        'regulators' => 'array',
        'ownership_percentage' => 'decimal:2',
        'is_regulated' => 'boolean',
    ];

    /**
     * A residency declaration is set at provisioning and changed only by
     * re-provisioning (16.2 business rule). Any runtime edit throws; the
     * provisioning command opts in explicitly and the change is audited.
     */
    protected static function booted(): void
    {
        static::updating(function (Entity $entity) {
            if ($entity->isDirty('data_residency_country') && ! app()->bound('residency.reprovisioning')) {
                throw new ResidencyViolationException(
                    'A data-residency declaration cannot be changed at runtime — re-provision the entity.'
                );
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'parent_entity_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Entity::class, 'parent_entity_id');
    }

    public function organisationUnit(): BelongsTo
    {
        return $this->belongsTo(OrganisationUnit::class);
    }

    /** Users explicitly granted access to this entity's data (16.1). */
    public function grantedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['granted_by', 'note', 'created_at']);
    }

    /**
     * The organisation-unit subtree this legal entity anchors. Domain
     * records (controls via unit_id; risks, metrics, obligation
     * assignments via their entity_id → organisation_units columns, R8)
     * scope to the entity through these unit ids.
     *
     * @return array<int, int>
     */
    public function unitSubtreeIds(): array
    {
        if (! $this->organisation_unit_id) {
            return [];
        }

        $ids = [$this->organisation_unit_id];
        $frontier = [$this->organisation_unit_id];

        while ($frontier !== []) {
            $frontier = OrganisationUnit::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The proportion of a subsidiary's numbers the group position carries. */
    public function consolidationWeight(): float
    {
        return match ($this->consolidation_method) {
            'full' => 1.0,
            'proportional', 'equity' => (float) $this->ownership_percentage / 100,
            default => 0.0,
        };
    }
}
