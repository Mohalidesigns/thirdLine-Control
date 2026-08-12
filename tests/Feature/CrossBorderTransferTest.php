<?php

namespace Tests\Feature;

use App\Models\CrossBorderTransfer;
use App\Models\Tenant;
use App\Models\TransferLawfulBasis;
use App\Models\User;
use App\Services\CrossBorderTransferService;
use App\Services\ResidencyAttestationService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TransferLawfulBasisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CrossBorderTransferTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(TransferLawfulBasisSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Transfer Bank', 'status' => 'active', 'data_residency' => 'NG']);

        $this->cfh = $this->makeUser('cfh@transfer.test', 'Control Function Head');
        $this->officer = $this->makeUser('officer@transfer.test', 'Control Officer');
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return [
            'direction' => 'outbound',
            'data_categories' => ['reports'],
            'contains_personal_data' => false,
            'description' => 'Group reporting extract.',
            'destination_country' => 'GH',
            'recipient' => 'Group Shared Services',
            'lawful_basis_id' => TransferLawfulBasis::withoutGlobalScopes()->where('code', 'NON-PERSONAL')->value('id'),
            ...$overrides,
        ];
    }

    public function test_a_transfer_without_a_lawful_basis_is_blocked(): void
    {
        $this->actingAs($this->officer);

        // Missing entirely — blocked at validation before the service.
        $this->post(route('residency.transfers.store'), $this->payload(['lawful_basis_id' => null]))
            ->assertSessionHasErrors('lawful_basis_id');

        // Present but unusable (inactive) — blocked in the service.
        $basis = TransferLawfulBasis::withoutGlobalScopes()->where('code', 'CONSENT')->first();
        $basis->update(['is_active' => false]);

        try {
            app(CrossBorderTransferService::class)->record(
                $this->payload(['lawful_basis_id' => $basis->id]),
                $this->officer,
            );
            $this->fail('Expected the transfer to be blocked.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, CrossBorderTransfer::withoutGlobalScopes()->count());
    }

    public function test_the_recorder_cannot_authorise_their_own_transfer(): void
    {
        $this->actingAs($this->cfh);

        $transfer = app(CrossBorderTransferService::class)->record($this->payload(), $this->cfh);

        // The CFH holds 'authorise transfers' — and is still refused (R2).
        $this->post(route('residency.transfers.authorise', $transfer))
            ->assertForbidden();

        $this->assertSame('pending_authorisation', $transfer->fresh()->status);
    }

    public function test_a_second_person_authorises_and_completes(): void
    {
        $this->actingAs($this->officer);

        $transfer = app(CrossBorderTransferService::class)->record($this->payload(), $this->officer);

        // The officer lacks 'authorise transfers' — route middleware refuses.
        $this->post(route('residency.transfers.authorise', $transfer))->assertForbidden();

        $this->actingAs($this->cfh)
            ->post(route('residency.transfers.authorise', $transfer))
            ->assertRedirect();

        $this->assertSame('authorised', $transfer->fresh()->status);

        $this->actingAs($this->officer)
            ->post(route('residency.transfers.complete', $transfer))
            ->assertRedirect();

        $this->assertSame('completed', $transfer->fresh()->status);
    }

    public function test_a_transfer_cannot_complete_before_authorisation(): void
    {
        $this->actingAs($this->officer);

        $transfer = app(CrossBorderTransferService::class)->record($this->payload(), $this->officer);

        $this->post(route('residency.transfers.complete', $transfer))->assertForbidden();

        $this->assertSame('pending_authorisation', $transfer->fresh()->status);
    }

    public function test_attestation_is_signed_and_tamper_evident(): void
    {
        $this->actingAs($this->cfh);

        $attestation = app(ResidencyAttestationService::class)->generate(
            $this->tenant,
            $this->cfh,
            now()->startOfQuarter()->toDateString(),
            now()->endOfQuarter()->toDateString(),
        );

        $this->assertTrue($attestation->verifySignature());
        $this->assertSame('NG', $attestation->declared_country);

        // Tampering with the stored body breaks the signature.
        $content = $attestation->content;
        $content['declared_country'] = 'EU';
        $attestation->forceFill(['content' => $content])->saveQuietly();

        $this->assertFalse($attestation->fresh()->verifySignature());
    }

    public function test_attestation_pdf_downloads(): void
    {
        $this->actingAs($this->cfh);

        $attestation = app(ResidencyAttestationService::class)->generate(
            $this->tenant, $this->cfh,
            now()->startOfQuarter()->toDateString(),
            now()->endOfQuarter()->toDateString(),
        );

        $this->get(route('residency.attestations.download', $attestation))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
