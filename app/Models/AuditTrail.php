<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit trail record. Rows may only ever be inserted —
 * updates and deletes throw at the model layer (FR-12.4, NFR-6).
 */
class AuditTrail extends Model
{
    public const UPDATED_AT = null;

    /**
     * Classes that have been renamed since rows were written, old name =>
     * current name.
     *
     * History is not rewritten to match a rename — audit_trails carries
     * BEFORE UPDATE / BEFORE DELETE triggers precisely so it cannot be, and
     * a row says what it said on the day. The reconciliation happens here,
     * on the way out: the log presents and filters both names as one
     * subject, so a rename does not split a record's history into two piles
     * that neither query finds together.
     *
     * Add to this map when a model is renamed. Never solve it with an
     * UPDATE.
     */
    public const RENAMED_TYPES = [
        // CR-04 §B.1b — freed the name for the casework aggregate.
        'App\\Models\\InvestigationCase' => 'App\\Models\\SpeakUpCase',
    ];

    /** The current class name for a stored one, renames resolved. */
    public static function canonicalType(?string $type): ?string
    {
        return $type === null ? null : (self::RENAMED_TYPES[$type] ?? $type);
    }

    /**
     * Every stored spelling of a class, so a filter on the current name
     * still finds rows written under the old one.
     *
     * @return array<int, string>
     */
    public static function storedTypesFor(string $type): array
    {
        $legacy = array_keys(self::RENAMED_TYPES, $type, true);

        return [$type, ...$legacy];
    }

    protected $fillable = [
        'tenant_id',
        'user_id',
        'actor_name',
        'actor_email',
        'entity_type',
        'entity_id',
        'action',
        'event_label',
        'subject_label',
        'description',
        'before',
        'after',
        'ip_address',
        'user_agent',
        'method',
        'url',
        'route_name',
        'status_code',
        'device_name',
        'batch_id',
        'previous_hash',
        'row_hash',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    public static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit trail records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Audit trail records are immutable.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
