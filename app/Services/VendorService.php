<?php

namespace App\Services;

use App\Models\ControlException;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorContract;
use App\Models\VendorFinding;
use App\Models\VendorScreening;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Third-party risk management (17.2).
 *
 * Two rules do most of the work here. First, an assessment is approved by
 * somebody other than the person who ran it — a due-diligence conclusion
 * is exactly the kind of judgement a single person should not be able to
 * both form and bless (R2). Second, an approved assessment has an expiry,
 * and when it lapses the platform raises an exception against the
 * relationship rather than quietly showing a stale green tick.
 *
 * Concentration is computed in a single base currency through the FX
 * reference table, so a group with Ghanaian and Kenyan subsidiaries gets
 * one comparable number rather than three incomparable ones (R7).
 */
class VendorService
{
    /** Default base for concentration when a tenant has not set one. */
    public const DEFAULT_BASE_CURRENCY = 'NGN';

    /**
     * A vendor is "concentrated" once it carries this share of the base.
     * A default, not a rule: tenants override it in settings, because what
     * counts as concentration in a five-vendor estate is not what counts
     * in a five-hundred-vendor one (R1).
     */
    public const DEFAULT_CONCENTRATION_THRESHOLD = 20;

    public function __construct(private LinkageService $linkage) {}

    // ── Assessments ──────────────────────────────────────────────────

    /**
     * Submit a completed due-diligence assessment. It does not become the
     * vendor's current position until a second person approves it.
     */
    public function submitAssessment(VendorAssessment $assessment, User $assessor, array $data = []): VendorAssessment
    {
        if (! in_array($assessment->status, ['Draft', 'In Progress', 'Rejected'], true)) {
            throw ValidationException::withMessages([
                'status' => "An assessment in status {$assessment->status} cannot be submitted.",
            ]);
        }

        $assessment->update([
            'risk_score' => $data['risk_score'] ?? $assessment->risk_score,
            'risk_band' => $data['risk_band'] ?? $assessment->risk_band,
            'conclusion' => $data['conclusion'] ?? $assessment->conclusion,
            'assessed_by' => $assessor->id,
            'assessed_at' => now(),
            'status' => 'Submitted',
        ]);

        $assessment->auditAction('submitted', null, [
            'risk_score' => $assessment->risk_score,
            'risk_band' => $assessment->risk_band,
            'by' => $assessor->id,
        ]);

        return $assessment->fresh();
    }

    /**
     * Approve a submitted assessment. The approver may not be the assessor
     * — checked here as well as in the policy, because the service is the
     * layer a scheduled command or an import would come through and the
     * rule must hold there too (R2, no admin bypass).
     */
    public function approveAssessment(VendorAssessment $assessment, User $approver): VendorAssessment
    {
        if ($assessment->status !== 'Submitted') {
            throw ValidationException::withMessages([
                'status' => 'Only a submitted assessment can be approved.',
            ]);
        }

        if ($assessment->assessed_by === $approver->id) {
            throw ValidationException::withMessages([
                'approved_by' => 'The person who ran the due diligence cannot approve its conclusion.',
            ]);
        }

        $months = (int) ($assessment->review_frequency_months ?: $assessment->vendor?->review_frequency_months ?: 12);

        $assessment->update([
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'status' => 'Approved',
            'review_frequency_months' => $months,
            'expires_at' => CarbonImmutable::now()->addMonths($months)->toDateString(),
            'review_raised_at' => null,
            'review_exception_id' => null,
        ]);

        $assessment->auditAction('approved', ['status' => 'Submitted'], [
            'status' => 'Approved',
            'by' => $approver->id,
            'expires_at' => $assessment->fresh()->expires_at?->toDateString(),
        ]);

        // The vendor's own criticality follows its latest approved risk
        // band, so the register and the assessment cannot disagree.
        if ($assessment->risk_band) {
            $assessment->vendor?->update(['criticality' => $assessment->risk_band, 'status' => 'Active']);
        }

        return $assessment->fresh();
    }

    public function rejectAssessment(VendorAssessment $assessment, User $approver, string $reason): VendorAssessment
    {
        if ($assessment->status !== 'Submitted') {
            throw ValidationException::withMessages([
                'status' => 'Only a submitted assessment can be rejected.',
            ]);
        }

        if ($assessment->assessed_by === $approver->id) {
            throw ValidationException::withMessages([
                'approved_by' => 'The person who ran the due diligence cannot rule on it.',
            ]);
        }

        $assessment->update(['status' => 'Rejected', 'conclusion' => $reason]);
        $assessment->auditAction('rejected', null, ['by' => $approver->id, 'reason' => $reason]);

        return $assessment->fresh();
    }

    /**
     * The nightly sweep: every approved assessment past its review date is
     * marked Expired, its vendor moves to Under Review, and an exception is
     * raised so the lapse sits in the same queue as every other overdue
     * control failure rather than in a report nobody opens.
     *
     * @return array{expired: int, raised: int}
     */
    public function expireAssessments(?int $tenantId = null, ?string $asOf = null): array
    {
        $expired = 0;
        $raised = 0;

        VendorAssessment::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('deleted_at')
            ->expired($asOf)
            ->with('vendor')
            ->each(function (VendorAssessment $assessment) use (&$expired, &$raised) {
                $expired++;

                $assessment->update(['status' => 'Expired']);
                $assessment->vendor?->update(['status' => 'Under Review']);

                if ($assessment->review_raised_at) {
                    return;
                }

                $exception = $this->raiseReviewObligation($assessment);
                $raised++;

                $assessment->update([
                    'review_raised_at' => now(),
                    'review_exception_id' => $exception?->id,
                ]);
            });

        return ['expired' => $expired, 'raised' => $raised];
    }

    /**
     * A lapsed review is an exception against the relationship. It carries
     * the vendor's criticality into its severity, so a lapsed review on a
     * critical outsourcing does not queue behind a stationery supplier.
     */
    private function raiseReviewObligation(VendorAssessment $assessment): ?ControlException
    {
        $vendor = $assessment->vendor;

        if (! $vendor) {
            return null;
        }

        $severity = match ($vendor->criticality) {
            'Critical' => 'Critical',
            'High' => 'High',
            'Low' => 'Low',
            default => 'Medium',
        };

        try {
            $exception = ControlException::create([
                'tenant_id' => $vendor->tenant_id,
                'reference' => ControlException::nextReference('EXC'),
                'source_type' => 'Third Party',
                'source_id' => $assessment->id,
                'title' => "Due diligence lapsed — {$vendor->legal_name}",
                'description' => "Assessment {$assessment->reference} was approved on "
                    .($assessment->approved_at?->toDateString() ?? 'an unrecorded date')
                    ." with a {$assessment->review_frequency_months}-month review cycle and expired on "
                    .($assessment->expires_at?->toDateString() ?? 'its review date')
                    .". The relationship is {$vendor->criticality} criticality"
                    .($vendor->touchesPersonalData() ? ' and the vendor processes personal data.' : '.'),
                'severity' => $severity,
                'owner_id' => $vendor->owner_id,
                'raised_by' => $vendor->owner_id,
                'date_raised' => now()->toDateString(),
                'target_closure_date' => now()->addDays(match ($severity) {
                    'Critical' => 14,
                    'High' => 30,
                    default => 60,
                })->toDateString(),
                'status' => $vendor->owner_id ? 'Assigned' : 'Open',
                'responsible_party_id' => $vendor->owner_id,
            ]);

            $this->linkage->link(
                'vendor', $vendor->id, 'exception', $exception->id,
                'relates_to', null, "Due-diligence review lapsed ({$assessment->reference})."
            );

            return $exception;
        } catch (\Throwable $e) {
            // An audit or linkage failure must never stop the expiry sweep
            // from marking the assessment stale (R3).
            Log::error('Vendor review obligation could not be raised', [
                'assessment' => $assessment->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // ── Screening ────────────────────────────────────────────────────

    /**
     * Record a screening result. A hit is never auto-cleared: the record
     * lands with `cleared_at` null and shows in the outstanding queue until
     * a named human dispositions it. Where an AI summary was produced it is
     * attached with the interaction it came from, as a draft (R4).
     */
    public function recordScreening(Vendor $vendor, array $data): VendorScreening
    {
        $hits = $data['hits'] ?? [];

        $screening = VendorScreening::create([
            'tenant_id' => $vendor->tenant_id,
            'vendor_id' => $vendor->id,
            'data_source_id' => $data['data_source_id'] ?? null,
            'screening_type' => $data['screening_type'],
            'screened_at' => $data['screened_at'] ?? now(),
            'result' => $data['result'],
            'hit_count' => is_array($hits) ? count($hits) : 0,
            'hits' => $hits ?: null,
            'summary' => $data['summary'] ?? null,
            'ai_interaction_id' => $data['ai_interaction_id'] ?? null,
        ]);

        $vendor->auditAction('screened', null, [
            'type' => $screening->screening_type,
            'result' => $screening->result,
            'hits' => $screening->hit_count,
        ]);

        return $screening;
    }

    /** A human's decision on a screening hit. */
    public function dispositionScreening(VendorScreening $screening, User $user, array $data): VendorScreening
    {
        $screening->update([
            'cleared_by' => $user->id,
            'cleared_at' => now(),
            'disposition' => $data['disposition'],
        ]);

        // A hit that was not dismissed becomes a finding to be worked.
        if (($data['raise_finding'] ?? false) && $screening->vendor) {
            $finding = $this->raiseFinding($screening->vendor, [
                'title' => ucfirst(str_replace('_', ' ', $screening->screening_type)).' screening match',
                'description' => $data['disposition'],
                'severity' => $data['severity'] ?? 'High',
                'owner_id' => $screening->vendor->owner_id,
                'due_date' => now()->addDays(30)->toDateString(),
            ], $user);

            $screening->update(['finding_id' => $finding->id]);
        }

        $screening->auditAction('dispositioned', null, [
            'by' => $user->id,
            'result' => $screening->result,
        ]);

        return $screening->fresh();
    }

    public function raiseFinding(Vendor $vendor, array $data, User $user, ?VendorAssessment $assessment = null): VendorFinding
    {
        return VendorFinding::create([
            'tenant_id' => $vendor->tenant_id,
            'vendor_id' => $vendor->id,
            'assessment_id' => $assessment?->id ?? ($data['assessment_id'] ?? null),
            'reference' => VendorFinding::nextReference('TPF'),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'severity' => $data['severity'] ?? 'Medium',
            'status' => 'Open',
            'owner_id' => $data['owner_id'] ?? $vendor->owner_id,
            'due_date' => $data['due_date'] ?? null,
            'raised_by' => $user->id,
        ]);
    }

    // ── Concentration ────────────────────────────────────────────────

    /**
     * Concentration risk (17.2): exposure by vendor, by service category
     * and by jurisdiction, across every entity, in one base currency.
     *
     * A vendor whose spend is in a currency with no reference rate is
     * reported in an `unconverted` bucket rather than silently dropped or
     * silently added at 1:1 — a concentration number that quietly excluded
     * a subsidiary would be worse than no number.
     *
     * @return array<string, mixed>
     */
    public function concentration(?int $tenantId = null, ?string $baseCurrency = null, ?int $entityId = null): array
    {
        $tenantId ??= auth()->user()?->tenant_id;
        $base = strtoupper($baseCurrency ?: $this->baseCurrency($tenantId));

        $vendors = Vendor::query()
            ->active()
            ->when($entityId, fn ($q, $e) => $q->where('entity_id', $e))
            ->with('entity:id,legal_name')
            ->get();

        $total = Money::zero($base);
        $unconverted = [];
        $rows = [];

        foreach ($vendors as $vendor) {
            $spend = $vendor->annualSpendMoney();

            if ($spend === null) {
                $converted = Money::zero($base);
            } else {
                $rate = ExchangeRate::resolve($spend->currency, $base, null, $tenantId);

                if ($rate === null) {
                    $unconverted[] = [
                        'vendor' => $vendor->legal_name,
                        'currency' => $spend->currency,
                        'amount' => $spend->toDecimal(),
                    ];

                    continue;
                }

                $converted = $spend->convert($base, $rate);
            }

            $total = $total->add($converted);

            $rows[] = [
                'id' => $vendor->id,
                'reference' => $vendor->reference,
                'name' => $vendor->legal_name,
                'category' => $vendor->category ?: 'Uncategorised',
                'criticality' => $vendor->criticality,
                'jurisdiction' => $vendor->jurisdiction,
                'processing_country' => $vendor->processing_country ?: $vendor->jurisdiction,
                'entity' => $vendor->entity?->legal_name,
                'spend_minor' => $converted->amount,
                'spend' => $converted->toDecimal(),
            ];
        }

        $threshold = $this->concentrationThreshold($tenantId);

        $withShare = collect($rows)
            ->map(fn (array $row) => [
                ...$row,
                'share' => $total->amount > 0 ? round($row['spend_minor'] / $total->amount * 100, 1) : 0.0,
            ])
            ->sortByDesc('spend_minor')
            ->values();

        return [
            'base_currency' => $base,
            'total' => $total->jsonSerialize(),
            'threshold' => $threshold,
            'by_vendor' => $withShare->all(),
            'by_category' => $this->groupShare($withShare, 'category', $total),
            'by_jurisdiction' => $this->groupShare($withShare, 'processing_country', $total),
            'breaches' => $withShare->filter(fn (array $row) => $row['share'] >= $threshold)->values()->all(),
            'unconverted' => $unconverted,
            'vendor_count' => $withShare->count(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function groupShare(Collection $rows, string $key, Money $total): array
    {
        return $rows
            ->groupBy($key)
            ->map(fn ($group, $label) => [
                'label' => (string) $label,
                'vendors' => $group->count(),
                'spend_minor' => $group->sum('spend_minor'),
                'spend' => Money::of((int) $group->sum('spend_minor'), $total->currency)->toDecimal(),
                'share' => $total->amount > 0
                    ? round($group->sum('spend_minor') / $total->amount * 100, 1)
                    : 0.0,
                'critical' => $group->whereIn('criticality', ['Critical', 'High'])->count(),
            ])
            ->sortByDesc('spend_minor')
            ->values()
            ->all();
    }

    public function baseCurrency(?int $tenantId): string
    {
        $settings = $tenantId
            ? (Tenant::withoutGlobalScopes()->find($tenantId)?->settings['vendors'] ?? [])
            : [];

        return $settings['base_currency'] ?? self::DEFAULT_BASE_CURRENCY;
    }

    public function concentrationThreshold(?int $tenantId): int
    {
        $settings = $tenantId
            ? (Tenant::withoutGlobalScopes()->find($tenantId)?->settings['vendors'] ?? [])
            : [];

        return (int) ($settings['concentration_threshold'] ?? self::DEFAULT_CONCENTRATION_THRESHOLD);
    }

    // ── Contracts ────────────────────────────────────────────────────

    /**
     * Contracts inside their renewal-notice window, and the ones that have
     * already slipped past it. Both matter: a contract past its notice date
     * has effectively auto-renewed whether anyone decided to or not.
     *
     * @return array{alerted: int, expired: int}
     */
    public function refreshContracts(?int $tenantId = null): array
    {
        $alerted = 0;
        $expired = 0;

        VendorContract::withoutGlobalScopes()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNull('deleted_at')
            ->whereIn('status', ['Active', 'Expiring'])
            ->whereNotNull('end_date')
            ->each(function (VendorContract $contract) use (&$alerted, &$expired) {
                $days = $contract->daysToExpiry();

                if ($days !== null && $days < 0) {
                    $contract->update(['status' => 'Expired']);
                    $expired++;

                    return;
                }

                if ($days !== null && $days <= (int) $contract->renewal_notice_days) {
                    if ($contract->status !== 'Expiring') {
                        $contract->update(['status' => 'Expiring', 'renewal_alerted_at' => now()]);
                        $contract->auditAction('renewal-window-entered', null, [
                            'days_to_expiry' => $days,
                            'notice_days' => (int) $contract->renewal_notice_days,
                        ]);
                        $alerted++;
                    }
                }
            });

        return ['alerted' => $alerted, 'expired' => $expired];
    }

    /** Headline numbers for the third-party index page. */
    public function stats(): array
    {
        return [
            'active' => Vendor::active()->count(),
            'critical' => Vendor::active()->critical()->count(),
            'expired_reviews' => VendorAssessment::expired()->count()
                + VendorAssessment::where('status', 'Expired')->count(),
            'due_reviews' => VendorAssessment::dueWithin(60)->count(),
            'open_findings' => VendorFinding::open()->count(),
            'outstanding_screenings' => VendorScreening::outstanding()->count(),
            'notifications_outstanding' => Vendor::active()
                ->where('regulator_notification_required', true)
                ->whereNull('regulator_notified_at')
                ->count(),
            'contracts_expiring' => VendorContract::inNoticeWindow()->count(),
        ];
    }
}
