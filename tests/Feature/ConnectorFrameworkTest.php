<?php

namespace Tests\Feature;

use App\Integrations\ConnectorException;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Contracts\Connector;
use App\Integrations\CoreBanking\FinacleConnector;
use App\Integrations\Generic\ManualUploadConnector;
use App\Integrations\Generic\RestConnector;
use App\Integrations\Generic\SqlConnector;
use App\Integrations\Microsoft\GraphConnector;
use App\Models\AuditTrail;
use App\Models\DataSnapshot;
use App\Models\DataSource;
use App\Models\DataSourceDataset;
use App\Models\IntegrationSyncLog;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\DataSourceFailedNotification;
use App\Services\ConnectorManager;
use App\Services\SnapshotService;
use Carbon\CarbonImmutable;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The connector framework's non-negotiables (12.1, 12.7):
 * credentials never escape, the breaker trips, PII is treated at
 * ingestion, snapshots stay in their country's data plane, and legal
 * hold beats retention.
 */
class ConnectorFrameworkTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sup3r-s3cret-finacle-password';

    private Tenant $tenant;

    private User $cfh;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active', 'data_residency' => 'NG']);
        $this->cfh = $this->makeUser('cfh@test.local', 'Control Function Head');
        $this->officer = $this->makeUser('officer@test.local', 'Control Officer');

        $this->actingAs($this->officer);
    }

    private function makeUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function makeSource(array $overrides = []): DataSource
    {
        return DataSource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Finacle production',
            'source_type' => 'database',
            'system_category' => 'core_banking',
            'vendor_key' => 'finacle',
            'auth_type' => 'basic',
            'connection_config' => ['driver' => 'oracle', 'host' => 'core.bank.internal', 'database' => 'FCRPT'],
            'credentials' => json_encode(['username' => 'svc', 'password' => self::SECRET]),
            'is_active' => true,
            'created_by' => $this->officer->id,
            'approved_by' => $this->cfh->id,
            'approved_at' => now(),
            'failure_threshold' => 3,
            ...$overrides,
        ]);
    }

    // ── Credentials never escape ─────────────────────────────────────

    public function test_credentials_are_encrypted_at_rest_and_never_serialised(): void
    {
        $source = $this->makeSource();

        $raw = DB::table('data_sources')->where('id', $source->id)->first();

        $this->assertNotNull($raw->credentials_encrypted);
        $this->assertStringNotContainsString(self::SECRET, (string) $raw->credentials_encrypted);
        $this->assertStringNotContainsString('core.bank.internal', (string) $raw->connection_config);

        // Round-trips for the connector…
        $this->assertSame(self::SECRET, json_decode($source->fresh()->credentials, true)['password']);
        $this->assertSame('core.bank.internal', $source->fresh()->connection_config['host']);

        // …but never for anything that leaves the server.
        $serialised = json_encode($source->fresh()->toArray());
        $this->assertStringNotContainsString(self::SECRET, $serialised);
        $this->assertStringNotContainsString('core.bank.internal', $serialised);
        $this->assertStringNotContainsString('credentials_encrypted', $serialised);
        $this->assertStringContainsString('stored', $source->fresh()->masked_credentials);
    }

    public function test_the_audit_trail_records_the_change_without_recording_the_secret(): void
    {
        $source = $this->makeSource();
        $source->update(['credentials' => 'a-rotated-secret-value']);

        $rows = AuditTrail::withoutGlobalScopes()
            ->where('entity_type', $source->getMorphClass())
            ->get();

        $this->assertNotEmpty($rows);

        $payload = json_encode($rows->map(fn ($r) => [$r->before, $r->after]));
        $this->assertStringNotContainsString(self::SECRET, $payload);
        $this->assertStringNotContainsString('a-rotated-secret-value', $payload);
        $this->assertStringContainsString('[redacted]', $payload);
    }

    public function test_the_inertia_props_for_a_source_carry_no_credential(): void
    {
        $source = $this->makeSource();

        $response = $this->actingAs($this->cfh)->get(route('admin.data-sources.show', $source));

        $response->assertOk();
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->assertStringNotContainsString('core.bank.internal', $response->getContent());
    }

    public function test_a_driver_error_carrying_a_dsn_is_redacted_before_it_is_logged(): void
    {
        $messages = [
            'SQLSTATE[HY000] [1045] Access denied for user svc password=hunter2 at host',
            'Failed to connect to https://user:hunter2@core.bank.internal/api',
            'Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature rejected',
        ];

        foreach ($messages as $message) {
            $redacted = ConnectorException::redactMessage($message);
            $this->assertStringNotContainsString('hunter2', $redacted);
        }

        $this->assertStringNotContainsString(
            'eyJhbGciOiJIUzI1NiJ9.payload.signature',
            ConnectorException::redactMessage($messages[2]),
        );
    }

    // ── Circuit breaker ──────────────────────────────────────────────

    public function test_the_circuit_breaker_opens_after_the_configured_number_of_failures(): void
    {
        $source = $this->makeSource(['failure_threshold' => 3, 'owner_id' => $this->cfh->id]);
        $connectors = app(ConnectorManager::class);

        $connectors->recordFailure($source, 'ORA-12170 TNS:Connect timeout');
        $this->assertSame('Degraded', $source->fresh()->health_status);
        $this->assertNull($source->fresh()->paused_at);

        $connectors->recordFailure($source->fresh(), 'ORA-12170 TNS:Connect timeout');
        $this->assertNull($source->fresh()->paused_at);

        $connectors->recordFailure($source->fresh(), 'ORA-12170 TNS:Connect timeout');

        $tripped = $source->fresh();
        $this->assertSame('Failed', $tripped->health_status);
        $this->assertNotNull($tripped->paused_at);
        $this->assertSame(3, $tripped->consecutive_failures);
        $this->assertFalse($tripped->isExtractable());

        // Backoff grows rather than hammering a dead endpoint.
        $this->assertGreaterThan(30, $connectors->backoffSeconds($tripped));
        $this->assertLessThanOrEqual(3600, $connectors->backoffSeconds($tripped));

        // And a paused source refuses to extract at all.
        $this->expectException(ConnectorException::class);
        $connectors->assertExtractable($tripped);
    }

    public function test_the_owner_is_notified_when_the_breaker_trips(): void
    {
        $source = $this->makeSource(['failure_threshold' => 1, 'owner_id' => $this->cfh->id]);

        app(ConnectorManager::class)->recordFailure($source, 'gone');

        Notification::assertSentTo($this->cfh, DataSourceFailedNotification::class);
    }

    public function test_a_success_resets_the_breaker_and_a_human_can_resume_a_paused_source(): void
    {
        $source = $this->makeSource(['failure_threshold' => 1]);
        $connectors = app(ConnectorManager::class);

        $connectors->recordFailure($source, 'gone');
        $this->assertNotNull($source->fresh()->paused_at);

        $resumed = $connectors->resume($source->fresh(), $this->cfh);
        $this->assertNull($resumed->paused_at);
        $this->assertSame(0, $resumed->consecutive_failures);

        $connectors->recordSuccess($resumed);
        $this->assertSame('Healthy', $resumed->fresh()->health_status);
    }

    public function test_an_unapproved_source_never_extracts(): void
    {
        $source = $this->makeSource(['approved_at' => null, 'approved_by' => null]);

        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessageMatches('/has not been approved/');

        app(ConnectorManager::class)->assertExtractable($source);
    }

    public function test_the_rate_limit_stops_a_runaway_rule_flooding_a_source(): void
    {
        $source = $this->makeSource(['rate_limit_per_minute' => 2]);
        $connectors = app(ConnectorManager::class);

        $this->assertTrue($connectors->withinRateLimit($source));
        $this->assertTrue($connectors->withinRateLimit($source));
        $this->assertFalse($connectors->withinRateLimit($source));
    }

    public function test_an_extraction_writes_to_the_shared_sync_log(): void
    {
        $source = $this->makeSource();

        app(ConnectorManager::class)->log($source, 'extraction', 'Success', ['rows' => 12]);

        $log = IntegrationSyncLog::withoutGlobalScopes()->latest('id')->first();

        $this->assertSame('ccm:extraction', $log->entity_type);
        $this->assertSame('inbound', $log->direction);
        $this->assertSame(12, $log->payload['rows']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($log->payload));
    }

    // ── Capability honesty ───────────────────────────────────────────

    public function test_the_registry_resolves_a_vendor_and_reports_verification_honestly(): void
    {
        $this->assertSame(FinacleConnector::class, ConnectorRegistry::classFor('finacle', 'database'));
        $this->assertSame(GraphConnector::class, ConnectorRegistry::classFor('entra', 'rest_api'));
        $this->assertSame(SqlConnector::class, ConnectorRegistry::classFor('custom', 'database'));
        $this->assertSame(RestConnector::class, ConnectorRegistry::classFor('custom', 'rest_api'));

        // Priority 1 connectors are exercised; the vendor mappings are not.
        $this->assertTrue(ConnectorRegistry::isVerified('custom', 'rest_api'));
        $this->assertTrue(ConnectorRegistry::isVerified('entra', 'rest_api'));
        $this->assertFalse(ConnectorRegistry::isVerified('finacle', 'database'));
        $this->assertFalse(ConnectorRegistry::isVerified('flexcube', 'database'));
        $this->assertFalse(ConnectorRegistry::isVerified('nibss', 'rest_api'));

        foreach (ConnectorRegistry::matrix() as $capability) {
            $this->assertNotEmpty($capability['label']);
            $this->assertNotEmpty($capability['not_covered'], $capability['label'].' must say what it does not cover.');
        }

        $this->assertInstanceOf(Connector::class, ConnectorRegistry::resolve($this->makeSource()));
    }

    // ── PII treatment at ingestion (12.7) ────────────────────────────

    public function test_sensitive_personal_fields_are_hashed_at_ingestion(): void
    {
        $dataset = $this->makeDataset('sensitive_personal', ['bvn', 'account_number']);
        $snapshots = app(SnapshotService::class);

        $redacted = $snapshots->redactRow(
            ['alert_id' => 'AML1', 'bvn' => '22112345678', 'account_number' => '0123456789', 'scenario' => 'Structuring'],
            $dataset,
        );

        $this->assertStringStartsWith('hash:', $redacted['bvn']);
        $this->assertStringStartsWith('hash:', $redacted['account_number']);
        $this->assertSame('Structuring', $redacted['scenario']);
        $this->assertSame('AML1', $redacted['alert_id']);

        // The hash is stable, so a subject still joins across snapshots…
        $again = $snapshots->redactRow(['bvn' => '22112345678'], $dataset);
        $this->assertSame($redacted['bvn'], $again['bvn']);

        // …and it is not a bare digest of the value.
        $this->assertNotSame('hash:'.substr(hash('sha256', '22112345678'), 0, 32), $redacted['bvn']);
    }

    public function test_a_dpo_authorisation_is_what_permits_retention_in_clear(): void
    {
        $dataset = $this->makeDataset('sensitive_personal', ['bvn']);
        $snapshots = app(SnapshotService::class);

        $this->assertTrue($dataset->requiresHashing());

        $dataset->update(['dpo_authorised_by' => $this->cfh->id, 'dpo_authorised_at' => now()]);

        $this->assertFalse($dataset->fresh()->requiresHashing());
        $this->assertSame('22112345678', $snapshots->redactRow(['bvn' => '22112345678'], $dataset->fresh())['bvn']);
    }

    public function test_a_finding_masks_personal_data_even_when_retention_was_authorised(): void
    {
        $dataset = $this->makeDataset('sensitive_personal', ['account_number']);
        $dataset->update(['dpo_authorised_by' => $this->cfh->id, 'dpo_authorised_at' => now()]);

        $masked = app(SnapshotService::class)->redactForFinding(
            ['account_number' => '0123456789', 'amount' => 500],
            $dataset->fresh(),
        );

        $this->assertSame('••••••6789', $masked['account_number']);
        $this->assertSame(500, $masked['amount']);
    }

    // ── Data residency (12.7) ────────────────────────────────────────

    public function test_a_snapshot_is_written_inside_its_tenants_country_data_plane(): void
    {
        $dataset = $this->makeDataset('none', []);
        $snapshots = app(SnapshotService::class);

        $snapshot = DataSnapshot::create([
            'tenant_id' => $this->tenant->id,
            'dataset_id' => $dataset->id,
            'snapshot_ref' => 'SNP-TEST-001',
            'status' => 'Capturing',
        ]);

        $path = $snapshots->pathFor($dataset, $snapshot);

        $this->assertStringStartsWith(trim((string) config('monitoring.snapshot_root'), '/').'/NG/', $path);
        $this->assertStringContainsString('tenant-'.$this->tenant->id.'/', $path);
        $this->assertStringNotContainsString('..', $path);

        // A tenant in a different residency writes to a different plane, and
        // the two paths can never collide.
        $ghana = Tenant::create(['name' => 'Ghana Bank', 'status' => 'active', 'data_residency' => 'GH']);
        $ghanaSource = DataSource::withoutGlobalScopes()->create([
            'tenant_id' => $ghana->id,
            'name' => 'GH core',
            'source_type' => 'database',
            'system_category' => 'core_banking',
            'vendor_key' => 'custom',
            'auth_type' => 'none',
        ]);
        $ghanaDataset = DataSourceDataset::withoutGlobalScopes()->create([
            'tenant_id' => $ghana->id,
            'data_source_id' => $ghanaSource->id,
            'name' => 'postings',
            'retention_days' => 30,
            'pii_classification' => 'none',
        ]);
        $ghanaSnapshot = DataSnapshot::withoutGlobalScopes()->create([
            'tenant_id' => $ghana->id,
            'dataset_id' => $ghanaDataset->id,
            'snapshot_ref' => 'SNP-TEST-002',
            'status' => 'Capturing',
        ]);

        $ghanaPath = $snapshots->pathFor($ghanaDataset, $ghanaSnapshot);

        $this->assertStringStartsWith(trim((string) config('monitoring.snapshot_root'), '/').'/GH/', $ghanaPath);
        $this->assertNotSame(dirname($path), dirname($ghanaPath));
    }

    // ── Retention and legal hold (12.5, 12.7) ────────────────────────

    public function test_an_expired_snapshot_is_purged_and_its_record_survives(): void
    {
        $snapshot = $this->makeSnapshot(['expires_at' => now()->subDay()->toDateString()]);

        $result = app(SnapshotService::class)->purgeExpired($this->tenant->id);

        $this->assertSame(1, $result['purged']);
        $this->assertSame('Purged', $snapshot->fresh()->status);
        $this->assertNull($snapshot->fresh()->storage_path);
        $this->assertNotNull($snapshot->fresh()->purged_at);
        Storage::disk('local')->assertMissing($snapshot->storage_path);
    }

    public function test_legal_hold_blocks_the_purge_sweep_and_the_model_layer(): void
    {
        $held = $this->makeSnapshot([
            'expires_at' => now()->subDays(10)->toDateString(),
            'legal_hold' => true,
            'legal_hold_reason' => 'CBN examination in progress',
        ]);

        $result = app(SnapshotService::class)->purgeExpired($this->tenant->id);

        $this->assertSame(0, $result['purged']);
        $this->assertSame(1, $result['held']);
        $this->assertSame('Ready', $held->fresh()->status);
        Storage::disk('local')->assertExists($held->storage_path);

        // Calling the purge directly is refused too — the service checks and
        // so does the model, which is the last line.
        try {
            app(SnapshotService::class)->purge($held->fresh());
            $this->fail('A snapshot under legal hold must not be purgeable.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('legal hold', $e->getMessage());
        }

        try {
            $held->fresh()->delete();
            $this->fail('A snapshot under legal hold must not be deletable.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('legal hold', $e->getMessage());
        }
    }

    public function test_the_purge_command_reports_what_it_left_under_hold(): void
    {
        $this->makeSnapshot(['expires_at' => now()->subDay()->toDateString()]);
        $this->makeSnapshot(['expires_at' => now()->subDay()->toDateString(), 'legal_hold' => true]);

        $this->artisan('atheris:purge-snapshots', ['--tenant' => $this->tenant->id])
            ->expectsOutputToContain('1 snapshot(s) purged')
            ->expectsOutputToContain('1 expired snapshot(s) retained under legal hold')
            ->assertExitCode(0);
    }

    public function test_the_dry_run_deletes_nothing(): void
    {
        $snapshot = $this->makeSnapshot(['expires_at' => now()->subDay()->toDateString()]);

        $this->artisan('atheris:purge-snapshots', ['--tenant' => $this->tenant->id, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame('Ready', $snapshot->fresh()->status);
        Storage::disk('local')->assertExists($snapshot->storage_path);
    }

    public function test_a_manual_upload_source_extracts_rows_from_a_workbook_free_of_transport(): void
    {
        // The manual connector needs no network, so it is the one Priority 1
        // transport that can be exercised end to end in a test.
        $source = DataSource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'AML upload',
            'source_type' => 'file_upload',
            'system_category' => 'spreadsheet',
            'vendor_key' => 'custom',
            'auth_type' => 'none',
            'is_active' => true,
            'approved_by' => $this->cfh->id,
            'approved_at' => now(),
        ]);

        $connector = ConnectorRegistry::resolve($source);

        $this->assertInstanceOf(ManualUploadConnector::class, $connector);
        $this->assertTrue($connector->testConnection()['ok']);

        $dataset = DataSourceDataset::create([
            'tenant_id' => $this->tenant->id,
            'data_source_id' => $source->id,
            'name' => 'alerts',
            'extraction_config' => [],
            'retention_days' => 30,
            'pii_classification' => 'none',
        ]);

        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessageMatches('/No workbook has been uploaded/');

        iterator_to_array($connector->extract($dataset, CarbonImmutable::now()), false);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @param array<int, string> $piiFields */
    private function makeDataset(string $classification, array $piiFields): DataSourceDataset
    {
        return DataSourceDataset::create([
            'tenant_id' => $this->tenant->id,
            'data_source_id' => $this->makeSource(['name' => 'Source '.uniqid()])->id,
            'name' => 'dataset_'.uniqid(),
            'retention_days' => 30,
            'pii_classification' => $classification,
            'pii_fields' => $piiFields,
            'is_active' => true,
        ]);
    }

    private function makeSnapshot(array $overrides = []): DataSnapshot
    {
        $dataset = $this->makeDataset('none', []);

        $snapshot = DataSnapshot::create([
            'tenant_id' => $this->tenant->id,
            'dataset_id' => $dataset->id,
            'snapshot_ref' => DataSnapshot::nextReference('SNP', true, 'snapshot_ref'),
            'status' => 'Ready',
            'captured_at' => now()->subDays(40),
            'row_count' => 3,
            'size_bytes' => 64,
            ...$overrides,
        ]);

        $path = app(SnapshotService::class)->pathFor($dataset, $snapshot);
        Storage::disk('local')->put($path, "{\"id\":1}\n{\"id\":2}\n{\"id\":3}\n");
        $snapshot->updateQuietly(['storage_path' => $path]);

        return $snapshot->fresh();
    }
}
