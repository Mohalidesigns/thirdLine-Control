<?php

namespace Tests\Feature;

use App\Models\CompensatingControl;
use App\Models\Control;
use App\Models\ControlException;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: registering a compensating control on an exception with no
 * linked control used to 500 on the NOT NULL primary_control_id insert.
 * It must be a validation error — there is nothing to compensate.
 */
class CompensatingControlGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);
        $this->officer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->officer->assignRole('Control Officer');
    }

    private function makeException(array $overrides = []): ControlException
    {
        return ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'EXC-'.fake()->unique()->numberBetween(1000, 9999),
            'source_type' => 'Manual',
            'title' => 'Manual exception with no linked control',
            'severity' => 'High',
            'raised_by' => $this->officer->id,
            'date_raised' => now()->toDateString(),
            'target_closure_date' => now()->addDays(14)->toDateString(),
            'status' => 'In Progress',
            ...$overrides,
        ]);
    }

    public function test_registering_on_a_control_less_exception_is_a_validation_error_not_a_500(): void
    {
        $exception = $this->makeException();

        $this->actingAs($this->officer)
            ->from(route('exceptions.show', $exception))
            ->post(route('compensating-controls.store', $exception), [
                'description' => 'Manual review of daily postings',
                'is_temporary' => true,
                'effective_from' => now()->toDateString(),
                'effective_to' => now()->addMonth()->toDateString(),
            ])
            ->assertRedirect(route('exceptions.show', $exception))
            ->assertSessionHasErrors('compensating');

        $this->assertSame(0, CompensatingControl::withoutGlobalScopes()->count());
    }

    public function test_registering_on_a_control_linked_exception_still_works(): void
    {
        $control = Control::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'control_ref' => 'CTL-901',
            'title' => 'Suspense reconciliation',
            'type' => 'Preventive',
            'nature' => 'Manual',
            'frequency' => 'Monthly',
            'status' => 'Active',
            'created_by' => $this->officer->id,
        ]);

        $exception = $this->makeException(['control_id' => $control->id]);

        $this->actingAs($this->officer)
            ->post(route('compensating-controls.store', $exception), [
                'description' => 'Manual review of daily postings',
                'is_temporary' => true,
                'effective_from' => now()->toDateString(),
                'effective_to' => now()->addMonth()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $registered = CompensatingControl::withoutGlobalScopes()->first();
        $this->assertNotNull($registered);
        $this->assertSame($control->id, $registered->primary_control_id);
        $this->assertSame('Proposed', $registered->status);
    }
}
