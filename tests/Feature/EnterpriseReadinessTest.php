<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\Entity;
use App\Models\Framework;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContentPackInstaller;
use App\Services\SoaService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnterpriseReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Enterprise Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $this->cfh = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cfh->assignRole('Control Function Head');
    }

    // ── Observability (16.3) ─────────────────────────────────────────

    public function test_health_endpoint_reports_component_checks(): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.cache.ok', true)
            ->assertJsonStructure(['queue' => ['pending', 'failed']]);
    }

    public function test_responses_carry_security_headers_and_a_correlation_id(): void
    {
        $response = $this->actingAs($this->cfh)->get(route('dashboard'));

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy');
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_a_supplied_request_id_is_echoed_for_correlation(): void
    {
        $response = $this->actingAs($this->cfh)
            ->withHeader('X-Request-Id', 'drill-abc-123')
            ->get(route('dashboard'));

        $this->assertSame('drill-abc-123', $response->headers->get('X-Request-Id'));
    }

    // ── Tenant provisioning (16.3) ───────────────────────────────────

    public function test_provisioning_stands_up_a_complete_tenant(): void
    {
        $this->artisan('tenant:provision', [
            'name' => 'Acme Microfinance Bank',
            '--admin-email' => 'admin@acme.test',
            '--residency' => 'NG',
            '--entity-type' => 'microfinance_bank',
        ])->assertSuccessful();

        $tenant = Tenant::where('name', 'Acme Microfinance Bank')->firstOrFail();
        $this->assertSame('NG', $tenant->data_residency);

        $entity = Entity::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('microfinance_bank', $entity->entity_type);
        $this->assertSame('NG', $entity->data_residency_country);

        $admin = User::withoutGlobalScopes()->where('email', 'admin@acme.test')->firstOrFail();
        $this->assertTrue($admin->hasRole('System Administrator'));
        $this->assertTrue($entity->grantedUsers()->where('users.id', $admin->id)->exists());

        // Same name twice refuses.
        $this->artisan('tenant:provision', [
            'name' => 'Acme Microfinance Bank',
        ])->assertFailed();
    }

    // ── Statement of Applicability (16.4) ────────────────────────────

    public function test_soa_covers_all_93_annex_a_controls_and_exports(): void
    {
        app(ContentPackInstaller::class)->install('ISO-27001-2022');

        $this->actingAs($this->cfh);

        $framework = Framework::withoutGlobalScopes()->where('code', 'ISO-27001-2022')->firstOrFail();

        $statement = app(SoaService::class)->statement($framework);

        $annexA = $statement->filter(fn ($row) => preg_match('/^A\.\d+\.\d+$/', $row['ref_code']));
        $this->assertCount(93, $annexA);

        // Undeclared requirements default to applicable / not implemented —
        // the honest default for a certification document.
        $first = $annexA->first();
        $this->assertTrue($first['is_applicable']);
        $this->assertSame('not_implemented', $first['implementation_status']);

        $this->get(route('frameworks.soa', $framework))->assertOk();
        $this->get(route('frameworks.soa.export', $framework))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_excluding_a_requirement_requires_a_justification(): void
    {
        app(ContentPackInstaller::class)->install('ISO-27001-2022');

        $framework = Framework::withoutGlobalScopes()->where('code', 'ISO-27001-2022')->firstOrFail();
        $requirement = $framework->requirements()->where('ref_code', 'A.7.4')->firstOrFail();

        $this->actingAs($this->cfh)
            ->put(route('frameworks.soa.decide', $framework), [
                'requirement_id' => $requirement->id,
                'is_applicable' => false,
                'justification' => '',
                'implementation_status' => 'not_implemented',
            ])->assertSessionHasErrors('justification');

        $this->put(route('frameworks.soa.decide', $framework), [
            'requirement_id' => $requirement->id,
            'is_applicable' => false,
            'justification' => 'Physical security is the colocation facility\'s control, evidenced by its SOC 2 report.',
            'implementation_status' => 'not_implemented',
        ])->assertRedirect();

        $this->assertDatabaseHas('soa_entries', [
            'tenant_id' => $this->tenant->id,
            'requirement_id' => $requirement->id,
            'is_applicable' => false,
        ]);
    }

    // ── Query budget (16.3) — the controls index must not scale queries
    // with rows (no N+1; pagination bounds the page, R6).

    public function test_controls_index_stays_within_the_query_budget_at_scale(): void
    {
        $rows = [];
        for ($i = 1; $i <= 400; $i++) {
            $rows[] = [
                'tenant_id' => $this->tenant->id,
                'control_ref' => 'BULK-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'title' => "Bulk control {$i}",
                'type' => 'Preventive', 'nature' => 'Manual', 'frequency' => 'Monthly',
                'status' => 'Active', 'is_key_control' => false, 'is_template' => false,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        Control::withoutGlobalScopes()->insert($rows);

        $this->actingAs($this->cfh);

        DB::enableQueryLog();
        $this->get(route('controls.index'))->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            40,
            $queries,
            "Controls index ran {$queries} queries at 400 rows — budget is <40; look for an N+1.",
        );
    }
}
