<?php

namespace Tests\e2e;

use App\Models\CompensatingControl;
use App\Models\ControlException;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\SpotCheck;
use Illuminate\Support\Facades\Storage;

/**
 * Harness self-check.
 *
 * This file tests the *test infrastructure*, not the product. It exists
 * because a fixture that is silently absent, or a clock helper that
 * silently does nothing, produces green tests that prove nothing — which
 * is worse than a red one. Run this first; if it fails, no result from
 * the rest of the suite should be trusted.
 */
class HarnessSelfCheckTest extends E2ETestCase
{
    // -----------------------------------------------------------------
    // Fixtures are present
    // -----------------------------------------------------------------

    public function test_every_role_account_resolves(): void
    {
        foreach (array_keys(self::ACCOUNTS) as $key) {
            $user = $this->account($key);
            $this->assertNotNull($user->id, "Account {$key} did not resolve.");
        }

        $this->assertFalse(
            (bool) $this->account('inactive')->is_active,
            'The deactivated fixture must actually be inactive, or TC-01-03 proves nothing.'
        );
    }

    public function test_spot_check_fixtures_cover_every_lifecycle_state(): void
    {
        $states = SpotCheck::withoutGlobalScopes()
            ->where('title', 'like', 'E2E%')
            ->pluck('status')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['Completed', 'Draft', 'In Progress', 'Report Issued'],
            $states,
            'TC-09 needs a spot check at every lifecycle state to test illegal transitions.'
        );

        $this->assertGreaterThan(0, Finding::withoutGlobalScopes()->count(), 'TC-09 needs findings.');
    }

    public function test_compensating_control_fixtures_include_an_expiring_temporary_one(): void
    {
        $expiring = CompensatingControl::withoutGlobalScopes()
            ->where('is_temporary', true)
            ->whereIn('status', ['Approved', 'Active'])
            ->whereDate('effective_to', '<', now())
            ->count();

        $this->assertGreaterThan(
            0,
            $expiring,
            'TC-11-04 needs a temporary compensating control already past its end date.'
        );
    }

    public function test_evidence_fixtures_cover_pii_legal_hold_and_expiry(): void
    {
        $this->assertGreaterThan(0, Evidence::withoutGlobalScopes()->where('contains_personal_data', true)->count(), 'TC-13-09 needs PII-classified evidence.');
        $this->assertGreaterThan(0, Evidence::withoutGlobalScopes()->where('legal_hold', true)->count(), 'TC-13 needs evidence under legal hold.');
        $this->assertGreaterThan(0, Evidence::withoutGlobalScopes()->whereDate('retention_expiry_date', '<', now())->count(), 'TC-13-08 needs expired evidence.');

        // The files must actually exist on disk, or download, checksum and
        // disposal tests are testing nothing.
        foreach (Evidence::withoutGlobalScopes()->where('file_name', 'like', 'e2e-%')->get() as $evidence) {
            $this->assertTrue(
                Storage::disk('local')->exists($evidence->storage_path),
                "Evidence fixture {$evidence->file_name} has no file at {$evidence->storage_path}."
            );

            $this->assertSame(
                $evidence->checksum,
                hash('sha256', Storage::disk('local')->get($evidence->storage_path)),
                "Evidence fixture {$evidence->file_name} checksum does not match its stored bytes."
            );
        }
    }

    public function test_second_tenant_exists_and_is_genuinely_separate(): void
    {
        $other = $this->otherTenantAccount('other_cfh');
        $ours = $this->account('cfh');

        $this->assertNotSame(
            $ours->tenant_id,
            $other->tenant_id,
            'The isolation fixture must belong to a different tenant, or TC-New-04 proves nothing.'
        );

        $this->assertNotSame($ours->tenant_id, $this->otherTenantControl()->tenant_id);
        $this->assertNotSame($ours->tenant_id, $this->otherTenantException()->tenant_id);
    }

    // -----------------------------------------------------------------
    // The clock helpers actually move the clock
    // -----------------------------------------------------------------

    public function test_at_helper_moves_and_restores_the_clock(): void
    {
        $realYear = now()->year;

        $seen = $this->at('2030-06-15 09:00:00', fn () => now()->toDateString());

        $this->assertSame('2030-06-15', $seen, 'The clock helper did not move the clock.');
        $this->assertSame($realYear, now()->year, 'The clock helper did not restore the clock.');
    }

    public function test_clock_is_restored_even_when_the_callback_throws(): void
    {
        $realYear = now()->year;

        try {
            $this->at('2030-06-15', function () {
                throw new \RuntimeException('deliberate');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(
            $realYear,
            now()->year,
            'A throwing callback leaked a frozen clock into the next test.'
        );
    }

    /**
     * The real proof: a scheduled command run through the harness must
     * observe the shifted clock and change data accordingly.
     *
     * Evidence under legal hold that is already past expiry must NOT be
     * queued for disposal (FR-9.7); evidence past expiry without a hold
     * must be (FR-9.8).
     */
    public function test_run_command_at_drives_a_real_scheduled_command(): void
    {
        $held = Evidence::withoutGlobalScopes()->where('file_name', 'e2e-legal-hold-memo.txt')->firstOrFail();
        $disposable = Evidence::withoutGlobalScopes()->where('file_name', 'e2e-expired-no-hold.txt')->firstOrFail();

        // Clear any prior queueing so the assertion is about this run.
        $held->forceFill(['disposal_requested_at' => null])->saveQuietly();
        $disposable->forceFill(['disposal_requested_at' => null])->saveQuietly();

        $this->runCommandAt('secondline:queue-evidence-disposal', now()->addYear());

        $this->assertNull(
            $held->refresh()->disposal_requested_at,
            'FR-9.7: evidence under legal hold must never be queued for disposal.'
        );

        $this->assertNotNull(
            $disposable->refresh()->disposal_requested_at,
            'FR-9.8: expired evidence without a legal hold must be queued for disposal. '
            .'If this is null, the clock helper is not reaching the command.'
        );
    }

    /**
     * Second proof, on a different command, so a pass cannot be an
     * accident of one command's implementation. Ageing must compute from
     * the shifted clock.
     */
    public function test_run_command_at_is_observed_by_the_ageing_sweep(): void
    {
        $exception = ControlException::withoutGlobalScopes()
            ->whereIn('status', ControlException::OPEN_STATUSES)
            ->firstOrFail();

        // The sweep processes every open exception, so the run date must
        // be after every seeded date_raised. Running it "in the past"
        // relative to the seed produces negative ages and aborts the whole
        // batch on an unsignedInteger column — see DEF-006, which this
        // test deliberately does not re-trigger.
        $exception->forceFill([
            'date_raised' => '2026-11-01',
            'target_closure_date' => '2026-11-30',
            'age_days' => 0,
            'is_overdue' => false,
        ])->saveQuietly();

        $this->runCommandAt('secondline:refresh-ageing', '2027-01-01 01:15:00');

        $exception->refresh();

        $this->assertSame(
            61,
            $exception->age_days,
            'Ageing must be computed from the shifted clock (2026-11-01 → 2027-01-01 = 61 days).'
        );

        $this->assertTrue(
            (bool) $exception->is_overdue,
            'An exception past its target closure date must be flagged overdue.'
        );
    }
}
