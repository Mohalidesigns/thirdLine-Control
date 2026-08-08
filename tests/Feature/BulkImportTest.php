<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\OrganisationUnit;
use App\Models\Regulator;
use App\Models\RegulatoryObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImportService;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class BulkImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Test Bank', 'status' => 'active']);

        $this->admin = User::factory()->create(['email' => 'admin@test.local', 'tenant_id' => $this->tenant->id]);
        $this->admin->assignRole('System Administrator');

        $this->actingAs($this->admin);
    }

    /** Build an .xlsx UploadedFile from rows (header + data). */
    private function makeSpreadsheet(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $index => $row) {
            $sheet->fromArray($row, null, 'A'.($index + 1));
        }

        $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function controlsHeader(): array
    {
        return ['Title *', 'Description', 'Type *', 'Nature *', 'Frequency *', 'Category',
            'Entity code', 'Owner email', 'Key control (Yes/No)', 'Function grouping', 'Control level'];
    }

    public function test_dry_run_reports_row_level_errors_and_writes_nothing(): void
    {
        $file = $this->makeSpreadsheet([
            $this->controlsHeader(),
            ['Good control', '', 'Preventive', 'Manual', 'Monthly', '', '', '', 'Yes', '', ''],
            ['', '', 'NotAType', 'Manual', 'Monthly', '', '', '', '', '', ''],
            ['Bad entity', '', 'Detective', 'Manual', 'Daily', '', 'NOPE', '', '', '', ''],
        ]);

        $report = app(ImportService::class)->dryRun('controls', $file, $this->admin);

        $this->assertSame(3, $report['rows']);
        $this->assertSame(1, $report['valid']);
        $this->assertArrayHasKey(3, $report['errors'], 'Row 3 (missing title, bad type) must be reported');
        $this->assertArrayHasKey(4, $report['errors'], 'Row 4 (unknown entity code) must be reported');
        $this->assertStringContainsString('Title is required', implode(' ', $report['errors'][3]));

        $this->assertSame(0, Control::withoutGlobalScopes()->count(), 'A dry-run must write nothing');
    }

    public function test_import_rolls_back_completely_on_a_mid_file_failure(): void
    {
        $file = $this->makeSpreadsheet([
            $this->controlsHeader(),
            ['First valid control', '', 'Preventive', 'Manual', 'Monthly', '', '', '', 'No', '', ''],
            ['Second valid control', '', 'Detective', 'Automated', 'Daily', '', '', '', 'No', '', ''],
            ['Broken row', '', 'NotAType', 'Manual', 'Monthly', '', '', '', '', '', ''],
        ]);

        try {
            app(ImportService::class)->import('controls', $file, $this->admin);
            $this->fail('The import should have failed on row 4');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('row_4', $e->errors());
        }

        $this->assertSame(0, Control::withoutGlobalScopes()->count(),
            'A mid-file failure must roll back every previously written row');
    }

    public function test_successful_import_creates_rows_and_an_audit_entry(): void
    {
        OrganisationUnit::create(['tenant_id' => $this->tenant->id, 'code' => 'HQ', 'name' => 'Head Office', 'type' => 'Head Office']);

        $file = $this->makeSpreadsheet([
            $this->controlsHeader(),
            ['Recon control', 'Daily recon', 'Detective', 'Manual', 'Daily', '', 'HQ', 'admin@test.local', 'Yes', 'Detect', 'Operational'],
            ['Access review', '', 'Preventive', 'IT-Dependent Manual', 'Quarterly', '', '', '', 'No', 'Protect', ''],
        ]);

        $result = app(ImportService::class)->import('controls', $file, $this->admin);

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, Control::withoutGlobalScopes()->count());

        $control = Control::withoutGlobalScopes()->where('title', 'Recon control')->first();
        $this->assertSame('Draft', $control->status, 'Imported controls arrive as drafts for maker-checker');
        $this->assertTrue($control->is_key_control);
        $this->assertSame('HQ', $control->unit->code);
        $this->assertSame($this->admin->id, $control->owner_id);

        $this->assertDatabaseHas('audit_trails', [
            'entity_type' => $this->tenant->getMorphClass(),
            'entity_id' => $this->tenant->id,
            'action' => 'bulk_imported',
        ]);
    }

    public function test_imported_obligations_arrive_unverified(): void
    {
        Regulator::create([
            'tenant_id' => null, 'code' => 'CBN', 'name' => 'Central Bank of Nigeria',
            'jurisdiction' => 'NG',
        ]);

        $file = $this->makeSpreadsheet([
            ['Title *', 'Description', 'Regulator code *', 'Obligation type *', 'Frequency *', 'Legal reference', 'Penalty description'],
            ['Monthly return', '', 'CBN', 'filing', 'monthly', '', ''],
        ]);

        app(ImportService::class)->import('obligations', $file, $this->admin);

        $obligation = RegulatoryObligation::withoutGlobalScopes()->first();
        $this->assertSame('unverified', $obligation->verification_status,
            'R10: imported regulatory facts ship as unverified');
    }

    public function test_import_endpoints_require_the_permission(): void
    {
        $officer = User::factory()->create(['email' => 'officer@test.local', 'tenant_id' => $this->tenant->id]);
        $officer->assignRole('Control Officer');

        $this->actingAs($officer)->get(route('admin.import'))->assertForbidden();
        $this->actingAs($officer)->get(route('admin.import.template', 'controls'))->assertForbidden();
    }

    public function test_template_download_streams_a_spreadsheet(): void
    {
        $this->get(route('admin.import.template', 'controls'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
