<?php

namespace Tests\Feature;

use App\Models\ControlException;
use App\Models\Evidence;
use App\Models\RetentionPolicy;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EvidenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EvidenceManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $secondCfh;

    private ControlException $exception;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->cfh = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->cfh->assignRole('Control Function Head');

        $this->secondCfh = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->secondCfh->assignRole('Control Function Head');

        $this->exception = ControlException::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'EXC-9001',
            'source_type' => 'Manual',
            'title' => 'Evidence host exception',
            'severity' => 'Medium',
            'date_raised' => now()->toDateString(),
            'status' => 'Open',
        ]);
    }

    private function makeEvidence(array $overrides = []): Evidence
    {
        return Evidence::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'linked_type' => ControlException::class,
            'linked_id' => $this->exception->id,
            'file_name' => 'proof.pdf',
            'storage_path' => 'evidence/proof.pdf',
            'contains_personal_data' => false,
            'uploaded_by' => $this->cfh->id,
            'uploaded_at' => now(),
            ...$overrides,
        ]);
    }

    // ── FR-9.2: mandatory PII declaration ────────────────────────────

    public function test_upload_is_blocked_without_the_personal_data_declaration(): void
    {
        $this->actingAs($this->cfh)
            ->post(route('evidence.store'), [
                'file' => UploadedFile::fake()->create('extract.pdf', 100),
                'linked_type' => 'exception',
                'linked_id' => $this->exception->id,
                // contains_personal_data deliberately omitted
            ])
            ->assertSessionHasErrors('contains_personal_data');

        $this->assertSame(0, Evidence::withoutGlobalScopes()->count());
    }

    public function test_pii_upload_requires_data_categories(): void
    {
        $this->actingAs($this->cfh)
            ->post(route('evidence.store'), [
                'file' => UploadedFile::fake()->create('extract.pdf', 100),
                'linked_type' => 'exception',
                'linked_id' => $this->exception->id,
                'contains_personal_data' => true,
                // categories omitted
            ])
            ->assertSessionHasErrors('personal_data_categories');
    }

    public function test_valid_upload_stores_checksum_and_retention_expiry(): void
    {
        RetentionPolicy::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Default',
            'evidence_class' => 'General',
            'retention_months' => 12,
            'is_default' => true,
        ]);

        $this->actingAs($this->cfh)
            ->post(route('evidence.store'), [
                'file' => UploadedFile::fake()->create('extract.pdf', 100),
                'linked_type' => 'exception',
                'linked_id' => $this->exception->id,
                'contains_personal_data' => false,
            ])
            ->assertSessionHasNoErrors();

        $evidence = Evidence::withoutGlobalScopes()->sole();
        $this->assertNotNull($evidence->checksum);
        $this->assertNotNull($evidence->retention_expiry_date);
        Storage::disk('local')->assertExists($evidence->storage_path);
    }

    // ── FR-9.7: legal hold overrides disposal absolutely ─────────────

    public function test_evidence_under_legal_hold_cannot_be_deleted_or_disposed(): void
    {
        $evidence = $this->makeEvidence(['legal_hold' => true, 'retention_expiry_date' => now()->subYear()]);

        // Not queued by the disposal job.
        $this->assertSame(0, app(EvidenceService::class)->queueExpiredForDisposal());

        // Not disposable through the workflow.
        try {
            app(EvidenceService::class)->approveDisposalFirst($evidence, $this->cfh);
            $this->fail('Disposal approval should be blocked under legal hold.');
        } catch (ValidationException) {
            // expected
        }

        // Not even deletable at the model layer.
        $this->expectException(\LogicException::class);
        $evidence->delete();
    }

    // ── FR-9.8: dual approval before permanent deletion ──────────────

    public function test_disposal_requires_two_different_approvers(): void
    {
        Storage::disk('local')->put('evidence/proof.pdf', 'content');
        $evidence = $this->makeEvidence(['retention_expiry_date' => now()->subMonth()]);

        $service = app(EvidenceService::class);
        $service->approveDisposalFirst($evidence, $this->cfh);

        // Same approver cannot give the final sign-off.
        try {
            $service->approveDisposalFinal($evidence->fresh(), $this->cfh);
            $this->fail('The same user must not give both disposal approvals.');
        } catch (ValidationException) {
            // expected
        }

        $service->approveDisposalFinal($evidence->fresh(), $this->secondCfh);

        $fresh = $evidence->fresh();
        $this->assertNotNull($fresh->disposed_at);
        $this->assertSame($this->secondCfh->id, $fresh->disposal_approved_by);
        Storage::disk('local')->assertMissing('evidence/proof.pdf');
    }

    public function test_final_disposal_without_first_approval_is_rejected(): void
    {
        $evidence = $this->makeEvidence();

        $this->expectException(ValidationException::class);
        app(EvidenceService::class)->approveDisposalFinal($evidence, $this->cfh);
    }
}
