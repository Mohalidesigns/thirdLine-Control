<?php

namespace Tests\Feature;

use App\Models\IntegrationConfig;
use App\Models\ObligationAssignment;
use App\Models\OrganisationUnit;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContentPackInstaller;
use App\Services\ObligationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $otherTenant;

    private string $apiKey = 'slk_test_key_0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['name' => 'Other Bank', 'status' => 'active']);

        IntegrationConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'target_system' => 'ThirdLine',
            'sync_direction' => 'Bidirectional',
            'master_system' => 'SecondLine',
            'inbound_key_hash' => hash('sha256', $this->apiKey),
            'is_active' => true,
        ]);

        app(ContentPackInstaller::class)->install('CBN-CG-2023');
    }

    private function seedCalendarFor(Tenant $tenant): void
    {
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);

        $entity = OrganisationUnit::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Head Office '.$tenant->id,
            'type' => 'Head Office',
            'entity_type' => 'commercial_bank',
            'jurisdiction' => 'NG',
            'fiscal_year_end_month' => 12,
            'fiscal_year_end_day' => 31,
        ]);

        $obligation = RegulatoryObligation::withoutGlobalScopes()
            ->whereNull('tenant_id')->where('obligation_ref', 'CBN-CG-2023-BOARD-EVAL')->firstOrFail();

        $assignment = ObligationAssignment::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'obligation_id' => $obligation->id,
            'entity_id' => $entity->id,
            'owner_id' => $owner->id,
        ]);

        app(ObligationService::class)->generateInstances($assignment);
    }

    public function test_the_compliance_endpoints_require_a_key(): void
    {
        $this->getJson('/api/v1/frameworks/coverage')->assertStatus(401);
        $this->getJson('/api/v1/obligations/instances')->assertStatus(401);
    }

    public function test_framework_coverage_is_returned_per_framework(): void
    {
        $response = $this->getJson('/api/v1/frameworks/coverage', ['X-Api-Key' => $this->apiKey])->assertOk();

        $codes = collect($response->json('data'))->pluck('code');

        $this->assertContains('CBN-CG-2023', $codes);
        $this->assertArrayHasKey('mapped_percentage', $response->json('data.0'));
        $this->assertArrayHasKey('verification_status', $response->json('data.0'));
    }

    public function test_the_filing_calendar_carries_its_verification_status(): void
    {
        $this->seedCalendarFor($this->tenant);

        $response = $this->getJson('/api/v1/obligations/instances', ['X-Api-Key' => $this->apiKey])->assertOk();

        $this->assertNotEmpty($response->json('data'));
        $this->assertSame('CBN-CG-2023-BOARD-EVAL', $response->json('data.0.obligation_ref'));
        $this->assertSame('unverified', $response->json('data.0.verification_status'));
        $this->assertSame('CBN', $response->json('data.0.regulator'));
    }

    public function test_another_tenants_filings_are_never_returned(): void
    {
        $this->seedCalendarFor($this->tenant);
        $this->seedCalendarFor($this->otherTenant);

        $response = $this->getJson('/api/v1/obligations/instances', ['X-Api-Key' => $this->apiKey])->assertOk();

        $entities = collect($response->json('data'))->pluck('entity')->unique();

        $this->assertSame(['Head Office '.$this->tenant->id], $entities->values()->all());
    }

    public function test_the_calendar_can_be_filtered_by_status(): void
    {
        $this->seedCalendarFor($this->tenant);

        $response = $this->getJson('/api/v1/obligations/instances?status=Accepted', ['X-Api-Key' => $this->apiKey])->assertOk();

        $this->assertSame([], $response->json('data'));
    }
}
