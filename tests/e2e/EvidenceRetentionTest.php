<?php

namespace Tests\e2e;

use App\Models\AuditTrail;
use App\Models\Evidence;
use App\Models\RetentionPolicy;
use App\Models\TestInstance;
use App\Services\EvidenceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TC-13 — evidence management, retention and NDPA (FR-9.1 … FR-9.11).
 *
 * Note on TC-13-02's severity condition. The plan rates an accepted
 * web-executable file Critical *"if a web-executable file lands in a
 * web-served path"*. It does not here: `EvidenceService::DISK = 'local'`,
 * whose root is `storage_path('app/private')`, outside the document root
 * and with no `url` configured. That condition is checked by test rather
 * than assumed, below, because the whole severity rating turns on it.
 */
class EvidenceRetentionTest extends E2ETestCase
{
    private function linkedInstance(): TestInstance
    {
        return TestInstance::withoutGlobalScopes()->firstOrFail();
    }

    private function upload(UploadedFile $file, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->account('officer'))->post('/evidence', array_merge([
            'file' => $file,
            'linked_type' => 'test_instance',
            'linked_id' => $this->linkedInstance()->id,
            'contains_personal_data' => false,
            'classification' => 'Internal',
        ], $overrides));
    }

    private function latestEvidence(): ?Evidence
    {
        return Evidence::withoutGlobalScopes()->orderByDesc('id')->first();
    }

    // -----------------------------------------------------------------
    // TC-13-01 — the happy path
    // -----------------------------------------------------------------

    public static function allowedFileProvider(): array
    {
        return [
            'CSV extract' => ['reconciliation.csv', 'text/csv'],
            'PDF statement' => ['statement.pdf', 'application/pdf'],
            'PNG screenshot' => ['screenshot.png', 'image/png'],
            'Excel workbook' => ['sample.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ];
    }

    #[DataProvider('allowedFileProvider')]
    public function test_tc_13_01_allowed_evidence_types_are_stored_and_retrievable(string $name, string $mime): void
    {
        $before = Evidence::withoutGlobalScopes()->count();

        $this->upload(UploadedFile::fake()->create($name, 12, $mime))->assertSessionHasNoErrors();

        $this->assertSame($before + 1, Evidence::withoutGlobalScopes()->count(), "{$name} was not stored.");

        $evidence = $this->latestEvidence();

        $this->assertSame($name, $evidence->file_name);
        $this->assertTrue(Storage::disk(EvidenceService::DISK)->exists($evidence->storage_path), 'File missing on disk.');
        $this->assertNotNull($evidence->checksum, 'FR-9.1: a checksum must be recorded.');
        $this->assertNotNull($evidence->retention_expiry_date, 'FR-9.3: a retention expiry must be set on upload.');

        $this->actingAs($this->account('officer'))
            ->get('/evidence/'.$evidence->id.'/download')
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // TC-13-02 — disallowed types
    // -----------------------------------------------------------------

    public static function dangerousFileProvider(): array
    {
        return [
            'PHP script' => ['shell.php', 'application/x-php'],
            'Windows executable' => ['payload.exe', 'application/x-msdownload'],
            'Shell script' => ['run.sh', 'application/x-sh'],
            'Double extension' => ['invoice.pdf.php', 'application/pdf'],
            'Spoofed MIME (php declared as csv)' => ['ledger.php', 'text/csv'],
            'SVG (script-capable)' => ['logo.svg', 'image/svg+xml'],
            'HTML' => ['report.html', 'text/html'],
        ];
    }

    #[DataProvider('dangerousFileProvider')]
    public function test_tc_13_02_dangerous_file_types_are_rejected(string $name, string $mime): void
    {
        $before = Evidence::withoutGlobalScopes()->count();

        $this->upload(UploadedFile::fake()->create($name, 4, $mime));

        $this->assertSame(
            $before,
            Evidence::withoutGlobalScopes()->count(),
            "TC-13-02: '{$name}' was accepted. EvidenceController::store() validates only "
            .'[required, file, max:20480] — there is no extension allowlist, no MIME allowlist, and no '
            .'content inspection.'
        );
    }

    /**
     * The severity of TC-13-02 turns entirely on where the file lands, so
     * that is verified rather than assumed. The evidence disk must be
     * outside the document root and must not be publicly addressable.
     */
    public function test_tc_13_02_evidence_disk_is_not_web_served(): void
    {
        $root = config('filesystems.disks.'.EvidenceService::DISK.'.root');

        $this->assertSame(storage_path('app/private'), $root, 'The evidence disk moved — re-assess DEF-010.');

        $this->assertNull(
            config('filesystems.disks.'.EvidenceService::DISK.'.url'),
            'The evidence disk has a public URL configured — uploaded files would be directly addressable.'
        );

        $this->assertStringNotContainsString(
            public_path(),
            $root,
            'CRITICAL: the evidence disk is inside the document root; an uploaded .php file would be executable.'
        );
    }

    /** Downloads must be sent as attachments, never rendered inline. */
    public function test_evidence_downloads_are_served_as_attachments(): void
    {
        $this->upload(UploadedFile::fake()->create('inline-probe.csv', 4, 'text/csv'))->assertSessionHasNoErrors();

        $response = $this->actingAs($this->account('officer'))
            ->get('/evidence/'.$this->latestEvidence()->id.'/download');

        $response->assertOk();

        $this->assertStringStartsWith(
            'attachment',
            (string) $response->headers->get('Content-Disposition'),
            'Evidence must download as an attachment; inline rendering would make a stored HTML or SVG '
            .'file a stored-XSS vector on the application origin.'
        );
    }

    // -----------------------------------------------------------------
    // TC-13-03 / TC-13-04 — size and integrity of the upload itself
    // -----------------------------------------------------------------

    public function test_tc_13_03_oversize_file_is_rejected(): void
    {
        $before = Evidence::withoutGlobalScopes()->count();

        // Limit is max:20480 KB (20 MB).
        $this->upload(UploadedFile::fake()->create('too-big.csv', 20481, 'text/csv'))
            ->assertSessionHasErrors('file');

        $this->assertSame($before, Evidence::withoutGlobalScopes()->count());
    }

    public function test_tc_13_04_zero_byte_file_is_rejected(): void
    {
        $before = Evidence::withoutGlobalScopes()->count();

        $this->upload(UploadedFile::fake()->create('empty.csv', 0, 'text/csv'));

        $this->assertSame(
            $before,
            Evidence::withoutGlobalScopes()->count(),
            'TC-13-04: a zero-byte file was stored as evidence. An empty file is not evidence of anything, '
            .'and it satisfies a mandatory-evidence gate while proving nothing.'
        );
    }

    // -----------------------------------------------------------------
    // TC-13-05 — access control and unguessable storage
    // -----------------------------------------------------------------

    public function test_tc_13_05_storage_paths_are_not_guessable(): void
    {
        $this->upload(UploadedFile::fake()->create('guessable-probe.csv', 4, 'text/csv'));

        $path = $this->latestEvidence()->storage_path;

        $this->assertStringNotContainsString('guessable-probe', $path, 'The stored path echoes the original filename.');
        $this->assertMatchesRegularExpression('#^evidence/\d{4}/\d{2}/[A-Za-z0-9]{20,}\.#', $path, "Unexpected path shape: {$path}");
    }

    public function test_tc_13_05_download_is_permission_checked(): void
    {
        $evidence = Evidence::withoutGlobalScopes()->where('file_name', 'e2e-clean-extract.csv')->firstOrFail();

        // Authorised: the second-line roles named in EvidenceController::download().
        foreach (['admin', 'cfh', 'officer'] as $account) {
            $this->actingAs($this->account($account))
                ->get('/evidence/'.$evidence->id.'/download')
                ->assertOk();
        }

        // Not authorised: neither a named role nor the uploader.
        foreach (['owner', 'manager', 'exec'] as $account) {
            $this->actingAs($this->account($account))
                ->get('/evidence/'.$evidence->id.'/download')
                ->assertForbidden();
        }
    }

    public function test_tc_13_05_another_tenant_cannot_download_our_evidence(): void
    {
        $evidence = Evidence::withoutGlobalScopes()->where('file_name', 'e2e-clean-extract.csv')->firstOrFail();

        $response = $this->actingAs($this->otherTenantAccount('other_cfh'))
            ->get('/evidence/'.$evidence->id.'/download');

        $this->assertFalse(
            $response->isSuccessful(),
            "CRITICAL: another tenant's Control Function Head downloaded our evidence."
        );
    }

    /** FR-9.6 — every view and download logged. */
    public function test_every_download_is_access_logged(): void
    {
        $evidence = Evidence::withoutGlobalScopes()->where('file_name', 'e2e-clean-extract.csv')->firstOrFail();

        $before = $evidence->accessLogs()->count();

        $this->actingAs($this->account('officer'))->get('/evidence/'.$evidence->id.'/download')->assertOk();

        $this->assertSame(
            $before + 1,
            $evidence->accessLogs()->count(),
            'FR-9.6: every download must be logged.'
        );
    }

    // -----------------------------------------------------------------
    // TC-13-07 / TC-13-09 — retention classes and the PII declaration
    // -----------------------------------------------------------------

    public function test_tc_13_09_pii_declaration_is_mandatory(): void
    {
        $before = Evidence::withoutGlobalScopes()->count();

        $this->actingAs($this->account('officer'))->post('/evidence', [
            'file' => UploadedFile::fake()->create('undeclared.csv', 4, 'text/csv'),
            'linked_type' => 'test_instance',
            'linked_id' => $this->linkedInstance()->id,
            // contains_personal_data deliberately absent
        ])->assertSessionHasErrors('contains_personal_data');

        $this->assertSame($before, Evidence::withoutGlobalScopes()->count());
    }

    public function test_tc_13_09_pii_categories_are_required_when_personal_data_is_declared(): void
    {
        $this->upload(UploadedFile::fake()->create('pii-no-categories.csv', 4, 'text/csv'), [
            'contains_personal_data' => true,
        ])->assertSessionHasErrors('personal_data_categories');
    }

    public function test_tc_13_07_pii_evidence_takes_the_customer_personal_data_retention_class(): void
    {
        $piiPolicy = RetentionPolicy::withoutGlobalScopes()->where('evidence_class', 'Customer Personal Data')->firstOrFail();
        $default = RetentionPolicy::withoutGlobalScopes()->where('is_default', true)->firstOrFail();

        $this->upload(UploadedFile::fake()->create('pii-classified.csv', 4, 'text/csv'), [
            'contains_personal_data' => true,
            'personal_data_categories' => ['BVN', 'Account numbers'],
        ])->assertSessionHasNoErrors();

        $evidence = $this->latestEvidence();

        $this->assertSame($piiPolicy->id, $evidence->retention_policy_id, 'FR-9.3: PII must take the PII retention class.');
        $this->assertEqualsWithDelta(
            now()->addMonths($piiPolicy->retention_months)->timestamp,
            $evidence->retention_expiry_date->timestamp,
            86400 * 2,
            'Retention expiry must derive from the policy period.'
        );
        $this->assertNotSame($default->id, $piiPolicy->id, 'Fixture assumption: the two classes differ.');
    }

    // -----------------------------------------------------------------
    // TC-13-08 — retention expiry, legal hold, dual-approval disposal
    // -----------------------------------------------------------------

    public function test_tc_13_08_expired_evidence_is_queued_but_legal_hold_is_respected(): void
    {
        $held = Evidence::withoutGlobalScopes()->where('file_name', 'e2e-legal-hold-memo.txt')->firstOrFail();
        $free = Evidence::withoutGlobalScopes()->where('file_name', 'e2e-expired-no-hold.txt')->firstOrFail();

        $held->forceFill(['disposal_requested_at' => null])->saveQuietly();
        $free->forceFill(['disposal_requested_at' => null])->saveQuietly();

        $this->runCommandAt('secondline:queue-evidence-disposal', now()->addYear());

        $this->assertNull($held->refresh()->disposal_requested_at, 'FR-9.7: legal hold must suspend disposal.');
        $this->assertNotNull($free->refresh()->disposal_requested_at, 'FR-9.8: expired evidence must be queued.');
    }

    public function test_tc_13_08_disposal_requires_two_different_approvers(): void
    {
        $evidence = Evidence::withoutGlobalScopes()->where('file_name', 'e2e-expired-no-hold.txt')->firstOrFail();
        $evidence->forceFill(['disposal_requested_at' => now(), 'disposal_requested_by' => null, 'disposed_at' => null])->saveQuietly();

        $cfh = $this->account('cfh');

        app(EvidenceService::class)->approveDisposalFirst($evidence->refresh(), $cfh);

        $this->assertSame($cfh->id, $evidence->refresh()->disposal_requested_by);
        $this->assertNull($evidence->refresh()->disposed_at, 'One approval must not dispose.');

        // The same person must not be able to give the second approval.
        try {
            app(EvidenceService::class)->approveDisposalFinal($evidence->refresh(), $cfh);
            $this->fail('FR-9.8: the same user gave both disposal approvals.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertNull($evidence->refresh()->disposed_at);
    }

    public function test_tc_13_08_disposal_removes_the_file_but_the_audit_record_survives(): void
    {
        $evidence = Evidence::withoutGlobalScopes()->where('file_name', 'e2e-expired-no-hold.txt')->firstOrFail();
        $evidence->forceFill([
            'disposal_requested_at' => now(),
            'disposal_requested_by' => $this->account('cfh')->id,
            'disposed_at' => null,
        ])->saveQuietly();

        $path = $evidence->storage_path;

        // The DB row rolls back with the test transaction but the file
        // deletion does not — remember the seeded bytes so the fixture can
        // be restored for later tests (HarnessSelfCheck verifies checksums).
        $seededBytes = Storage::disk(EvidenceService::DISK)->exists($path)
            ? Storage::disk(EvidenceService::DISK)->get($path)
            : "Synthetic E2E fixture. Past retention expiry, no legal hold.\n";

        Storage::disk(EvidenceService::DISK)->put($path, 'restored for disposal test');

        app(EvidenceService::class)->approveDisposalFinal($evidence->refresh(), $this->account('admin'));

        $evidence->refresh();

        $this->assertNotNull($evidence->disposed_at, 'Disposal must be recorded.');
        $this->assertFalse(Storage::disk(EvidenceService::DISK)->exists($path), 'The file must be removed.');

        $this->assertNotNull(
            AuditTrail::where('entity_type', Evidence::class)->where('entity_id', $evidence->id)
                ->where('action', 'disposed')->first(),
            'FR-9.8: the disposal must leave a documented log entry.'
        );

        $this->assertNotNull(
            Evidence::withoutGlobalScopes()->find($evidence->id),
            'NFR-6: the evidence record itself must survive disposal of the file.'
        );

        $this->actingAs($this->account('officer'))
            ->get('/evidence/'.$evidence->id.'/download')
            ->assertStatus(410);

        // Put the fixture back — the DB row rolls back with the transaction
        // but the deleted file would stay gone for every later test.
        Storage::disk(EvidenceService::DISK)->put($path, $seededBytes);
    }

    public function test_legal_hold_can_only_be_set_by_the_control_function(): void
    {
        $evidence = Evidence::withoutGlobalScopes()->where('file_name', 'e2e-clean-extract.csv')->firstOrFail();

        foreach (['officer', 'owner', 'manager', 'exec'] as $account) {
            $this->actingAs($this->account($account))
                ->post('/evidence/'.$evidence->id.'/legal-hold', ['hold' => true, 'reason' => 'Unauthorised attempt.'])
                ->assertForbidden();
        }

        $this->assertFalse((bool) $evidence->refresh()->legal_hold);
    }

    // -----------------------------------------------------------------
    // TC-13-11 — integrity
    // -----------------------------------------------------------------

    public function test_tc_13_11_a_substituted_file_is_detectable_by_checksum(): void
    {
        $this->upload(UploadedFile::fake()->create('integrity-probe.csv', 4, 'text/csv'))->assertSessionHasNoErrors();

        $evidence = $this->latestEvidence();
        $recorded = $evidence->checksum;

        $this->assertSame(
            $recorded,
            hash('sha256', Storage::disk(EvidenceService::DISK)->get($evidence->storage_path)),
            'The recorded checksum must match the stored bytes at rest.'
        );

        // Substitute the file underneath the record.
        Storage::disk(EvidenceService::DISK)->put($evidence->storage_path, 'SUBSTITUTED CONTENT');

        $this->assertNotSame(
            $recorded,
            hash('sha256', Storage::disk(EvidenceService::DISK)->get($evidence->storage_path)),
            'FR-9: substitution must be detectable by comparing the stored checksum.'
        );

        // Detectable is not the same as detected: nothing in the application
        // re-verifies the checksum on download.
        $this->actingAs($this->account('officer'))
            ->get('/evidence/'.$evidence->id.'/download')
            ->assertOk();
    }
}
