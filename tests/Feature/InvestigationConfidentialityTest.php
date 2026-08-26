<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Investigation;
use App\Models\SpeakUpCase;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CaseService;
use App\Services\InvestigationDashboardService;
use App\Services\InvestigationReportBuilder;
use App\Services\InvestigationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The single most important safety rule in CR-04, and the one with no
 * equivalent in the module this was ported from — because internal audit
 * has no whistleblowing intake to leak.
 *
 * Two regimes on one table, and a hard boundary at the Speak Up register:
 * an investigation raised from a confidential report inherits its
 * protection, inherits its allowlist, and never learns who reported it.
 */
class InvestigationConfidentialityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $head;

    private User $lead;

    private User $teamMember;

    private User $outsider;

    /** Holds 'view all investigations' and NOT the confidential authority. */
    private User $overseer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->admin = $this->makeUser('admin@test.local', 'System Administrator');
        $this->head = $this->makeUser('head@test.local', 'Control Function Head');
        $this->lead = $this->makeUser('lead@test.local', 'Control Officer');
        $this->teamMember = $this->makeUser('member@test.local', 'Control Officer');
        $this->outsider = $this->makeUser('outsider@test.local', 'Control Officer');

        // The separation the module turns on: oversight of the register is
        // not sight of a confidential matter. A tenant can hand out the
        // first without the second, and this role proves it.
        Role::findOrCreate('Investigation Overseer', 'web')->syncPermissions([
            'view investigations', 'view all investigations',
        ]);
        $this->overseer = $this->makeUser('overseer@test.local', 'Investigation Overseer');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function service(): InvestigationService
    {
        return app(InvestigationService::class);
    }

    private function open(array $overrides = [], ?User $actor = null): Investigation
    {
        $actor ??= $this->lead;
        $this->actingAs($actor);

        return $this->service()->open([
            'title' => 'Unexplained credits to a staff account',
            'category' => 'fraud',
            'source' => 'management_directive',
            'priority' => 'High',
            'team_member_ids' => [$this->teamMember->id],
            ...$overrides,
        ], $actor);
    }

    private function visibleTo(User $user): array
    {
        $this->actingAs($user);

        return Investigation::query()->pluck('reference')->all();
    }

    // ── The ordinary regime ──────────────────────────────────────────

    public function test_a_non_confidential_case_is_visible_to_its_team(): void
    {
        $investigation = $this->open();

        $this->assertContains($investigation->reference, $this->visibleTo($this->lead));
        $this->assertContains($investigation->reference, $this->visibleTo($this->teamMember));
    }

    public function test_a_non_confidential_case_is_invisible_to_an_officer_who_is_not_on_it(): void
    {
        $investigation = $this->open();

        $this->assertNotContains($investigation->reference, $this->visibleTo($this->outsider));

        $this->actingAs($this->outsider)
            ->get(route('investigations.show', $investigation->id))
            ->assertNotFound();
    }

    public function test_view_all_investigations_opens_an_ordinary_case(): void
    {
        $investigation = $this->open();

        $this->assertContains($investigation->reference, $this->visibleTo($this->overseer));

        $this->actingAs($this->overseer)
            ->get(route('investigations.show', $investigation->id))
            ->assertOk();
    }

    // ── The confidential regime ──────────────────────────────────────

    public function test_view_all_investigations_does_not_open_a_confidential_case(): void
    {
        $investigation = $this->open(['is_confidential' => true]);

        $this->assertNotContains(
            $investigation->reference,
            $this->visibleTo($this->overseer),
            "'view all investigations' is oversight of the register, not a key to a confidential matter.",
        );

        $this->actingAs($this->overseer)
            ->get(route('investigations.show', $investigation->id))
            ->assertNotFound();
    }

    public function test_a_confidential_case_is_visible_to_its_lead_and_team(): void
    {
        $investigation = $this->open(['is_confidential' => true]);

        $this->assertContains($investigation->reference, $this->visibleTo($this->lead));
        $this->assertContains($investigation->reference, $this->visibleTo($this->teamMember));
        $this->assertNotContains($investigation->reference, $this->visibleTo($this->outsider));
    }

    public function test_a_confidential_case_is_visible_to_the_control_function_head(): void
    {
        $investigation = $this->open(['is_confidential' => true]);

        $this->assertTrue(
            $this->head->can(Investigation::CONFIDENTIAL_PERMISSION),
            'The confidential override is a named permission, not a role name.',
        );

        $this->assertContains($investigation->reference, $this->visibleTo($this->head));

        $this->actingAs($this->head)
            ->get(route('investigations.show', $investigation->id))
            ->assertOk();
    }

    public function test_every_read_of_a_confidential_case_is_logged_twice(): void
    {
        $investigation = $this->open(['is_confidential' => true]);

        $this->actingAs($this->head)
            ->get(route('investigations.show', $investigation->id))
            ->assertOk();

        $this->assertTrue(
            $investigation->activities()
                ->where('activity_type', 'confidential_view')
                ->where('performed_by', $this->head->id)
                ->exists(),
            'The read must be on the case timeline — an access log nobody opens is not oversight.',
        );

        $this->assertTrue(
            AuditTrail::query()
                ->where('action', 'confidential_case_viewed')
                ->where('entity_type', Investigation::class)
                ->where('entity_id', $investigation->id)
                ->exists(),
        );
    }

    public function test_a_confidential_case_never_reaches_another_users_dashboard_aggregates(): void
    {
        $this->open(['is_confidential' => true]);

        $this->actingAs($this->overseer);
        $data = app(InvestigationDashboardService::class)->build($this->overseer);

        $this->assertSame(0, $data['kpis']['open_now']['value']);
        $this->assertSame([], $data['financials']['top_cases']);
    }

    // ── The Speak Up boundary — §D.3, all four rules ─────────────────

    /** @return array{case: SpeakUpCase, investigation: Investigation} */
    private function raiseFromSpeakUp(bool $anonymous = true): array
    {
        $result = app(CaseService::class)->open([
            'case_type' => 'whistleblowing',
            'title' => 'Procurement awards concentrated on one vendor',
            'description' => 'Three consecutive contracts awarded without a competitive process.',
            'confidentiality' => 'Highly Restricted',
            'severity' => 'High',
            'channel' => 'web',
            'lead_investigator_id' => $this->lead->id,
            'access_user_ids' => [$this->lead->id, $this->teamMember->id],
        ], null, $this->tenant->id, $anonymous);

        $case = $result['case'];

        $this->actingAs($this->lead);

        $investigation = $this->service()->open([
            'title' => 'Vendor concentration in procurement',
            'category' => 'conflict_of_interest',
            'source' => 'whistleblowing',
            'priority' => 'High',
            // Deliberately trying to open it up and to staff it from the
            // request — both must be ignored.
            'is_confidential' => false,
            'team_member_ids' => [$this->outsider->id],
        ], $this->lead, $case);

        return ['case' => $case, 'investigation' => $investigation];
    }

    public function test_rule_one_confidentiality_is_inherited_and_locked(): void
    {
        $investigation = $this->raiseFromSpeakUp()['investigation'];

        $this->assertTrue($investigation->is_confidential, 'A Speak-Up-origin investigation is confidential, whatever the form said.');
        $this->assertTrue($investigation->confidentiality_locked);

        // And the lock holds at the controller, not only in the service.
        $this->actingAs($this->lead)
            ->put(route('investigations.update', $investigation->id), [
                'title' => $investigation->title,
                'category' => $investigation->category,
                'source' => $investigation->source,
                'priority' => $investigation->priority,
                'is_confidential' => false,
            ]);

        $this->assertTrue(
            $investigation->refresh()->is_confidential,
            'No one on the investigating team can lower a protection that belongs to a reporter who is not on the team.',
        );
    }

    public function test_rule_two_the_team_is_seeded_from_the_case_allowlist_not_the_request(): void
    {
        $result = $this->raiseFromSpeakUp();
        $investigation = $result['investigation'];

        $teamIds = $investigation->teamMembers()->pluck('user_id')->all();

        $this->assertContains($this->lead->id, $teamIds);
        $this->assertContains($this->teamMember->id, $teamIds);
        $this->assertNotContains(
            $this->outsider->id,
            $teamIds,
            'Nobody gains sight of a whistleblowing matter by being named on an investigation.',
        );
    }

    public function test_rule_three_no_reporter_identity_crosses_the_boundary(): void
    {
        $reporter = $this->makeUser('reporter@test.local', 'Control Owner');

        $result = app(CaseService::class)->open([
            'case_type' => 'whistleblowing',
            'title' => 'Cash shortages concealed at close of business',
            'description' => 'Tills balanced with a suspense entry three nights running.',
            'confidentiality' => 'Highly Restricted',
            'severity' => 'High',
            'channel' => 'web',
            'lead_investigator_id' => $this->lead->id,
            'access_user_ids' => [$this->lead->id],
        ], $reporter, $this->tenant->id, false);

        $case = $result['case'];
        $this->assertSame($reporter->id, $case->reporter_id);

        $this->actingAs($this->lead);
        $investigation = $this->service()->open([
            'title' => 'Suspense entries at branch close',
            'category' => 'fraud',
            'source' => 'whistleblowing',
            'priority' => 'High',
        ], $this->lead, $case);

        $attributes = $investigation->refresh()->getAttributes();
        $columns = json_encode($attributes);

        $this->assertStringNotContainsString($reporter->email, $columns);
        $this->assertStringNotContainsString($reporter->name, $columns);
        $this->assertSame(
            [],
            array_keys(array_filter($attributes, fn ($value, $key) => str_contains($key, 'reporter'), ARRAY_FILTER_USE_BOTH)),
            'An investigation has no column that could hold a reporter, which is the strongest form of "it never crosses".',
        );

        // And not through the report either — Background names the origin
        // by TYPE, never by person.
        $sections = json_encode(app(InvestigationReportBuilder::class)->sections($investigation));

        $this->assertStringContainsString('a Speak Up report', $sections);
        $this->assertStringNotContainsString($reporter->name, $sections);
        $this->assertStringNotContainsString($reporter->email, $sections);
    }

    public function test_rule_four_adding_a_team_member_requires_the_case_allowlist_too(): void
    {
        $investigation = $this->raiseFromSpeakUp()['investigation'];

        $this->actingAs($this->head);

        try {
            $this->service()->assignTeamMember($investigation, $this->outsider, 'investigator', $this->head);
            $this->fail('One allowlist, enforced in both directions — this module does not open a second door.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('allowlist', $e->getMessage());
        }

        $this->assertFalse($investigation->teamMembers()->where('user_id', $this->outsider->id)->exists());
    }

    public function test_someone_on_the_case_allowlist_can_be_added_to_the_investigation(): void
    {
        $result = $this->raiseFromSpeakUp();
        $case = $result['case'];
        $investigation = $result['investigation'];

        // Grant on the case first, which is the one door.
        $this->actingAs($this->lead);
        app(CaseService::class)->grantAccess($case->refresh(), $this->outsider, $this->lead);

        $this->service()->assignTeamMember($investigation, $this->outsider, 'investigator', $this->lead);

        $this->assertTrue($investigation->teamMembers()->where('user_id', $this->outsider->id)->exists());
    }

    public function test_an_anonymous_speak_up_investigation_runs_end_to_end_without_resolving_a_person(): void
    {
        $result = $this->raiseFromSpeakUp(anonymous: true);
        $case = $result['case'];
        $investigation = $result['investigation'];

        $this->assertNull($case->reporter_id, 'An anonymous case has no reporter to leak.');

        $this->actingAs($this->lead);

        $investigation = $this->service()->transition($investigation, 'reported', $this->lead);
        $investigation = $this->service()->transition($investigation, 'under_investigation', $this->lead);

        $subject = $this->service()->addSubject($investigation, [
            'subject_type' => 'vendor',
            'name' => 'Adeyemi Trading Ltd',
            'role_in_case' => 'primary_subject',
        ], $this->lead);

        $this->service()->recordSubjectOutcome($subject, 'culpable', 'Three awards with no competitive process.', $this->lead);

        $investigation = $this->service()->transition($investigation, 'pending_review', $this->lead);
        $investigation = $this->service()->complete($investigation, $this->lead, ['risk_rating' => 'High']);

        $this->assertSame('completed', $investigation->status);
    }
}
