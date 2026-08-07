<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\ControlException;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->officer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->officer->assignRole('Control Officer');

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('Control Owner');
    }

    private function makeControl(string $title): Control
    {
        return Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-'.fake()->unique()->numberBetween(100, 999),
            'title' => $title,
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
            'owner_id' => $this->owner->id,
            'created_by' => $this->officer->id,
        ]);
    }

    public function test_search_finds_matching_records_grouped_by_type(): void
    {
        $this->makeControl('Daily GL reconciliation review');
        $this->makeControl('Vault cash count');

        $response = $this->actingAs($this->officer)
            ->getJson(route('search', ['q' => 'reconciliation']))
            ->assertOk()
            ->json();

        $this->assertCount(1, $response['groups']);
        $this->assertSame('controls', $response['groups'][0]['type']);
        $this->assertSame('Daily GL reconciliation review', $response['groups'][0]['results'][0]['title']);
    }

    public function test_search_never_returns_records_from_another_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other Bank', 'status' => 'active']);
        Control::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id,
            'control_ref' => 'CTL-999',
            'title' => 'Foreign reconciliation control',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
            'owner_id' => $this->owner->id,
            'created_by' => $this->officer->id,
        ]);

        $response = $this->actingAs($this->officer)
            ->getJson(route('search', ['q' => 'reconciliation']))
            ->assertOk()
            ->json();

        $this->assertSame([], $response['groups']);
    }

    public function test_an_owner_never_sees_exceptions_they_cannot_open(): void
    {
        $control = $this->makeControl('Payment approval control');

        // An exception owned by someone else — the ExceptionPolicy would
        // refuse this owner the show page, so search must hide it too.
        ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'EXC-7001',
            'source_type' => 'Manual',
            'title' => 'Unreviewed payment exception',
            'severity' => 'High',
            'owner_id' => $this->officer->id,
            'control_id' => $control->id,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays(14)->toDateString(),
            'status' => 'Open',
            'description' => 'x',
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson(route('search', ['q' => 'payment']))
            ->assertOk()
            ->json();

        $titles = collect($response['groups'])->flatMap(fn ($g) => collect($g['results'])->pluck('title'));

        $this->assertContains('Payment approval control', $titles);          // has 'view controls'
        $this->assertNotContains('Unreviewed payment exception', $titles);   // not their exception
    }

    public function test_short_terms_return_nothing(): void
    {
        $this->makeControl('Daily GL reconciliation review');

        $this->actingAs($this->officer)
            ->getJson(route('search', ['q' => 'r']))
            ->assertOk()
            ->assertExactJson(['groups' => []]);
    }
}
