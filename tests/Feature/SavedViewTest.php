<?php

namespace Tests\Feature;

use App\Models\SavedView;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedViewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $officerA;

    private User $officerB;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->officerA = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->officerA->assignRole('Control Officer');

        $this->officerB = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->officerB->assignRole('Control Officer');

        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->owner->assignRole('Control Owner');
    }

    public function test_a_user_can_save_and_list_their_views(): void
    {
        $this->actingAs($this->officerA)
            ->post(route('saved-views.store'), [
                'resource' => 'exceptions',
                'name' => 'Overdue criticals',
                'filters' => ['severity' => 'Critical', 'overdue' => 1],
                'is_shared' => false,
                'is_default' => true,
            ])
            ->assertRedirect();

        $this->actingAs($this->officerA)
            ->getJson(route('saved-views.index', ['resource' => 'exceptions']))
            ->assertOk()
            ->assertJsonPath('views.0.name', 'Overdue criticals')
            ->assertJsonPath('views.0.is_default', true);
    }

    public function test_shared_views_are_visible_to_the_same_role_only(): void
    {
        SavedView::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->officerA->id,
            'resource' => 'controls',
            'name' => 'Officer view',
            'filters' => ['status' => 'Active'],
            'is_shared' => true,
        ]);

        // Same role → visible.
        $this->actingAs($this->officerB)
            ->getJson(route('saved-views.index', ['resource' => 'controls']))
            ->assertOk()
            ->assertJsonCount(1, 'views');

        // Different role → hidden.
        $this->actingAs($this->owner)
            ->getJson(route('saved-views.index', ['resource' => 'controls']))
            ->assertOk()
            ->assertJsonCount(0, 'views');
    }

    public function test_private_views_are_never_visible_to_others(): void
    {
        SavedView::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->officerA->id,
            'resource' => 'controls',
            'name' => 'Private view',
            'filters' => ['status' => 'Draft'],
            'is_shared' => false,
        ]);

        $this->actingAs($this->officerB)
            ->getJson(route('saved-views.index', ['resource' => 'controls']))
            ->assertOk()
            ->assertJsonCount(0, 'views');
    }

    public function test_setting_a_new_default_clears_the_previous_one(): void
    {
        $first = SavedView::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->officerA->id,
            'resource' => 'controls', 'name' => 'First', 'filters' => [], 'is_default' => true,
        ]);
        $second = SavedView::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->officerA->id,
            'resource' => 'controls', 'name' => 'Second', 'filters' => [],
        ]);

        $this->actingAs($this->officerA)
            ->post(route('saved-views.default', $second))
            ->assertRedirect();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_a_user_cannot_delete_someone_elses_view(): void
    {
        $view = SavedView::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->officerA->id,
            'resource' => 'controls', 'name' => 'Mine', 'filters' => [], 'is_shared' => true,
        ]);

        $this->actingAs($this->officerB)
            ->delete(route('saved-views.destroy', $view))
            ->assertForbidden();

        $this->assertDatabaseHas('saved_views', ['id' => $view->id]);
    }
}
