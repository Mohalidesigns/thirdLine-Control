<?php

namespace Tests\e2e;

use App\Models\AuditTrail;
use App\Models\ControlException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * TC-17-01 … TC-17-05 and TC-New-03 — audit trail completeness and
 * immutability (FR-12.4, NFR-6).
 *
 * The plan rates a missing or falsifiable audit trail Critical, so the
 * negatives here are deliberately aggressive: not only "the model refuses"
 * but "no route exists that could" and "the storage layer itself is / is
 * not protected".
 */
class AuditTrailIntegrityTest extends E2ETestCase
{
    private function anyTrail(): AuditTrail
    {
        $trail = AuditTrail::query()->orderByDesc('id')->first();

        $this->assertNotNull($trail, 'Fixture missing: no audit records seeded.');

        return $trail;
    }

    // ---------------------------------------------------------------
    // TC-17-01 — state changes are logged with actor and before/after
    // ---------------------------------------------------------------

    public function test_tc_17_01_state_change_records_actor_action_and_before_after(): void
    {
        $exception = ControlException::withoutGlobalScopes()->open()->firstOrFail();
        $officer = $this->account('officer');

        $before = AuditTrail::where('entity_type', ControlException::class)
            ->where('entity_id', $exception->id)->count();

        $this->actingAs($officer)
            ->post(route('exceptions.comments', $exception), ['body' => 'E2E audit probe comment.'])
            ->assertRedirect();

        $exception->forceFill(['root_cause' => 'E2E audit probe — root cause set.'])->save();

        $trail = AuditTrail::where('entity_type', ControlException::class)
            ->where('entity_id', $exception->id)
            ->orderByDesc('id')
            ->first();

        $this->assertGreaterThan(
            $before,
            AuditTrail::where('entity_type', ControlException::class)->where('entity_id', $exception->id)->count(),
            'FR-12.4: an update must write an audit record.'
        );

        $this->assertSame('updated', $trail->action);
        $this->assertArrayHasKey('root_cause', $trail->after ?? [], 'FR-12.4: after-values must be captured.');
        $this->assertIsArray($trail->before, 'FR-12.4: before-values must be captured.');
        $this->assertNotNull($trail->created_at);
    }

    public function test_tc_17_01_http_originated_changes_capture_actor_and_ip(): void
    {
        // Must be a change to the audited model itself. The commentary
        // thread writes to exception_activities, which is not Auditable
        // (it carries its own attribution for FR-5.10), so assigning is
        // the cleanest HTTP-originated update on the exception row.
        $exception = ControlException::withoutGlobalScopes()->where('status', 'Open')->firstOrFail();
        $officer = $this->account('officer');
        $owner = $this->account('owner2');

        $highWater = AuditTrail::max('id');

        $this->actingAs($officer)
            ->post(route('exceptions.assign', $exception), ['user_id' => $owner->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $trail = AuditTrail::where('entity_type', ControlException::class)
            ->where('entity_id', $exception->id)
            ->where('id', '>', $highWater)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($trail, 'FR-12.4: an HTTP-originated update must write an audit record.');
        $this->assertSame($officer->id, $trail->user_id, 'FR-12.4: the actor must be attributed.');
        $this->assertNotNull($trail->ip_address, 'FR-12.4: IP address must be captured.');
        $this->assertNotNull($trail->user_agent, 'FR-12.4: user agent should be captured.');
    }

    // ---------------------------------------------------------------
    // TC-17-02 / TC-New-03 — immutability
    // ---------------------------------------------------------------

    public function test_tc_17_02_audit_record_cannot_be_updated_through_the_model(): void
    {
        $trail = $this->anyTrail();

        $this->expectException(\LogicException::class);

        $trail->update(['action' => 'tampered']);
    }

    public function test_tc_17_02_audit_record_cannot_be_deleted_through_the_model(): void
    {
        $trail = $this->anyTrail();

        $this->expectException(\LogicException::class);

        $trail->delete();
    }

    public function test_tc_17_02_no_route_exposes_audit_trail_mutation(): void
    {
        $mutating = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
            ->filter(fn ($route) => str_contains(strtolower($route->uri()), 'audit'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame(
            [],
            $mutating,
            'TC-17-02: a state-changing route exists on the audit trail: '.implode(', ', $mutating)
        );
    }

    /**
     * TC-New-03. Immutability is enforced in AuditTrail::booted(), which is
     * the Eloquent layer only. This test documents — with evidence rather
     * than assertion — whether the storage layer itself protects the table.
     *
     * A pass here means the DB rejects the write. A skip means it does not,
     * and the finding is recorded in the defect log rather than failing the
     * suite, because model-layer-only immutability may be an accepted
     * design position. It must not, however, be an unexamined one.
     */
    public function test_tc_new_03_query_builder_bypass_of_audit_immutability(): void
    {
        $trail = $this->anyTrail();
        $original = $trail->action;

        try {
            DB::table('audit_trails')->where('id', $trail->id)->update(['action' => 'tampered-by-query-builder']);
        } catch (\Throwable $e) {
            $this->assertTrue(true, 'Storage layer rejected the write: '.$e->getMessage());

            return;
        }

        $after = DB::table('audit_trails')->where('id', $trail->id)->value('action');

        // Restore before asserting, so a failure never leaves the row dirty.
        DB::table('audit_trails')->where('id', $trail->id)->update(['action' => $original]);

        $this->assertNotSame(
            'tampered-by-query-builder',
            $after,
            'TC-New-03: audit_trails is mutable via the query builder. Immutability is enforced only in '
            .'AuditTrail::booted(); there is no database-level protection (trigger, append-only grant, or '
            .'revoked UPDATE/DELETE). Any raw write, console command, or future query-builder call can '
            .'silently rewrite the audit trail. See defect log.'
        );
    }

    // ---------------------------------------------------------------
    // TC-17-04 — the examiner's view
    // ---------------------------------------------------------------

    public function test_tc_17_04_audit_log_is_readable_and_exportable_by_authorised_roles(): void
    {
        $this->actingAs($this->account('admin'))
            ->get(route('admin.audit-log'))
            ->assertOk();

        $this->actingAs($this->account('admin'))
            ->get(route('admin.audit-log.export'))
            ->assertOk();
    }

    public function test_tc_17_04_audit_log_is_denied_to_unauthorised_roles(): void
    {
        foreach (['officer', 'owner', 'manager', 'exec'] as $account) {
            $this->actingAs($this->account($account))
                ->get(route('admin.audit-log'))
                ->assertForbidden();

            $this->actingAs($this->account($account))
                ->get(route('admin.audit-log.export'))
                ->assertForbidden();
        }
    }

    // ---------------------------------------------------------------
    // TC-17-05 — denied actions must be recorded, not silently dropped
    // ---------------------------------------------------------------

    public function test_tc_17_05_authorisation_failures_are_recorded(): void
    {
        $exception = ControlException::withoutGlobalScopes()
            ->where('status', 'Remediated')->first()
            ?? ControlException::withoutGlobalScopes()->open()->firstOrFail();

        $before = AuditTrail::count();

        $this->actingAs($this->account('owner'))
            ->post(route('exceptions.close', $exception), [
                'verification_method' => 'Unauthorised attempt.',
            ])
            ->assertForbidden();

        $after = AuditTrail::count();

        $this->assertGreaterThan(
            $before,
            $after,
            'TC-17-05 / FR-12.4: a denied attempt to close an exception must leave a record. '
            .'An examiner asking "did anyone try to close this outside the control function?" '
            .'currently has no way to answer from the application.'
        );
    }
}
