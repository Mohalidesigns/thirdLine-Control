<?php

namespace App\Services;

use App\Models\ControlFrequency;
use App\Models\FrequencyAlias;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * CR-03 §C.1/§E.1: the one place a frequency becomes a period.
 *
 * Two jobs, deliberately separate:
 *  - resolution — a raw string from the client's workbook ("Quaterly",
 *    "bi-annually", "As per sales by CBN") becomes a ControlFrequency,
 *    through the alias table and never through a match() in PHP. An
 *    unknown string resolves to NULL so the caller can fail loudly; the
 *    old silent fall-through to Monthly turned a mislabelled daily
 *    control into a monthly one without anybody noticing (§B.2 Gap 1).
 *  - periodisation — a frequency plus a moment becomes a label, a start,
 *    an end and a due date.
 *
 * Period labels are byte-identical to the ones TestingService produced
 * before this CR, so no existing instance is orphaned by the upgrade.
 */
class FrequencyResolver
{
    /** The legacy controls.frequency enum → catalogue code. */
    public const LEGACY_MAP = [
        'Daily' => 'daily',
        'Weekly' => 'weekly',
        'Monthly' => 'monthly',
        'Quarterly' => 'quarterly',
        'Semi-annual' => 'semiannual',
        'Annual' => 'annual',
        'Event-driven' => 'on_request',
    ];

    /** @var array<string, array<string, ControlFrequency>> tenant key => code => row */
    private array $byCode = [];

    /** @var array<string, array<string, ControlFrequency>> tenant key => normalised alias => row */
    private array $byAlias = [];

    // ── Resolution ───────────────────────────────────────────────────

    /**
     * A raw workbook string → the frequency it means, or null when the
     * string is blank (inherit) or unrecognised (the caller decides).
     */
    public function resolve(?string $raw, ?int $tenantId = null): ?ControlFrequency
    {
        $key = FrequencyAlias::normalise($raw);

        if ($key === '') {
            return null;
        }

        $aliases = $this->aliasMap($tenantId);

        if (isset($aliases[$key])) {
            return $aliases[$key];
        }

        // A code typed straight in ("semiannual") resolves too, so the
        // catalogue is usable without an alias row for every spelling.
        return $this->byCode($key, $tenantId);
    }

    /** Resolution that refuses to guess — the importer's path (§D.1 step 4). */
    public function resolveOrFail(?string $raw, ?int $tenantId = null): ControlFrequency
    {
        $frequency = $this->resolve($raw, $tenantId);

        if (! $frequency) {
            throw ValidationException::withMessages([
                'frequency' => sprintf('"%s" is not a frequency this system knows. Add an alias for it before importing.', trim((string) $raw)),
            ]);
        }

        return $frequency;
    }

    public function byCode(string $code, ?int $tenantId = null): ?ControlFrequency
    {
        return $this->codeMap($tenantId)[$code] ?? null;
    }

    /** The catalogue row behind a legacy controls.frequency enum value. */
    public function fromLegacy(?string $frequency, ?int $tenantId = null): ?ControlFrequency
    {
        $code = self::LEGACY_MAP[$frequency] ?? null;

        return $code ? $this->byCode($code, $tenantId) : null;
    }

    /** @return array<string, ControlFrequency> */
    public function catalogue(?int $tenantId = null): array
    {
        return $this->codeMap($tenantId);
    }

    public function flush(): void
    {
        $this->byCode = [];
        $this->byAlias = [];
    }

    // ── Periodisation ────────────────────────────────────────────────

    /**
     * @return array{label: string, start: CarbonImmutable, end: CarbonImmutable, due: ?CarbonImmutable}
     */
    public function period(ControlFrequency $frequency, CarbonImmutable $asOf): array
    {
        [$label, $start, $end] = $this->boundaries($frequency->cycle, $asOf);

        // A continuous rhythm has a reporting window but no deadline —
        // an observation task that never closes on a boundary must not
        // sit in the overdue queue (§C.5).
        $due = $frequency->isContinuous() ? null : $end->addDays($frequency->grace_days);

        return ['label' => $label, 'start' => $start, 'end' => $end, 'due' => $due];
    }

    /**
     * Period for a catalogue code, for callers that hold a code rather
     * than a row.
     *
     * @return array{label: string, start: CarbonImmutable, end: CarbonImmutable, due: ?CarbonImmutable}
     */
    public function periodFor(string $code, CarbonImmutable $asOf, ?int $tenantId = null): array
    {
        $frequency = $this->byCode($code, $tenantId);

        if ($frequency) {
            return $this->period($frequency, $asOf);
        }

        [$label, $start, $end] = $this->boundaries($code, $asOf);

        return ['label' => $label, 'start' => $start, 'end' => $end, 'due' => $end->addDays(5)];
    }

    /**
     * Raw cycle boundaries. Kept private-ish and label-stable: the strings
     * here are the same ones TestingService::periodFor() has produced
     * since Phase 3, and test_instances rows are keyed on them.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    public function boundaries(string $cycle, CarbonImmutable $asOf): array
    {
        return match ($cycle) {
            'daily' => [$asOf->format('Y-m-d'), $asOf->startOfDay(), $asOf->endOfDay()],
            'weekly' => ['W'.$asOf->format('W-Y'), $asOf->startOfWeek(), $asOf->endOfWeek()],
            'monthly' => [$asOf->format('M-Y'), $asOf->startOfMonth(), $asOf->endOfMonth()],
            'quarterly' => ['Q'.$asOf->quarter.'-'.$asOf->year, $asOf->startOfQuarter(), $asOf->endOfQuarter()],
            'semiannual' => [($asOf->month <= 6 ? 'H1' : 'H2').'-'.$asOf->year,
                $asOf->month <= 6 ? $asOf->startOfYear() : $asOf->setMonth(7)->startOfMonth(),
                $asOf->month <= 6 ? $asOf->setMonth(6)->endOfMonth() : $asOf->endOfYear()],
            'annual' => [(string) $asOf->year, $asOf->startOfYear(), $asOf->endOfYear()],
            // An observation rolls into a fresh instance every month so the
            // register has something to report on, but the officer records
            // against it continuously (§C.5).
            'continuous' => ['OBS-'.$asOf->format('M-Y'), $asOf->startOfMonth(), $asOf->endOfMonth()],
            // An event instance is stamped with the moment it was raised;
            // uniqueness comes from that, not from a calendar.
            'event' => ['EVT-'.$asOf->format('Y-m-d-His'), $asOf->startOfDay(), $asOf->endOfDay()],
            // Unknown cycles keep the historical fall-through rather than
            // throwing inside a nightly job. Resolution, not periodisation,
            // is where an unknown frequency is caught (resolveOrFail).
            default => [$asOf->format('M-Y'), $asOf->startOfMonth(), $asOf->endOfMonth()],
        };
    }

    // ── Catalogue loading ────────────────────────────────────────────

    /** @return array<string, ControlFrequency> */
    private function codeMap(?int $tenantId): array
    {
        $key = (string) ($tenantId ?? 0);

        if (isset($this->byCode[$key])) {
            return $this->byCode[$key];
        }

        $rows = ControlFrequency::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($q) => $q->orWhere('tenant_id', $tenantId)))
            // A tenant row loads after the platform row of the same code
            // and therefore shadows it.
            ->orderByRaw('tenant_id is null desc')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[$row->code] = $row;
        }

        return $this->byCode[$key] = $map;
    }

    /** @return array<string, ControlFrequency> */
    private function aliasMap(?int $tenantId): array
    {
        $key = (string) ($tenantId ?? 0);

        if (isset($this->byAlias[$key])) {
            return $this->byAlias[$key];
        }

        $codes = $this->codeMap($tenantId);

        $aliases = FrequencyAlias::query()
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($q) => $q->orWhere('tenant_id', $tenantId)))
            ->orderByRaw('tenant_id is null desc')
            ->get();

        $map = [];

        foreach ($aliases as $alias) {
            $frequency = $codes[$alias->frequency?->code] ?? $alias->frequency;

            if ($frequency && $frequency->is_active) {
                $map[$alias->normalised] = $frequency;
            }
        }

        return $this->byAlias[$key] = $map;
    }
}
