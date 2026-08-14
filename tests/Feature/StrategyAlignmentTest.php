<?php

namespace Tests\Feature;

use App\Models\Initiative;
use App\Models\InitiativeMilestone;
use App\Models\Metric;
use App\Models\MetricValue;
use App\Models\Objective;
use App\Models\ObjectiveMetric;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ObjectiveService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 17.1 — strategy and performance alignment. The roll-up is the
 * claim the whole scorecard rests on, so it is tested against known
 * arithmetic rather than against itself.
 */
class StrategyAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $exec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->exec = $this->makeUser('exec@test.local', 'Executive Viewer');

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeObjective(array $overrides = []): Objective
    {
        return Objective::create([
            'tenant_id' => $this->tenant->id,
            'perspective' => 'financial',
            'code' => 'OBJ-'.strtoupper(substr(md5((string) mt_rand()), 0, 6)),
            'title' => 'Grow retail deposits by 25%',
            'owner_id' => $this->cfh->id,
            'period' => 'FY2026',
            'target_date' => now()->addMonths(9)->toDateString(),
            'status' => 'Active',
            'progress_mode' => 'auto',
            'weight' => 1,
            'created_by' => $this->officer->id,
            ...$overrides,
        ]);
    }

    private function makeMetric(float $latest, array $overrides = []): Metric
    {
        $metric = Metric::create([
            'tenant_id' => $this->tenant->id,
            'metric_type' => 'KPI',
            'code' => 'KPI-'.strtoupper(substr(md5((string) mt_rand()), 0, 6)),
            'name' => 'Retail deposit balance',
            'unit' => 'NGNbn',
            'data_type' => 'number',
            'direction' => 'higher_is_better',
            'frequency' => 'Monthly',
            'owner_id' => $this->cfh->id,
            'source' => 'manual',
            'aggregation' => 'last',
            'is_active' => true,
            ...$overrides,
        ]);

        MetricValue::create([
            'tenant_id' => $this->tenant->id,
            'metric_id' => $metric->id,
            'period_label' => now()->format('Y-m'),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'value' => $latest,
            'source' => 'manual',
            'captured_at' => now(),
        ]);

        return $metric;
    }

    public function test_a_measure_achievement_is_scaled_between_baseline_and_target(): void
    {
        $objective = $this->makeObjective();
        $metric = $this->makeMetric(120);

        $measure = ObjectiveMetric::create([
            'tenant_id' => $this->tenant->id,
            'objective_id' => $objective->id,
            'metric_id' => $metric->id,
            'baseline_value' => 100,
            'target_value' => 200,
            'weight' => 1,
        ]);

        // 120 of the way from 100 to 200 is a fifth of the journey.
        $this->assertSame(20, $measure->achievement(120.0));

        // A target below the baseline is a reduction target and scales the
        // same way — driving a loss ratio down is progress, not regress.
        $measure->update(['baseline_value' => 200, 'target_value' => 100]);
        $this->assertSame(80, $measure->fresh()->achievement(120.0));

        // Overshoot and undershoot are clamped, never negative or > 100.
        $this->assertSame(100, $measure->fresh()->achievement(50.0));
        $this->assertSame(0, $measure->fresh()->achievement(400.0));
    }

    public function test_objective_progress_rolls_up_from_metrics_and_initiatives(): void
    {
        $objective = $this->makeObjective();

        // Measure at 20% achievement, weight 3.
        $metric = $this->makeMetric(120);
        ObjectiveMetric::create([
            'tenant_id' => $this->tenant->id,
            'objective_id' => $objective->id,
            'metric_id' => $metric->id,
            'baseline_value' => 100,
            'target_value' => 200,
            'weight' => 3,
        ]);

        // Initiative at 50% (two of four equally weighted milestones done),
        // weight 1.
        $initiative = Initiative::create([
            'tenant_id' => $this->tenant->id,
            'objective_id' => $objective->id,
            'reference' => 'INI-2026-001',
            'title' => 'Agent banking rollout',
            'owner_id' => $this->cfh->id,
            'status' => 'In Progress',
            'currency' => 'NGN',
            'progress_mode' => 'auto',
            'weight' => 1,
            'created_by' => $this->officer->id,
        ]);

        foreach (['Complete', 'Complete', 'Pending', 'Pending'] as $index => $status) {
            InitiativeMilestone::create([
                'tenant_id' => $this->tenant->id,
                'initiative_id' => $initiative->id,
                'title' => "Milestone {$index}",
                'status' => $status,
                'completed_at' => $status === 'Complete' ? now() : null,
                'weight' => 1,
                'sort_order' => $index,
            ]);
        }

        $progress = app(ObjectiveService::class)->rollUp($objective->fresh());

        // (20 × 3 + 50 × 1) / 4 = 27.5 → 28.
        $this->assertSame(28, $progress);
        $this->assertSame(28, (int) $objective->fresh()->progress);
        $this->assertNotNull($objective->fresh()->progress_calculated_at);
    }

    public function test_a_parent_objective_rolls_up_from_its_children(): void
    {
        $parent = $this->makeObjective(['code' => 'OBJ-PARENT']);

        $childA = $this->makeObjective([
            'code' => 'OBJ-A',
            'parent_objective_id' => $parent->id,
            'progress_mode' => 'manual',
            'progress' => 80,
            'weight' => 3,
        ]);

        $this->makeObjective([
            'code' => 'OBJ-B',
            'parent_objective_id' => $parent->id,
            'progress_mode' => 'manual',
            'progress' => 40,
            'weight' => 1,
        ]);

        // (80 × 3 + 40 × 1) / 4 = 70.
        $this->assertSame(70, app(ObjectiveService::class)->rollUp($parent->fresh()));

        // A cancelled child leaves the live scorecard entirely.
        $childA->update(['status' => 'Cancelled']);
        $this->assertSame(40, app(ObjectiveService::class)->rollUp($parent->fresh()));
    }

    public function test_an_unmeasured_objective_is_unknown_rather_than_zero(): void
    {
        $objective = $this->makeObjective();
        $metric = $this->makeMetric(120);

        // A measure with no target cannot be scored, so it must not drag
        // the objective to 0% — that would read as failure rather than as
        // "not yet measured".
        ObjectiveMetric::create([
            'tenant_id' => $this->tenant->id,
            'objective_id' => $objective->id,
            'metric_id' => $metric->id,
            'baseline_value' => 100,
            'target_value' => null,
            'weight' => 1,
        ]);

        $breakdown = app(ObjectiveService::class)->breakdown($objective->fresh());

        $this->assertNull($breakdown['derived']);
        $this->assertNull($breakdown['contributions'][0]['progress']);

        // And the stored figure is null too, not 0 — an objective nobody has
        // instrumented must not sit at the bottom of the board's scorecard
        // for the crime of not having been measured.
        $this->assertNull(app(ObjectiveService::class)->rollUp($objective->fresh()));
        $this->assertNull($objective->fresh()->progress);
        $this->assertSame('Not measured', app(ObjectiveService::class)->band(null)['label']);
    }

    public function test_an_unmeasured_objective_does_not_drag_down_its_perspective(): void
    {
        $measured = $this->makeObjective(['code' => 'OBJ-M', 'progress_mode' => 'manual', 'progress' => 80]);
        $this->makeObjective(['code' => 'OBJ-U', 'progress_mode' => 'auto']);

        app(ObjectiveService::class)->rollUpAll($this->tenant->id);

        $financial = collect(app(ObjectiveService::class)->scorecard())->firstWhere('key', 'financial');

        // The perspective reads 80% from the one objective that is measured,
        // and says plainly that another is not — rather than averaging the
        // unmeasured one in as a zero and reporting 40%.
        $this->assertSame(80, $financial['progress']);
        $this->assertSame(1, $financial['unmeasured']);
        $this->assertSame(80, (int) $measured->fresh()->progress);
    }

    public function test_manual_progress_is_not_overwritten_by_the_roll_up(): void
    {
        $objective = $this->makeObjective(['progress_mode' => 'manual', 'progress' => 65]);
        $metric = $this->makeMetric(120);

        ObjectiveMetric::create([
            'tenant_id' => $this->tenant->id,
            'objective_id' => $objective->id,
            'metric_id' => $metric->id,
            'baseline_value' => 100,
            'target_value' => 200,
            'weight' => 1,
        ]);

        $this->assertSame(65, app(ObjectiveService::class)->rollUp($objective->fresh()));
        $this->assertSame(65, (int) $objective->fresh()->progress);

        // The derived figure is still shown beside the asserted one.
        $this->assertSame(20, app(ObjectiveService::class)->breakdown($objective->fresh())['derived']);
    }

    public function test_the_drafter_cannot_approve_their_own_objective(): void
    {
        // The Control Function Head drafts it…
        $this->actingAs($this->cfh);

        $this->post(route('objectives.store'), [
            'perspective' => 'customer',
            'code' => 'OBJ-CX-1',
            'title' => 'Halve complaint resolution time',
            'progress_mode' => 'auto',
            'weight' => 1,
        ])->assertRedirect();

        $objective = Objective::where('code', 'OBJ-CX-1')->firstOrFail();
        $this->assertSame('Draft', $objective->status);

        // …and cannot then wave it onto the board's scorecard, despite
        // holding every approval permission there is (R2, no admin bypass).
        $this->post(route('objectives.approve', $objective))->assertForbidden();
        $this->assertSame('Draft', $objective->fresh()->status);

        // A second person with the permission can.
        $this->actingAs($this->exec)
            ->post(route('objectives.approve', $objective))
            ->assertRedirect();

        $this->assertSame('Active', $objective->fresh()->status);
    }

    public function test_an_objective_cannot_be_made_its_own_ancestor(): void
    {
        $this->actingAs($this->cfh);

        $parent = $this->makeObjective(['code' => 'OBJ-TOP']);
        $child = $this->makeObjective(['code' => 'OBJ-CHILD', 'parent_objective_id' => $parent->id]);

        $this->put(route('objectives.update', $parent), [
            'perspective' => 'financial',
            'code' => 'OBJ-TOP',
            'title' => $parent->title,
            'progress_mode' => 'auto',
            'weight' => 1,
            'parent_objective_id' => $child->id,
        ])->assertSessionHasErrors('parent_objective_id');

        $this->assertNull($parent->fresh()->parent_objective_id);
    }

    public function test_the_strategy_map_and_scorecard_render_for_the_board(): void
    {
        $objective = $this->makeObjective();
        $metric = $this->makeMetric(150);

        ObjectiveMetric::create([
            'tenant_id' => $this->tenant->id,
            'objective_id' => $objective->id,
            'metric_id' => $metric->id,
            'baseline_value' => 100,
            'target_value' => 200,
            'weight' => 1,
        ]);

        app(ObjectiveService::class)->rollUp($objective->fresh());

        $this->actingAs($this->exec)
            ->get(route('strategy.map'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Strategy/Map')
                ->has('map.rows', count(Objective::PERSPECTIVES)));

        $this->actingAs($this->exec)
            ->get(route('strategy.scorecard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Strategy/Scorecard')
                ->where('scorecard.0.progress', 50));
    }

    /**
     * The strategy map asks "which risks threaten this objective, and are
     * the controls over them effective" of every objective on the page — in
     * a fixed number of queries, not one per objective (R6, no N+1).
     */
    public function test_the_strategy_map_stays_within_the_query_budget(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $this->makeObjective([
                'code' => 'OBJ-BULK-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'perspective' => Objective::PERSPECTIVES[$i % count(Objective::PERSPECTIVES)],
            ]);
        }

        $this->actingAs($this->exec);

        DB::enableQueryLog();
        $this->get(route('strategy.map'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            40,
            $queries,
            "Strategy map ran {$queries} queries at 40 objectives — budget is <40; look for an N+1.",
        );
    }

    public function test_a_control_owner_cannot_author_the_objective_register(): void
    {
        $owner = $this->makeUser('owner@test.local', 'Control Owner');

        $this->actingAs($owner)
            ->post(route('objectives.store'), [
                'perspective' => 'financial',
                'code' => 'OBJ-NOPE',
                'title' => 'Unauthorised',
                'progress_mode' => 'auto',
                'weight' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('objectives', ['code' => 'OBJ-NOPE']);
    }
}
