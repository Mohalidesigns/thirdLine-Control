<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\IntegrationConfig;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private string $apiKey = 'slk_test_key_0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        IntegrationConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'target_system' => 'ThirdLine',
            'sync_direction' => 'Bidirectional',
            'master_system' => 'SecondLine',
            'inbound_key_hash' => hash('sha256', $this->apiKey),
            'is_active' => true,
        ]);
    }

    public function test_requests_without_a_key_are_rejected(): void
    {
        $this->getJson('/api/v1/controls')->assertStatus(401);
        $this->getJson('/api/v1/controls', ['X-Api-Key' => 'wrong-key'])->assertStatus(401);
    }

    public function test_controls_endpoint_returns_the_sync_envelope(): void
    {
        Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-001',
            'external_ref' => 'SL-abc',
            'title' => 'Reconciliation control',
            'type' => 'Detective',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
            'approved_at' => now(),
        ]);

        $this->getJson('/api/v1/controls', ['X-Api-Key' => $this->apiKey])
            ->assertOk()
            ->assertJsonPath('data.0.external_ref', 'SL-abc')
            ->assertJsonPath('data.0.source_system', 'SecondLine')
            ->assertJsonPath('data.0.control.control_ref', 'CTL-001');
    }

    public function test_tenant_isolation_holds_across_keys(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other Bank', 'status' => 'active']);

        Control::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id,
            'control_ref' => 'CTL-OTHER',
            'title' => 'Foreign control',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
        ]);

        $this->getJson('/api/v1/controls', ['X-Api-Key' => $this->apiKey])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_inbound_control_upsert_is_idempotent(): void
    {
        $payload = [
            'external_ref' => 'TL-999',
            'last_approved_at' => now()->toIso8601String(),
            'control' => ['title' => 'ThirdLine-originated control', 'type' => 'Preventive'],
        ];

        $headers = ['X-Api-Key' => $this->apiKey, 'Idempotency-Key' => 'idem-001'];

        $this->postJson('/api/v1/controls', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('action', 'created');

        // Duplicate delivery with the same key is acknowledged, not reprocessed.
        $this->postJson('/api/v1/controls', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('action', 'duplicate');

        $this->assertSame(
            1,
            Control::withoutGlobalScopes()->where('external_ref', 'TL-999')->count(),
        );
    }

    public function test_push_only_tenants_reject_inbound_writes(): void
    {
        IntegrationConfig::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->update(['sync_direction' => 'Push']);

        $this->postJson('/api/v1/controls', [
            'external_ref' => 'TL-X',
            'control' => ['title' => 'Should be refused'],
        ], ['X-Api-Key' => $this->apiKey, 'Idempotency-Key' => 'idem-002'])
            ->assertStatus(409);
    }
}
