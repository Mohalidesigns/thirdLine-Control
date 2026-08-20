<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\InvestigationCase;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CaseService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The guarantees Phase 11.4 exists to keep: allowlist-gated access whose
 * only override is the named, read-only 'view all cases' oversight
 * permission (System Administrator alone), and anonymity that cannot be
 * undone. Oversight is sight, never reach: acting on a case still requires
 * membership, and every oversight view is logged.
 */
class CaseConfidentialityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $cfh;

    private User $officer;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->admin = $this->makeUser('admin@test.local', 'System Administrator');
        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->outsider = $this->makeUser('outsider@test.local', 'Control Officer');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{case: InvestigationCase, token: ?string} */
    private function openCase(bool $anonymous = false, ?User $reporter = null, array $overrides = []): array
    {
        return app(CaseService::class)->open([
            'case_type' => 'whistleblowing',
            'title' => 'Procurement awards concentrated on one vendor',
            'description' => 'Three consecutive contracts awarded without a competitive process.',
            'confidentiality' => 'Highly Restricted',
            'severity' => 'High',
            'channel' => 'web',
            'lead_investigator_id' => $this->cfh->id,
            'access_user_ids' => [$this->cfh->id, $this->officer->id],
            ...$overrides,
        ], $reporter, $this->tenant->id, $anonymous);
    }

    // ── The rule the phase brief names explicitly ────────────────────

    public function test_the_system_administrator_has_oversight_of_every_case(): void
    {
        $case = $this->openCase()['case'];

        $this->assertTrue(
            $this->admin->can('view all cases'),
            'Oversight is the named permission, not the role name.',
        );

        $this->actingAs($this->admin)
            ->get(route('cases.show', $case))
            ->assertOk();

        $this->assertTrue(
            AuditTrail::where('entity_type', (new InvestigationCase)->getMorphClass())
                ->where('entity_id', $case->id)
                ->where('action', 'viewed')
                ->exists(),
            'An oversight view is logged like any other access.',
        );
    }

    public function test_oversight_reaches_the_query_layer_but_only_for_the_permission_holder(): void
    {
        $this->openCase(overrides: [
            'lead_investigator_id' => $this->officer->id,
            'access_user_ids' => [$this->officer->id],
        ]);

        $this->actingAs($this->admin);
        $this->assertSame(1, InvestigationCase::count(),
            'The oversight permission opens the query layer.');

        // The Control Function Head holds every case permission except
        // oversight — the role name alone must widen nothing.
        $this->actingAs($this->cfh);
        $this->assertFalse($this->cfh->can('view all cases'));
        $this->assertSame(0, InvestigationCase::count(),
            'Without the oversight permission the allowlist scope still holds.');
    }

    public function test_oversight_is_read_only(): void
    {
        $case = $this->openCase()['case'];

        $this->actingAs($this->admin);

        $this->assertTrue($this->admin->can('view', $case));
        $this->assertFalse($this->admin->can('update', $case), 'Sight is not reach — acting needs membership.');
        $this->assertFalse($this->admin->can('investigate', $case));
        $this->assertFalse($this->admin->can('close', $case));
        $this->assertFalse($this->admin->can('manageAccess', $case));
        $this->assertFalse($this->admin->can('viewPrivileged', $case),
            'Privileged notes stay inside the allowlist even under oversight.');
    }

    public function test_a_control_officer_not_on_the_allowlist_gets_403(): void
    {
        $case = $this->openCase()['case'];

        $this->actingAs($this->outsider)
            ->get(route('cases.show', $case))
            ->assertForbidden();
    }

    public function test_someone_on_the_allowlist_can_open_the_case(): void
    {
        $case = $this->openCase()['case'];

        $this->actingAs($this->officer)
            ->get(route('cases.show', $case))
            ->assertOk();
    }

    public function test_the_index_lists_only_the_cases_the_viewer_is_on(): void
    {
        $this->openCase();
        $this->openCase(overrides: [
            'title' => 'A case the officer is not on',
            'lead_investigator_id' => $this->cfh->id,
            'access_user_ids' => [$this->cfh->id],
        ]);

        $this->actingAs($this->officer);
        $this->assertSame(1, InvestigationCase::count());

        $this->actingAs($this->cfh);
        $this->assertSame(2, InvestigationCase::count());
    }

    public function test_access_can_only_be_granted_by_someone_already_on_the_case(): void
    {
        $case = $this->openCase()['case'];

        $this->actingAs($this->admin);

        $this->expectException(ValidationException::class);
        app(CaseService::class)->grantAccess($case, $this->admin, $this->admin);
    }

    public function test_granting_access_is_logged_with_both_names(): void
    {
        $case = $this->openCase()['case'];

        $this->actingAs($this->cfh);
        app(CaseService::class)->grantAccess($case, $this->outsider, $this->cfh);

        $this->assertTrue($case->fresh()->grantsAccessTo($this->outsider));

        $row = AuditTrail::where('entity_type', (new InvestigationCase)->getMorphClass())
            ->where('entity_id', $case->id)
            ->where('action', 'access-granted')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($this->outsider->id, $row->after['grantee']);
        $this->assertSame($this->cfh->id, $row->after['granted_by']);
    }

    public function test_opening_a_case_file_is_itself_logged(): void
    {
        $case = $this->openCase()['case'];

        $this->actingAs($this->officer)->get(route('cases.show', $case))->assertOk();

        $this->assertTrue(
            AuditTrail::where('entity_type', (new InvestigationCase)->getMorphClass())
                ->where('entity_id', $case->id)
                ->where('action', 'viewed')
                ->exists(),
            'Every access is logged, not just every change.',
        );
    }

    public function test_the_lead_investigator_cannot_be_removed_from_the_allowlist(): void
    {
        $case = $this->openCase()['case'];

        $this->actingAs($this->cfh);

        $this->expectException(ValidationException::class);
        app(CaseService::class)->revokeAccess($case, $this->cfh, $this->cfh);
    }

    // ── Anonymity ────────────────────────────────────────────────────

    public function test_an_anonymous_case_stores_no_reporter_identity(): void
    {
        $this->actingAs($this->officer);

        // The reporter is someone who would otherwise land on the allowlist
        // for a named case, so their absence from it is meaningful.
        $result = $this->openCase(anonymous: true, reporter: $this->outsider);

        // Assert on the RAW ROW, not the model — nothing identifying at rest.
        $row = DB::table('cases')->where('id', $result['case']->id)->first();

        $this->assertNull($row->reporter_id, 'reporter_id must be null even when a session existed.');
        $this->assertNotNull($row->reporter_token_hash);
        $this->assertNotSame($result['token'], $row->reporter_token_hash, 'The token itself is never stored.');
        $this->assertSame(64, strlen($row->reporter_token_hash));
        $this->assertNotContains(
            $this->outsider->id,
            json_decode((string) $row->access_user_ids, true) ?: [],
            'An anonymous reporter is never added to the allowlist — there is no identity to add.',
        );
    }

    public function test_an_anonymous_case_audit_trail_carries_no_user_ip_or_agent(): void
    {
        $this->actingAs($this->officer);

        $result = $this->openCase(anonymous: true, reporter: $this->officer);

        $rows = AuditTrail::where('entity_type', (new InvestigationCase)->getMorphClass())
            ->where('entity_id', $result['case']->id)
            ->get();

        $this->assertNotEmpty($rows, 'The event itself is still audited (R3).');

        foreach ($rows as $row) {
            $this->assertNull($row->user_id, 'An anonymous case must never be attributed.');
            $this->assertNull($row->ip_address);
            $this->assertNull($row->user_agent);
        }
    }

    public function test_a_named_case_still_records_its_reporter(): void
    {
        $result = $this->openCase(anonymous: false, reporter: $this->officer);

        $this->assertSame($this->officer->id, $result['case']->reporter_id);
        $this->assertNull($result['token'], 'A named case needs no anonymous follow-up token.');
        $this->assertTrue($result['case']->grantsAccessTo($this->officer));
    }

    public function test_the_reporter_token_returns_status_without_identifying_anyone(): void
    {
        $result = $this->openCase(anonymous: true);

        $status = app(CaseService::class)->statusForToken($result['token']);

        $this->assertNotNull($status);
        $this->assertSame($result['case']->case_ref, $status['case_ref']);
        $this->assertArrayNotHasKey('reporter_id', $status);
        $this->assertArrayNotHasKey('access_user_ids', $status);
        $this->assertArrayNotHasKey('findings', $status);
    }

    public function test_an_unknown_token_returns_nothing(): void
    {
        $this->openCase(anonymous: true);

        $this->assertNull(app(CaseService::class)->statusForToken('NOT-A-REAL-TOKEN'));
    }

    public function test_a_reporter_reply_is_stored_without_an_author(): void
    {
        $result = $this->openCase(anonymous: true);

        $note = app(CaseService::class)->replyWithToken($result['token'], 'I have more detail on the third contract.');

        $this->assertNotNull($note);
        $this->assertNull(DB::table('case_notes')->where('id', $note->id)->value('author_id'));
    }

    public function test_the_reporter_sees_only_notes_marked_visible_to_them(): void
    {
        $result = $this->openCase(anonymous: true);
        $service = app(CaseService::class);

        $service->addNote($result['case'], $this->cfh, 'Procurement records requested.', reporterVisible: true);
        $service->addNote($result['case'], $this->cfh, 'Counsel engaged; treat as privileged.', privileged: true);
        $service->addNote($result['case'], $this->cfh, 'Internal working note.');

        $status = $service->statusForToken($result['token']);

        $this->assertCount(1, $status['updates']);
        $this->assertSame('Procurement records requested.', $status['updates'][0]['note']);
    }

    public function test_a_note_cannot_be_both_privileged_and_reporter_visible(): void
    {
        $case = $this->openCase()['case'];

        $this->expectException(ValidationException::class);
        app(CaseService::class)->addNote($case, $this->cfh, 'Contradictory.', privileged: true, reporterVisible: true);
    }

    // ── The WhatsApp anonymising bridge (11.4) ───────────────────────

    public function test_the_anonymising_bridge_strips_the_originating_identifier(): void
    {
        $result = app(CaseService::class)->openFromAnonymisingBridge([
            'case_type' => 'whistleblowing',
            'title' => 'Concern raised over WhatsApp',
            'description' => 'A supervisor is approving their own overtime.',
            'confidentiality' => 'Highly Restricted',
            'severity' => 'High',
            'phone' => '+234 803 000 0000',
            'reporter_contact' => ['msisdn' => '+234 803 000 0000'],
        ], $this->tenant->id, 'whatsapp');

        $row = DB::table('cases')->where('id', $result['case']->id)->first();

        $this->assertNull($row->reporter_id);
        $this->assertSame(1, (int) $row->was_anonymised);
        $this->assertStringNotContainsString('803', (string) $row->description);
        $this->assertStringNotContainsString('803', (string) $row->anonymisation_note);
        $this->assertSame('whatsapp', $row->channel);
    }

    // ── Public intake ────────────────────────────────────────────────

    public function test_the_public_intake_page_needs_no_login(): void
    {
        $this->get(route('whistleblowing.create'))->assertOk();
    }

    public function test_an_unauthenticated_report_creates_an_anonymous_case(): void
    {
        $this->post(route('whistleblowing.store'), [
            'title' => 'Expense claims approved without receipts',
            'description' => 'A department head has approved four claims with no supporting documents.',
            'anonymous' => true,
        ])->assertRedirect(route('whistleblowing.submitted'));

        $row = DB::table('cases')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->is_anonymous);
        $this->assertNull($row->reporter_id);
        $this->assertSame('whistleblowing', $row->case_type);
    }

    /**
     * The regression this exists for: a public report named nobody, so its
     * allowlist was empty and the case was invisible to every user in the
     * system — a disclosure channel that swallows disclosures.
     */
    public function test_a_public_report_reaches_someone_who_can_triage_it(): void
    {
        $this->post(route('whistleblowing.store'), [
            'title' => 'Procurement awards concentrated on one vendor',
            'description' => 'Three consecutive contracts awarded without a competitive process.',
            'anonymous' => true,
        ])->assertRedirect();

        $row = DB::table('cases')->latest('id')->first();
        $allowlist = json_decode((string) $row->access_user_ids, true) ?: [];

        $this->assertNotEmpty($allowlist, 'A report nobody can see is worse than no channel at all.');
        $this->assertContains($this->cfh->id, $allowlist, 'The Control Function Head triages an unnamed intake.');

        // ...and it is genuinely reachable through the scope, not just stored.
        $this->actingAs($this->cfh);
        $this->assertSame(1, InvestigationCase::where('id', $row->id)->count());

        $this->actingAs($this->cfh)
            ->get(route('cases.show', $row->id))
            ->assertOk();
    }

    public function test_the_public_intake_still_stores_no_reporter_identity(): void
    {
        $this->actingAs($this->outsider)
            ->post(route('whistleblowing.store'), [
                'title' => 'Conflict of interest not declared',
                'description' => 'A manager sits on the board of a supplier.',
                'anonymous' => true,
            ])
            ->assertRedirect();

        $row = DB::table('cases')->latest('id')->first();
        $allowlist = json_decode((string) $row->access_user_ids, true) ?: [];

        $this->assertNull($row->reporter_id);
        $this->assertNotContains(
            $this->outsider->id,
            $allowlist,
            'Triage access must never be a back door to the reporter’s identity.',
        );
    }

    public function test_the_intake_allowlist_roles_are_tenant_configurable(): void
    {
        $this->tenant->update(['settings' => ['cases' => ['intake_allowlist_roles' => ['Control Officer']]]]);

        $this->post(route('whistleblowing.store'), [
            'title' => 'Timesheets approved retrospectively',
            'description' => 'Overtime approved weeks after the fact.',
            'anonymous' => true,
        ])->assertRedirect();

        $allowlist = json_decode((string) DB::table('cases')->latest('id')->first()->access_user_ids, true) ?: [];

        $this->assertContains($this->officer->id, $allowlist);
        $this->assertNotContains($this->cfh->id, $allowlist);
    }

    public function test_an_explicit_allowlist_is_never_widened_by_the_intake_fallback(): void
    {
        $case = $this->openCase(overrides: [
            'lead_investigator_id' => $this->officer->id,
            'access_user_ids' => [$this->officer->id],
        ])['case'];

        $this->assertSame([$this->officer->id], $case->access_user_ids);
        $this->assertFalse(
            $case->grantsAccessTo($this->cfh),
            'The fallback applies only when the case would otherwise reach nobody.',
        );
    }

    public function test_an_authenticated_anonymous_report_still_stores_no_identity(): void
    {
        $this->actingAs($this->outsider)
            ->post(route('whistleblowing.store'), [
                'title' => 'Conflict of interest not declared',
                'description' => 'A manager sits on the board of a supplier.',
                'anonymous' => true,
            ])
            ->assertRedirect();

        $row = DB::table('cases')->latest('id')->first();

        $this->assertNull($row->reporter_id, 'Being logged in must not de-anonymise a report.');
    }

    // ── Investigation lifecycle ──────────────────────────────────────

    public function test_a_case_cannot_be_concluded_by_the_person_who_reported_it(): void
    {
        $result = $this->openCase(anonymous: false, reporter: $this->cfh, overrides: [
            'lead_investigator_id' => $this->cfh->id,
            'access_user_ids' => [$this->cfh->id],
        ]);

        $service = app(CaseService::class);
        $service->assess($result['case'], $this->cfh, ['severity' => 'High', 'confidentiality' => 'Restricted']);
        $service->startInvestigation($result['case']->fresh(), $this->cfh, 'Plan.');

        $this->assertFalse($this->cfh->can('conclude', $result['case']->fresh()));

        $this->expectException(ValidationException::class);
        $service->conclude($result['case']->fresh(), $this->cfh, 'Substantiated', [
            'findings' => 'Confirmed.', 'conclusion' => 'Upheld.',
        ]);
    }

    public function test_an_investigation_needs_a_lead_before_it_opens(): void
    {
        $result = $this->openCase(overrides: ['lead_investigator_id' => null, 'access_user_ids' => [$this->cfh->id]]);

        $this->expectException(ValidationException::class);
        app(CaseService::class)->startInvestigation($result['case'], $this->cfh, 'Plan.');
    }

    public function test_the_lifecycle_cannot_be_skipped(): void
    {
        $case = $this->openCase()['case'];

        $this->expectException(ValidationException::class);
        app(CaseService::class)->conclude($case, $this->cfh, 'Substantiated', [
            'findings' => 'x', 'conclusion' => 'y',
        ]);
    }

    // ── Board extract ────────────────────────────────────────────────

    public function test_the_board_extract_carries_counts_but_no_case_detail(): void
    {
        $this->openCase(anonymous: true);
        $this->openCase();

        $extract = app(CaseService::class)->boardExtract($this->tenant->id);

        $this->assertSame(2, $extract['total']);
        $this->assertSame(1, $extract['anonymous']);
        $this->assertArrayNotHasKey('cases', $extract);
        $this->assertArrayNotHasKey('titles', $extract);
    }

    // ── Pages ────────────────────────────────────────────────────────

    public function test_the_case_pages_render_for_a_member(): void
    {
        $case = $this->openCase()['case'];

        $this->actingAs($this->cfh)
            ->get(route('cases.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Cases/Index')->has('cases.data', 1));

        $this->actingAs($this->cfh)
            ->get(route('cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Cases/Show'));
    }

    public function test_privileged_notes_are_withheld_from_a_member_without_the_permission(): void
    {
        $case = $this->openCase()['case'];
        $service = app(CaseService::class);

        $service->addNote($case, $this->cfh, 'Ordinary note.');
        $service->addNote($case, $this->cfh, 'Counsel advice — privileged.', privileged: true);

        $this->actingAs($this->officer)
            ->get(route('cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notes', 1)->where('canSeePrivileged', false));

        $this->actingAs($this->cfh)
            ->get(route('cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('notes', 2)->where('canSeePrivileged', true));
    }
}
