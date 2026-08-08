<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DocumentService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    private User $lineManager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');
        $this->lineManager = $this->makeUser('lm@test.local', 'Line Manager');

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeDocument(array $overrides = []): Document
    {
        return app(DocumentService::class)->create([
            'title' => 'Anti-Bribery Policy',
            'document_type' => 'policy',
            'tenant_id' => $this->tenant->id,
            ...$overrides,
        ], UploadedFile::fake()->create('policy.pdf', 120, 'application/pdf'), $this->officer);
    }

    public function test_document_creation_stores_file_hash_and_first_version(): void
    {
        $document = $this->makeDocument();

        $this->assertSame('Draft', $document->status);
        $this->assertSame(1, $document->version_no);
        $this->assertNotNull($document->file_hash);
        $this->assertSame(1, $document->versions()->count());
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_document_approver_cannot_be_the_document_owner(): void
    {
        $document = $this->makeDocument(['owner_id' => $this->cfh->id]);
        app(DocumentService::class)->submitForReview($document);

        $this->assertFalse($this->cfh->can('approve', $document->fresh()),
            'The owner must never approve their own document');

        $this->expectException(ValidationException::class);
        app(DocumentService::class)->approve($document->fresh(), $this->cfh);
    }

    public function test_a_different_approver_can_approve_and_publish(): void
    {
        $document = $this->makeDocument(['owner_id' => $this->officer->id]);
        $service = app(DocumentService::class);

        $service->submitForReview($document);

        $this->assertTrue($this->cfh->can('approve', $document->fresh()));

        $service->approve($document->fresh(), $this->cfh);
        $document->refresh();

        $this->assertSame('Approved', $document->status);
        $this->assertSame($this->cfh->id, $document->approver_id);

        $service->publish($document, $this->cfh);
        $document->refresh();

        $this->assertSame('Published', $document->status);
        $this->assertNotNull($document->review_due_at, 'Publishing schedules the next review');
    }

    public function test_publishing_supersedes_the_named_predecessor(): void
    {
        $service = app(DocumentService::class);

        $old = $this->makeDocument(['owner_id' => $this->officer->id]);
        $service->submitForReview($old);
        $service->approve($old->fresh(), $this->cfh);
        $service->publish($old->fresh(), $this->cfh);

        $new = $this->makeDocument([
            'title' => 'Anti-Bribery Policy 2027',
            'owner_id' => $this->officer->id,
            'supersedes_document_id' => $old->id,
        ]);
        $service->submitForReview($new);
        $service->approve($new->fresh(), $this->cfh);
        $service->publish($new->fresh(), $this->cfh);

        $this->assertSame('Superseded', $old->fresh()->status);
        $this->assertSame('Published', $new->fresh()->status);
    }

    public function test_new_file_bumps_version_and_returns_to_draft(): void
    {
        $document = $this->makeDocument(['owner_id' => $this->officer->id]);
        $service = app(DocumentService::class);

        $service->submitForReview($document);
        $service->approve($document->fresh(), $this->cfh);

        $service->update($document->fresh(), [],
            UploadedFile::fake()->create('policy-v2.pdf', 130, 'application/pdf'),
            $this->officer, 'Annual refresh');

        $document->refresh();
        $this->assertSame(2, $document->version_no);
        $this->assertSame('Draft', $document->status);
        $this->assertNull($document->approver_id, 'A content change resets the approval');
        $this->assertSame(2, $document->versions()->count());
    }

    public function test_confidential_document_is_hidden_from_roles_not_listed(): void
    {
        $cfhRoleId = Role::findByName('Control Function Head')->id;

        $document = $this->makeDocument([
            'owner_id' => $this->officer->id,
            'is_confidential' => true,
            'access_role_ids' => [$cfhRoleId],
        ]);

        $service = app(DocumentService::class);
        $service->submitForReview($document);
        $service->approve($document->fresh(), $this->cfh);
        $service->publish($document->fresh(), $this->cfh);
        $document->refresh();

        $this->assertTrue($this->cfh->can('view', $document));
        $this->assertTrue($this->officer->can('view', $document), 'The owner always sees their document');
        $this->assertFalse($this->lineManager->can('view', $document), 'Roles off the list are locked out');
        $this->assertFalse($this->lineManager->can('download', $document));

        // Query-level: the index endpoint never lists it for the line manager.
        $this->actingAs($this->lineManager)
            ->get(route('documents.index'))
            ->assertOk()
            ->assertDontSee('Anti-Bribery Policy');
    }

    public function test_download_is_logged_and_counted(): void
    {
        $document = $this->makeDocument(['owner_id' => $this->officer->id]);
        $service = app(DocumentService::class);
        $service->submitForReview($document);
        $service->approve($document->fresh(), $this->cfh);
        $service->publish($document->fresh(), $this->cfh);

        $this->actingAs($this->lineManager)
            ->get(route('documents.download', $document->id))
            ->assertOk();

        $this->assertSame(1, $document->fresh()->download_count);

        $this->assertDatabaseHas('audit_trails', [
            'entity_type' => $document->getMorphClass(),
            'entity_id' => $document->id,
            'action' => 'downloaded',
        ]);
    }
}
