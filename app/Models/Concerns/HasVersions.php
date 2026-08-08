<?php

namespace App\Models\Concerns;

use App\Models\ObjectVersion;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;

/**
 * The ControlVersion pattern, generalised (9.8): every versioned object
 * snapshots its attributes into object_versions on create and on every
 * change to a versioned attribute. Versioning must never break the
 * business write — failures log and move on (mirrors Auditable, R3).
 *
 * Models may declare `protected array $versioned = [...]` to control which
 * attributes are snapshotted; the default is every fillable attribute
 * except ids, counters and timestamps.
 */
trait HasVersions
{
    public static function bootHasVersions(): void
    {
        static::created(fn ($model) => $model->snapshotVersion('Initial version'));

        static::updated(function ($model) {
            $changed = array_intersect(array_keys($model->getChanges()), $model->versionedAttributes());

            if ($changed !== []) {
                $model->snapshotVersion();
            }
        });
    }

    public function objectVersions(): MorphMany
    {
        return $this->morphMany(ObjectVersion::class, 'versionable')->orderByDesc('version_no');
    }

    /** @return array<int, string> */
    public function versionedAttributes(): array
    {
        if (property_exists($this, 'versioned') && $this->versioned !== []) {
            return $this->versioned;
        }

        return array_values(array_diff($this->getFillable(), [
            'tenant_id', 'created_by', 'updated_by', 'approved_by', 'approved_at',
            'download_count', 'current_version',
        ]));
    }

    public function snapshotVersion(?string $reason = null): ?ObjectVersion
    {
        try {
            $next = (int) $this->objectVersions()->max('version_no') + 1;

            return $this->objectVersions()->create([
                'tenant_id' => $this->tenant_id ?? auth()->user()?->tenant_id,
                'version_no' => $next,
                'payload' => $this->only($this->versionedAttributes()),
                'change_reason' => $reason,
                'created_by' => auth()->id(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Version snapshot failed', [
                'model' => static::class.'#'.$this->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Field-level diff between two version numbers:
     * [field => ['from' => x, 'to' => y]].
     */
    public function diffVersions(int $fromNo, int $toNo): array
    {
        $versions = $this->objectVersions()
            ->whereIn('version_no', [$fromNo, $toNo])
            ->get()
            ->keyBy('version_no');

        $from = $versions->get($fromNo)?->payload ?? [];
        $to = $versions->get($toNo)?->payload ?? [];

        $diff = [];

        foreach (array_unique([...array_keys($from), ...array_keys($to)]) as $field) {
            $before = $from[$field] ?? null;
            $after = $to[$field] ?? null;

            if ($before != $after) {
                $diff[$field] = ['from' => $before, 'to' => $after];
            }
        }

        return $diff;
    }
}
