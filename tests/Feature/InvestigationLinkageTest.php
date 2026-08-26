<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\EntityLink;
use App\Models\Investigation;
use App\Models\SpeakUpCase;
use App\Models\Tenant;
use App\Models\TestInstance;
use App\Models\User;
use App\Services\CaseService;
use App\Services\InvestigationService;
use App\Services\LinkageService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Provenance and the graph (§D.1, §D.2).
 *
 * Two mechanisms, on purpose. The morph answers "this investigation exists
 * because of that record" — exactly one, set at creation, and it is what
 * drives confidentiality inheritance and the report's Background. The edge
 * answers "these two records are related" — many, any time, and it is what
 * the Atlas page draws.
 */
class InvestigationLinkageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->outsider = $this->makeUser('outsider@test.local', 'Control Officer');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function exception(): ControlException
    {
        return ControlException::create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'EXC-2026-001',
            'source_type' => 'Manual',
            'title' => 'Posting made without a second signature',
            'severity' => 'High',
            'status' => 'Open',
            'date_raised' => now()->subDays(10),
        ]);
    }

    private function open(?object $origin = null, array $overrides = []): Investigation
    {
        $this->actingAs($this->officer);

        return app(InvestigationService::class)->open([
            'title' => 'Unauthorised postings at the treasury desk',
            'category' => 'fraud',
            'source' => 'control_exception',
            'priority' => 'High',
            ...$overrides,
        ], $this->officer, $origin);
    }

    // ── The alias ────────────────────────────────────────────────────

    public function test_the_investigation_is_a_first_class_node(): void
    {
        $this->assertSame(Investigation::class, EntityLink::modelClassFor('investigation'));
        $this->assertSame('Investigation', EntityLink::NODE_LABELS['investigation']);
    }

    public function test_an_investigation_can_be_linked_to_a_control_exception(): void
    {
        $exception = $this->exception();
        $investigation = $this->open();

        app(LinkageService::class)->link('investigation', $investigation->id, 'exception', $exception->id, 'relates_to');

        $neighbours = app(LinkageService::class)->neighbours('investigation', $investigation->id);

        $this->assertCount(1, $neighbours);
        $this->assertSame('exception', $neighbours[0]['type']);
        $this->assertSame('EXC-2026-001', $neighbours[0]['ref']);
        $this->assertSame('exceptions.show', $neighbours[0]['route']);
    }

    // ── Provenance ───────────────────────────────────────────────────

    public function test_the_origin_morph_and_the_graph_edge_are_written_together(): void
    {
        $exception = $this->exception();
        $investigation = $this->open($exception);

        $this->assertSame(ControlException::class, $investigation->origin_type);
        $this->assertSame($exception->id, $investigation->origin_id);

        $this->assertTrue(
            EntityLink::query()
                ->where('source_type', 'investigation')->where('source_id', $investigation->id)
                ->where('target_type', 'exception')->where('target_id', $exception->id)
                ->exists(),
            'The morph is the source of truth; the edge is the view. Neither may exist without the other.',
        );
    }

    /**
     * A test instance is a legitimate origin but has no alias in the
     * linkage graph. The morph must still be recorded — provenance is not
     * conditional on there being somewhere to draw it.
     */
    public function test_an_origin_with_no_graph_alias_still_records_its_provenance(): void
    {
        $control = Control::create([
            'tenant_id' => $this->tenant->id, 'control_ref' => 'CTL-001', 'title' => 'Dual authorisation',
            'type' => 'Preventive', 'nature' => 'Manual', 'frequency' => 'Monthly', 'status' => 'Active',
        ]);

        $instance = TestInstance::create([
            'tenant_id' => $this->tenant->id, 'control_id' => $control->id,
            'reference' => 'TST-2026-001', 'period_label' => 'Aug 2026',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'due_date' => now()->toDateString(), 'status' => 'Failed',
        ]);

        $investigation = $this->open($instance, ['source' => 'control_test_failure']);

        $this->assertSame(TestInstance::class, $investigation->origin_type);
        $this->assertSame($instance->id, $investigation->origin_id);
        $this->assertSame(
            0,
            EntityLink::query()->where('source_type', 'investigation')->where('source_id', $investigation->id)->count(),
            'No alias, no edge — and no half-written graph node either.',
        );
    }

    public function test_opening_from_an_origin_writes_the_record_the_edge_and_the_diary_together(): void
    {
        $exception = $this->exception();

        $investigation = $this->open($exception);

        $this->assertSame(1, Investigation::query()->count());
        $this->assertSame(
            1,
            EntityLink::query()->where('source_type', 'investigation')->where('source_id', $investigation->id)->count(),
        );
        $this->assertSame(
            'Raised from ControlException #'.$exception->id.'.',
            $investigation->activities()->where('activity_type', 'case_created')->value('description'),
        );
    }

    public function test_a_speak_up_origin_records_both_provenance_and_an_edge(): void
    {
        $case = app(CaseService::class)->open([
            'case_type' => 'whistleblowing',
            'title' => 'Vendor concentration',
            'description' => 'Three awards, no competition.',
            'confidentiality' => 'Highly Restricted',
            'severity' => 'High',
            'channel' => 'web',
            'lead_investigator_id' => $this->officer->id,
            'access_user_ids' => [$this->officer->id],
        ], null, $this->tenant->id, true)['case'];

        $investigation = $this->open($case, ['source' => 'whistleblowing']);

        $this->assertSame(SpeakUpCase::class, $investigation->origin_type);
        $this->assertTrue($investigation->raisedFromSpeakUp());

        $this->assertTrue(
            EntityLink::query()
                ->where('source_type', 'investigation')->where('source_id', $investigation->id)
                ->where('target_type', 'case')->where('target_id', $case->id)
                ->exists(),
        );
    }

    // ── The "(removed record)" behaviour ─────────────────────────────

    public function test_an_investigation_the_viewer_cannot_open_renders_as_a_removed_record(): void
    {
        $exception = $this->exception();
        $investigation = $this->open(null, ['is_confidential' => true]);

        app(LinkageService::class)->link('investigation', $investigation->id, 'exception', $exception->id);

        // The officer who runs the case sees it.
        $this->actingAs($this->officer);
        $mine = app(LinkageService::class)->neighbours('exception', $exception->id);
        $this->assertSame($investigation->reference, $mine[0]['ref']);
        $this->assertSame('investigations.show', $mine[0]['route']);

        // Anyone else sees the edge exists and nothing more.
        $this->actingAs($this->outsider);
        $theirs = app(LinkageService::class)->neighbours('exception', $exception->id);

        $this->assertCount(1, $theirs);
        $this->assertSame('(removed record)', $theirs[0]['title']);
        $this->assertNull($theirs[0]['ref']);
        $this->assertNull($theirs[0]['route'], 'A node with no route is a node nobody can follow into a case they may not read.');
    }

    public function test_the_two_hop_graph_stops_at_an_investigation_the_viewer_cannot_read(): void
    {
        $exception = $this->exception();
        $investigation = $this->open(null, ['is_confidential' => true]);

        app(LinkageService::class)->link('investigation', $investigation->id, 'exception', $exception->id);

        $this->actingAs($this->outsider);
        $graph = app(LinkageService::class)->graph('exception', $exception->id);

        $node = collect($graph['nodes'])->firstWhere('type', 'investigation');

        $this->assertNotNull($node, 'The edge is still there — hiding it would be a different kind of lie.');
        $this->assertSame('(removed record)', $node['title']);
        $this->assertNull($node['route']);
    }

    public function test_the_investigation_appears_in_the_link_picker_only_for_those_who_can_see_it(): void
    {
        $investigation = $this->open(null, ['is_confidential' => true]);

        $this->actingAs($this->officer);
        $this->assertCount(1, app(LinkageService::class)->candidates('investigation'));

        $this->actingAs($this->outsider);
        $this->assertCount(0, app(LinkageService::class)->candidates('investigation'));
    }
}
