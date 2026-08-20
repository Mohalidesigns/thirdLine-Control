<?php

namespace Tests\e2e;

use App\Models\AuditTrail;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\TestInstance;
use App\Services\ExceptionService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TC-10-04 … TC-10-10, TC-10-16 — the exception closure control.
 *
 * BRD REQ-001 / FR-5.4 / FR-5.5: only the control function may close an
 * exception, and only after verifying remediation.
 *
 * Per system-map finding R-01 (settled 2026-08-14), "the control function"
 * is the **Control Function Head** role. Control Officer is therefore in
 * the unauthorised set, not the authorised one as the original Part B §3
 * role matrix stated.
 *
 * Every negative is asserted by calling the endpoint directly with a valid
 * session for the wrong role — a hidden button is not authorisation — and
 * by re-reading the row from the database afterwards to prove no state
 * change occurred.
 */
class ExceptionClosureControlTest extends E2ETestCase
{
    /** A control whose owner is nobody involved in the closure test. */
    private function fixtureControl(): Control
    {
        $control = Control::withoutGlobalScopes()->where('status', 'Active')->firstOrFail();

        // Own it by a control owner, so the "owner cannot close" rule has
        // a subject that is not the verifier.
        $control->forceFill(['owner_id' => $this->account('owner')->id])->saveQuietly();

        return $control->refresh();
    }

    /**
     * An exception sitting at $status, owned by owner1, on a control owned
     * by owner1, raised manually (so the source-tester rule is inert).
     */
    private function exceptionAt(string $status): ControlException
    {
        $control = $this->fixtureControl();
        $owner = $this->account('owner');

        return ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $control->tenant_id,
            'reference' => ControlException::nextReference('EXC'),
            'source_type' => 'Manual',
            'control_id' => $control->id,
            'title' => 'E2E fixture — closure control',
            'description' => 'Synthetic test fixture.',
            'severity' => 'High',
            'owner_id' => $owner->id,
            'responsible_party_id' => $owner->id,
            'unit_id' => $control->unit_id,
            'raised_by' => $this->account('officer')->id,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays(14)->toDateString(),
            'status' => $status,
        ]);
    }

    private function closePayload(): array
    {
        return [
            'verification_method' => 'Re-performed the reconciliation for the period and inspected sign-off.',
            'closure_notes' => 'Remediation evidence verified.',
        ];
    }

    // ---------------------------------------------------------------
    // TC-10-04 — owner remediation stops at Remediated
    // ---------------------------------------------------------------

    public function test_tc_10_04_owner_remediation_moves_to_remediated_not_closed(): void
    {
        $exception = $this->exceptionAt('Assigned');

        $this->actingAs($this->account('owner'))
            ->post(route('exceptions.remediated', $exception), ['note' => 'Reconciliation rebuilt and signed off.'])
            ->assertRedirect();

        $exception->refresh();

        $this->assertSame('Remediated', $exception->status, 'Owner remediation must land on Remediated.');
        $this->assertNotNull($exception->remediated_at);
        $this->assertSame($this->account('owner')->id, $exception->remediated_by);
        $this->assertNull($exception->verified_at, 'Remediation must not record verification.');
        $this->assertNull($exception->verified_by);
    }

    // ---------------------------------------------------------------
    // TC-10-05 / TC-10-06 / TC-10-09 — unauthorised roles cannot close,
    // via a direct endpoint call, with no state change in the database.
    // ---------------------------------------------------------------

    public static function unauthorisedCloserProvider(): array
    {
        return [
            'Control Owner (TC-10-05)' => ['owner'],
            'System Administrator (TC-10-06)' => ['admin'],
            'Control Officer (TC-10-05, per R-01)' => ['officer'],
            'Line Manager' => ['manager'],
            'Executive Viewer' => ['exec'],
        ];
    }

    #[DataProvider('unauthorisedCloserProvider')]
    public function test_tc_10_09_unauthorised_role_cannot_close_via_direct_endpoint(string $account): void
    {
        $exception = $this->exceptionAt('Remediated');

        $this->actingAs($this->account($account))
            ->post(route('exceptions.close', $exception), $this->closePayload())
            ->assertForbidden();

        $fresh = ControlException::withoutGlobalScopes()->findOrFail($exception->id);

        $this->assertSame('Remediated', $fresh->status, "{$account} must not change the status.");
        $this->assertNull($fresh->verified_by);
        $this->assertNull($fresh->verified_at);
        $this->assertNull($fresh->verification_method);
    }

    /**
     * The service is the second line of defence behind the policy. It must
     * refuse independently, so a future caller that skips the policy is
     * still stopped (system-map G-01).
     */
    #[DataProvider('unauthorisedCloserProvider')]
    public function test_tc_10_09_service_layer_refuses_unauthorised_closer(string $account): void
    {
        if ($account === 'owner') {
            // Covered separately by the owner-specific SoD test below.
            $this->markTestSkipped('Covered by test_owner_of_control_cannot_close.');
        }

        $exception = $this->exceptionAt('Remediated');

        $this->expectException(ValidationException::class);

        app(ExceptionService::class)->verifyAndClose(
            $exception,
            $this->account($account),
            $this->closePayload()
        );
    }

    // ---------------------------------------------------------------
    // TC-10-07 — closure requires verification first: an exception that
    // has not reached Remediated cannot be closed.
    // ---------------------------------------------------------------

    public static function preRemediationStatusProvider(): array
    {
        return [
            'Open' => ['Open'],
            'Assigned' => ['Assigned'],
            'In Progress' => ['In Progress'],
        ];
    }

    #[DataProvider('preRemediationStatusProvider')]
    public function test_tc_10_07_control_function_cannot_close_before_remediation(string $status): void
    {
        $exception = $this->exceptionAt($status);

        $this->actingAs($this->account('cfh'))
            ->post(route('exceptions.close', $exception), $this->closePayload())
            ->assertForbidden();

        $fresh = ControlException::withoutGlobalScopes()->findOrFail($exception->id);

        $this->assertSame($status, $fresh->status);
        $this->assertNull($fresh->verified_at);
    }

    // ---------------------------------------------------------------
    // TC-10-08 — the authorised path
    // ---------------------------------------------------------------

    public function test_tc_10_08_control_function_head_verifies_then_closes(): void
    {
        $exception = $this->exceptionAt('Remediated');
        $cfh = $this->account('cfh');

        $this->actingAs($cfh)
            ->post(route('exceptions.close', $exception), $this->closePayload())
            ->assertRedirect();

        $fresh = ControlException::withoutGlobalScopes()->findOrFail($exception->id);

        $this->assertSame('Verified-Closed', $fresh->status);
        $this->assertSame($cfh->id, $fresh->verified_by, 'FR-5.5: verifier must be recorded.');
        $this->assertNotNull($fresh->verified_at, 'FR-5.5: verification date must be recorded.');
        $this->assertSame(
            $this->closePayload()['verification_method'],
            $fresh->verification_method,
            'FR-5.5: verification method must be recorded.'
        );
        $this->assertFalse((bool) $fresh->is_overdue, 'Closure must clear the overdue flag.');
    }

    public function test_tc_10_08_closure_requires_a_verification_method(): void
    {
        $exception = $this->exceptionAt('Remediated');

        $this->actingAs($this->account('cfh'))
            ->post(route('exceptions.close', $exception), ['closure_notes' => 'No method given.'])
            ->assertSessionHasErrors('verification_method');

        $this->assertSame(
            'Remediated',
            ControlException::withoutGlobalScopes()->findOrFail($exception->id)->status
        );
    }

    // ---------------------------------------------------------------
    // FR-12.3 segregation of duties
    // ---------------------------------------------------------------

    public function test_control_function_head_cannot_close_an_exception_on_a_control_it_owns(): void
    {
        $exception = $this->exceptionAt('Remediated');
        $cfh = $this->account('cfh');

        // Make the CFH the owner of the failed control.
        Control::withoutGlobalScopes()->where('id', $exception->control_id)
            ->update(['owner_id' => $cfh->id]);

        $this->actingAs($cfh)
            ->post(route('exceptions.close', $exception), $this->closePayload())
            ->assertForbidden();

        $this->assertSame(
            'Remediated',
            ControlException::withoutGlobalScopes()->findOrFail($exception->id)->status
        );
    }

    public function test_control_function_head_cannot_close_an_exception_it_owns(): void
    {
        $exception = $this->exceptionAt('Remediated');
        $cfh = $this->account('cfh');

        $exception->forceFill(['owner_id' => $cfh->id])->saveQuietly();

        $this->actingAs($cfh)
            ->post(route('exceptions.close', $exception), $this->closePayload())
            ->assertForbidden();

        $this->assertSame(
            'Remediated',
            ControlException::withoutGlobalScopes()->findOrFail($exception->id)->status
        );
    }

    /**
     * System-map finding G-01: ExceptionPolicy::close() blocks the
     * exception's own owner, but ExceptionService::verifyAndClose() does
     * not re-check it. If the service refuses too, G-01 is closed as a
     * non-issue. If it does not, any caller that reaches the service
     * without the policy skips the guard.
     */
    public function test_g01_service_layer_also_refuses_when_verifier_owns_the_exception(): void
    {
        $exception = $this->exceptionAt('Remediated');
        $cfh = $this->account('cfh');

        $exception->forceFill(['owner_id' => $cfh->id])->saveQuietly();

        // The control is owned by owner1, so only the exception-owner rule
        // is in play here.
        try {
            app(ExceptionService::class)->verifyAndClose($exception, $cfh, $this->closePayload());
        } catch (ValidationException $e) {
            $this->assertSame(
                'Remediated',
                ControlException::withoutGlobalScopes()->findOrFail($exception->id)->status
            );

            return;
        }

        $this->fail(
            'G-01 CONFIRMED: ExceptionService::verifyAndClose() closed an exception owned by the verifier. '
            .'The guard exists only in ExceptionPolicy::close(); any caller bypassing the policy skips it.'
        );
    }

    public function test_tester_who_performed_the_source_test_cannot_close(): void
    {
        $exception = $this->exceptionAt('Remediated');
        $cfh = $this->account('cfh');

        $instance = TestInstance::withoutGlobalScopes()->first();
        $this->assertNotNull($instance, 'Fixture missing: no test instance seeded.');

        $instance->forceFill(['assigned_tester_id' => $cfh->id])->saveQuietly();
        $exception->forceFill([
            'source_type' => 'Test',
            'source_id' => $instance->id,
        ])->saveQuietly();

        $this->actingAs($cfh)
            ->post(route('exceptions.close', $exception), $this->closePayload())
            ->assertForbidden();

        $this->assertSame(
            'Remediated',
            ControlException::withoutGlobalScopes()->findOrFail($exception->id)->status
        );
    }

    // ---------------------------------------------------------------
    // TC-10-10 — terminal state
    // ---------------------------------------------------------------

    public function test_tc_10_10_verified_closed_is_terminal_and_cannot_be_reclosed_or_remediated(): void
    {
        $exception = $this->exceptionAt('Verified-Closed');

        $this->actingAs($this->account('cfh'))
            ->post(route('exceptions.close', $exception), $this->closePayload())
            ->assertForbidden();

        $this->actingAs($this->account('owner'))
            ->post(route('exceptions.remediated', $exception), ['note' => 'Trying to reopen by remediating.'])
            ->assertForbidden();

        $this->assertSame(
            'Verified-Closed',
            ControlException::withoutGlobalScopes()->findOrFail($exception->id)->status
        );
    }

    // ---------------------------------------------------------------
    // TC-10-13 — a closed exception leaves the open view but is retained
    // ---------------------------------------------------------------

    public function test_tc_10_13_closed_exception_is_retained_not_hard_deleted(): void
    {
        $exception = $this->exceptionAt('Remediated');

        $this->actingAs($this->account('cfh'))
            ->post(route('exceptions.close', $exception), $this->closePayload());

        $fresh = ControlException::withoutGlobalScopes()->find($exception->id);

        $this->assertNotNull($fresh, 'A closed exception must never be hard-deleted (NFR-6).');
        $this->assertNull($fresh->deleted_at, 'Closure is a status change, not a soft delete.');

        $this->assertNotContains(
            $exception->id,
            ControlException::withoutGlobalScopes()->open()->pluck('id')->all(),
            'A Verified-Closed exception must leave the open/unresolved set.'
        );
    }

    // ---------------------------------------------------------------
    // TC-10-16 — audit trail on the closure transition
    // ---------------------------------------------------------------

    public function test_tc_10_16_closure_writes_an_attributable_audit_record(): void
    {
        $exception = $this->exceptionAt('Remediated');
        $cfh = $this->account('cfh');

        $before = AuditTrail::where('entity_type', ControlException::class)
            ->where('entity_id', $exception->id)->count();

        $this->actingAs($cfh)->post(route('exceptions.close', $exception), $this->closePayload());

        $trail = AuditTrail::where('entity_type', ControlException::class)
            ->where('entity_id', $exception->id)
            ->orderByDesc('id')
            ->get();

        $this->assertGreaterThan($before, $trail->count(), 'FR-12.4: closure must be audit-logged.');

        $closure = $trail->firstWhere('action', 'closed');
        $this->assertNotNull($closure, 'FR-12.4: a "closed" audit action must exist.');
        $this->assertSame($cfh->id, $closure->user_id, 'FR-12.4: the actor must be attributed.');

        $activity = $exception->activities()
            ->where('activity_type', 'Verification')
            ->latest('id')->first();

        $this->assertNotNull($activity, 'FR-5.10: the commentary thread must record the verification.');
        $this->assertSame('Remediated', $activity->from_value);
        $this->assertSame('Verified-Closed', $activity->to_value);
    }

    // ---------------------------------------------------------------
    // TC-10-01 — the UI must not offer closure to those who lack it
    // (belt-and-braces: the endpoint tests above are the real control)
    // ---------------------------------------------------------------

    public function test_show_page_does_not_offer_closure_to_unauthorised_roles(): void
    {
        $exception = $this->exceptionAt('Remediated');

        foreach (['owner', 'admin', 'officer', 'manager', 'exec'] as $account) {
            $response = $this->actingAs($this->account($account))
                ->get(route('exceptions.show', $exception));

            if ($response->status() !== 200) {
                continue; // role cannot view at all — stronger than hiding the button
            }

            $can = $response->viewData('page')['props']['can'] ?? [];

            $this->assertArrayHasKey('close', $can, "No 'close' capability flag exposed for {$account}.");
            $this->assertFalse((bool) $can['close'], "UI offers closure to {$account}.");
        }
    }
}
