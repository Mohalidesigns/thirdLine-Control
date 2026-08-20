<?php

namespace Tests\e2e;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\E2ETestSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Base class for the end-to-end QA suite.
 *
 * Unlike the product suite (in-memory SQLite, see phpunit.xml), this suite
 * runs against the **real MariaDB/MySQL e2e database**, seeded once, with
 * each test wrapped in a transaction and rolled back. That matters: every
 * status field in this schema is a MySQL `enum`, and SQLite does not
 * enforce enum membership. A test that proves an illegal status write is
 * rejected proves nothing unless the storage engine is the production one.
 *
 * Run with:
 *   ./vendor/bin/phpunit -c phpunit-e2e.xml
 *
 * Prerequisites (see docs/testing/01-test-execution-log.md §Environment):
 *   DB_DATABASE=atheris_control_e2e php artisan migrate:fresh --seed --force
 *   DB_DATABASE=atheris_control_e2e php artisan db:seed --class=E2ETestSeeder --force
 */
abstract class E2ETestCase extends TestCase
{
    use DatabaseTransactions;

    /** Seeded demo accounts, one per role. Password is 'password'. */
    public const ACCOUNTS = [
        'admin' => 'admin@secondline.test',
        'cfh' => 'cfh@secondline.test',
        'officer' => 'officer1@secondline.test',
        'officer2' => 'officer2@secondline.test',
        'owner' => 'owner1@secondline.test',
        'owner2' => 'owner2@secondline.test',
        'manager' => 'manager@secondline.test',
        'exec' => 'exec@secondline.test',
        'inactive' => 'inactive@secondline.test',
    ];

    /**
     * Resolve a seeded user by short key. Fails the test loudly rather
     * than returning null — a missing fixture is a broken run, not a
     * failing assertion.
     */
    protected function account(string $key): User
    {
        $email = self::ACCOUNTS[$key] ?? $key;

        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        $this->assertNotNull(
            $user,
            "Fixture missing: no user with email {$email}. Re-run the e2e seed."
        );

        return $user;
    }

    /**
     * Every role except the ones named. Used to drive "restricted action
     * as an unauthorised role" matrices without hand-listing the negatives
     * each time, so a role added later is negative-tested by default.
     */
    protected function allAccountsExcept(string ...$except): array
    {
        return array_values(array_diff(
            array_keys(array_diff_key(self::ACCOUNTS, ['inactive' => null])),
            $except
        ));
    }

    // -----------------------------------------------------------------
    // Second tenant — isolation and IDOR fixtures
    // -----------------------------------------------------------------

    /** A user belonging to the *other* tenant. */
    protected function otherTenantAccount(string $key = 'other_cfh'): User
    {
        $email = E2ETestSeeder::OTHER_TENANT_ACCOUNTS[$key] ?? $key;

        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        $this->assertNotNull(
            $user,
            "Fixture missing: no user with email {$email}. Re-run the e2e seed (E2ETestSeeder)."
        );

        return $user;
    }

    /** The other tenant's control — must never be reachable from tenant 1. */
    protected function otherTenantControl(): Control
    {
        $control = Control::withoutGlobalScopes()->where('control_ref', 'RB-CTRL-001')->first();

        $this->assertNotNull($control, 'Fixture missing: other-tenant control. Re-run the e2e seed.');

        return $control;
    }

    /** The other tenant's exception — must never be reachable from tenant 1. */
    protected function otherTenantException(): ControlException
    {
        $exception = ControlException::withoutGlobalScopes()->where('reference', 'RB-EXC-001')->first();

        $this->assertNotNull($exception, 'Fixture missing: other-tenant exception. Re-run the e2e seed.');

        return $exception;
    }

    // -----------------------------------------------------------------
    // Clock control
    //
    // Escalation, ageing, retention expiry and compensating-control
    // expiry are all daily batch commands, not event-driven. None of them
    // can be tested by waiting. These helpers move the clock, run the
    // command at that moment, and put the clock back — so a test reads as
    // "on this date, this command did this".
    // -----------------------------------------------------------------

    /**
     * Run an artisan command as if it were $when, then restore the clock.
     * Returns the command's exit code.
     *
     * The clock is restored in a finally block so a failing assertion
     * inside $before never leaks a frozen clock into the next test.
     */
    protected function runCommandAt(string $command, CarbonImmutable|string $when, array $parameters = []): int
    {
        $at = $when instanceof CarbonImmutable ? $when : CarbonImmutable::parse($when);

        $this->travelTo($at);

        try {
            return Artisan::call($command, $parameters);
        } finally {
            $this->travelBack();
        }
    }

    /**
     * Run a closure with the clock frozen at $when. Use for multi-step
     * scenarios — "on day 15 the owner remediates, on day 20 the control
     * function verifies" — where a single command call is not enough.
     */
    protected function at(CarbonImmutable|string $when, callable $callback): mixed
    {
        $at = $when instanceof CarbonImmutable ? $when : CarbonImmutable::parse($when);

        $this->travelTo($at);

        try {
            return $callback($at);
        } finally {
            $this->travelBack();
        }
    }

    /** Convenience: the seeded tenant's operating timezone is Africa/Lagos. */
    protected function lagos(string $expression): CarbonImmutable
    {
        return CarbonImmutable::parse($expression, 'Africa/Lagos');
    }

    /**
     * Push an exception's target closure date into the past so overdue
     * and escalation behaviour has a subject. Writes quietly so the
     * fixture setup does not itself pollute the audit trail under test.
     */
    protected function makeOverdueByDays(ControlException $exception, int $days): ControlException
    {
        $exception->forceFill([
            'target_closure_date' => now()->subDays($days)->toDateString(),
            'date_raised' => now()->subDays($days + 14)->toDateString(),
        ])->saveQuietly();

        return $exception->refresh();
    }
}
