<?php

namespace Tests\Feature;

use App\Console\Commands\InstallAuditTriggers;
use App\Models\AuditTrail;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\SpeakUpCase;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ExceptionService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CR3 — the Activity Log as evidence: capture-once semantics, correct
 * diffs, secret redaction, verifier attribution, immutability and the
 * tamper-evidence hash chain.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('System Administrator');

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('Control Owner');
    }

    // ── A rename does not get to edit history ────────────────────────

    /**
     * CR-04 §B.1b renamed InvestigationCase to SpeakUpCase. The first
     * attempt at that migration UPDATEd audit_trails so the stored class
     * name matched — and the database refused, because audit_trails carries
     * BEFORE UPDATE / BEFORE DELETE triggers for exactly this reason.
     *
     * The trigger was right. Rewriting history so a class name reads more
     * tidily is the act an immutable audit trail exists to make impossible.
     * So rows keep the name they were written with, and the reconciliation
     * happens on the way out.
     */
    public function test_a_row_written_before_a_rename_keeps_the_name_it_was_written_with(): void
    {
        $this->legacyCaseEntry();

        $this->assertSame(
            'App\Models\InvestigationCase',
            AuditTrail::query()->latest('id')->value('entity_type'),
            'History says what it said on the day. Nothing rewrites it.',
        );
    }

    public function test_the_log_presents_a_renamed_class_under_its_current_name(): void
    {
        $this->legacyCaseEntry();

        $this->actingAs($this->admin)
            ->get(route('settings.activity-log'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'entries.data.0.subject_type',
                'SpeakUpCase',
            ));
    }

    public function test_filtering_by_the_current_name_finds_rows_stored_under_the_old_one(): void
    {
        $this->legacyCaseEntry();

        $this->actingAs($this->admin)
            ->get(route('settings.activity-log', ['entity_type' => 'App\Models\SpeakUpCase']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('entries.data', 1));
    }

    public function test_a_renamed_class_offers_one_filter_option_not_two(): void
    {
        $this->legacyCaseEntry();
        $this->legacyCaseEntry('App\Models\SpeakUpCase');

        $this->actingAs($this->admin)
            ->get(route('settings.activity-log'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $types = collect($page->toArray()['props']['options']['entity_types'])
                    ->pluck('value')
                    ->filter(fn ($v) => str_contains($v, 'Case'));

                $this->assertSame(
                    ['App\Models\SpeakUpCase'],
                    $types->values()->all(),
                    'One subject, one option — offering "Case" twice would give each half the rows.',
                );
            });
    }

    /** An audit row as it was written before the rename. Insert only. */
    private function legacyCaseEntry(string $type = 'App\Models\InvestigationCase'): AuditTrail
    {
        return AuditTrail::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->admin->id,
            'entity_type' => $type,
            'entity_id' => 1,
            'action' => 'viewed',
            'subject_label' => 'CASE-2026-001',
            'description' => 'Case file opened.',
        ]);
    }

    // ── Auth capture: exactly once, never the password ───────────────

    public function test_a_login_is_logged_exactly_once(): void
    {
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password']);

        $this->assertSame(1, AuditTrail::where('action', 'login')->count());

        $row = AuditTrail::where('action', 'login')->first();
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertSame($this->admin->email, $row->actor_email);
        $this->assertSame($this->admin->name, $row->actor_name);
    }

    public function test_a_failed_login_is_logged_exactly_once_and_never_stores_the_password(): void
    {
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'super-Secret-P@ss-123']);

        $this->assertSame(1, AuditTrail::where('action', 'login_failed')->count());
        $this->assertSame(0, AuditTrail::where('action', 'login')->count());

        $row = AuditTrail::where('action', 'login_failed')->first();
        $this->assertSame($this->admin->email, $row->actor_email);
        $this->assertStringNotContainsString(
            'super-Secret-P@ss-123',
            json_encode($row->getAttributes()),
        );
    }

    public function test_a_logout_is_logged(): void
    {
        $this->actingAs($this->admin)->post('/logout');

        $this->assertSame(1, AuditTrail::where('action', 'logout')->count());
    }

    // ── CRUD diffs and redaction ─────────────────────────────────────

    public function test_a_model_update_records_a_correct_before_after_diff(): void
    {
        $control = $this->makeControl();

        $control->update(['frequency' => 'Weekly']);

        $row = AuditTrail::where('action', 'updated')
            ->where('entity_type', Control::class)
            ->where('entity_id', $control->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Monthly', $row->before['frequency']);
        $this->assertSame('Weekly', $row->after['frequency']);
        $this->assertSame($control->title, $row->subject_label);
        $this->assertNotNull($row->description);
    }

    public function test_passwords_and_tokens_never_appear_in_audit_payloads(): void
    {
        $this->actingAs($this->admin);

        $this->admin->forceFill(['password' => bcrypt('brand-new-secret-99')])->save();
        $this->tenant->update(['licence_key' => 'LIC-VERY-SECRET-KEY-001']);

        foreach (AuditTrail::all() as $row) {
            $payload = json_encode([$row->before, $row->after]);
            $this->assertStringNotContainsString('brand-new-secret-99', $payload);
            $this->assertStringNotContainsString('LIC-VERY-SECRET-KEY-001', $payload);
        }

        $userRow = AuditTrail::where('entity_type', User::class)->where('action', 'updated')->first();
        if ($userRow && array_key_exists('password', $userRow->after ?? [])) {
            $this->assertSame('[redacted]', $userRow->after['password']);
        }

        $tenantRow = AuditTrail::where('entity_type', Tenant::class)->where('action', 'updated')->first();
        $this->assertNotNull($tenantRow);
        $this->assertSame('[redacted]', $tenantRow->after['licence_key']);
    }

    // ── Exception closure names the verifier ─────────────────────────

    public function test_an_exception_closure_records_the_verifier(): void
    {
        $cfh = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $cfh->assignRole('Control Function Head');

        $exception = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'EXC-9001',
            'source_type' => 'Manual',
            'title' => 'Unreconciled suspense account',
            'severity' => 'High',
            'owner_id' => $this->owner->id,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays(14)->toDateString(),
            'status' => 'Remediated',
        ]);

        app(ExceptionService::class)->verifyAndClose($exception, $cfh, [
            'verification_method' => 'Re-performance',
            'closure_notes' => 'Re-performed the reconciliation; balances agree.',
        ]);

        $row = AuditTrail::where('action', 'closed')
            ->where('entity_type', ControlException::class)
            ->where('entity_id', $exception->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($cfh->id, $row->after['verified_by']);
        $this->assertSame($cfh->name, $row->after['verified_by_name']);
        $this->assertSame('Re-performance', $row->after['verification_method']);
    }

    // ── One action, one record: the middleware fallback ──────────────

    public function test_an_uninstrumented_state_change_gets_a_fallback_row(): void
    {
        Route::middleware(['web', 'auth'])->post('/_test/uninstrumented', fn () => response('ok'));

        $this->actingAs($this->admin)->post('/_test/uninstrumented');

        $row = AuditTrail::where('action', 'request.post')->first();
        $this->assertNotNull($row);
        $this->assertSame('POST /_test/uninstrumented', $row->description);
        $this->assertSame('POST /_test/uninstrumented', $row->event_label);
        $this->assertSame(200, $row->status_code);
        $this->assertStringNotContainsString('generated::', (string) $row->event_label);
    }

    public function test_the_fallback_does_not_duplicate_a_domain_logged_request(): void
    {
        Route::middleware(['web', 'auth'])->post('/_test/instrumented', function () {
            Tenant::first()->auditAction('approved');

            return response('ok');
        });

        $this->actingAs($this->admin)->post('/_test/instrumented');

        $this->assertSame(1, AuditTrail::where('action', 'approved')->count());
        $this->assertSame(0, AuditTrail::where('action', 'request.post')->count());
    }

    public function test_get_requests_and_guests_are_never_logged_by_the_fallback(): void
    {
        $this->actingAs($this->admin)->get('/dashboard');
        $this->post('/_definitely/not/a/route');

        $this->assertSame(0, AuditTrail::where('action', 'like', 'request.%')->count());
    }

    // ── Filters ──────────────────────────────────────────────────────

    public function test_filters_return_correct_result_sets(): void
    {
        $this->seedRows();

        $this->actingAs($this->admin)
            ->get(route('settings.activity-log', ['event' => 'approved']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/ActivityLog/Index')
                ->has('entries.data', 1)
                ->where('entries.data.0.event', 'approved'));

        // All three rows carry the control's subject label snapshot.
        $this->actingAs($this->admin)
            ->get(route('settings.activity-log', ['search' => 'Suspense']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('entries.data', 3)
                ->where('entries.data.0.subject_label', 'Suspense review'));

        $this->actingAs($this->admin)
            ->get(route('settings.activity-log', ['search' => 'no-such-thing-anywhere']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('entries.data', 0));

        $this->actingAs($this->admin)
            ->get(route('settings.activity-log', ['entity_type' => Control::class]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('entries.data', 3));
    }

    public function test_a_non_permitted_role_receives_403(): void
    {
        $this->actingAs($this->owner)
            ->get(route('settings.activity-log'))
            ->assertForbidden();
    }

    public function test_the_old_admin_url_redirects_to_settings(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/audit-log')
            ->assertRedirect('/settings/activity-log');
    }

    // ── Immutability ─────────────────────────────────────────────────

    public function test_update_and_delete_on_a_log_row_are_rejected(): void
    {
        $this->seedRows();
        $row = AuditTrail::first();

        try {
            $row->update(['action' => 'tampered']);
            $this->fail('Update should have thrown.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $row->delete();
            $this->fail('Delete should have thrown.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Storage layer: the DEF-004 triggers block raw statements too.
        $this->expectException(QueryException::class);
        DB::table('audit_trails')->where('id', $row->id)->update(['action' => 'tampered']);
    }

    // ── Tamper-evidence hash chain ───────────────────────────────────

    public function test_the_hash_chain_verifies_and_detects_tampering(): void
    {
        $this->seedRows();

        $this->artisan('audit:verify-chain')->assertExitCode(0);

        // Simulate an attacker with raw DB access who bypasses the triggers.
        InstallAuditTriggers::drop();
        DB::table('audit_trails')->where('id', AuditTrail::first()->id)
            ->update(['description' => 'rewritten history']);
        InstallAuditTriggers::install();

        $this->artisan('audit:verify-chain')->assertExitCode(1);
    }

    public function test_rows_are_chained_to_each_other(): void
    {
        $this->seedRows();

        $rows = AuditTrail::orderBy('id')->get();
        $this->assertGreaterThan(1, $rows->count());

        foreach ($rows as $i => $row) {
            $this->assertNotNull($row->row_hash);
            if ($i > 0) {
                $this->assertSame($rows[$i - 1]->row_hash, $row->previous_hash);
            }
        }
    }

    // ── Anonymity: Speak Up rows carry no identity ───────────────────

    public function test_anonymous_case_rows_carry_no_actor_ip_or_device(): void
    {
        $this->actingAs($this->admin);

        $case = SpeakUpCase::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'case_ref' => 'CASE-2026-0001',
            'title' => 'Anonymous report',
            'confidentiality' => 'Restricted',
            'is_anonymous' => true,
            'received_at' => now(),
            'channel' => 'web',
            'status' => 'New',
        ]);

        $row = AuditTrail::where('entity_type', SpeakUpCase::class)
            ->where('entity_id', $case->id)->first();

        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
        $this->assertNull($row->actor_name);
        $this->assertNull($row->actor_email);
        $this->assertNull($row->ip_address);
        $this->assertNull($row->user_agent);
        $this->assertNull($row->device_name);
        $this->assertNull($row->url);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function makeControl(array $overrides = []): Control
    {
        return Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-'.fake()->unique()->numberBetween(100, 999),
            'title' => 'Suspense review',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
            'owner_id' => $this->owner->id,
            'created_by' => $this->admin->id,
            ...$overrides,
        ]);
    }

    private function seedRows(): void
    {
        $this->actingAs($this->admin);

        $control = $this->makeControl();          // → created row
        $control->update(['frequency' => 'Weekly']); // → updated row
        $control->auditAction('approved');           // → approved row
    }
}
