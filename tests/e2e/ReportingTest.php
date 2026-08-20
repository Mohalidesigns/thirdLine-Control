<?php

namespace Tests\e2e;

use App\Models\SpotCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TC-14 — reporting and export (FR-10.7, FR-10.8, FR-4.4 … FR-4.6).
 *
 * TC-14-10 is the one the plan rates High on any variance: every figure a
 * report prints must reconcile to the source data. The Excel exports are
 * parsed back out of the streamed bytes and counted, rather than trusting
 * that a 200 means the numbers were right.
 */
class ReportingTest extends E2ETestCase
{
    /**
     * Body of a report response. The Excel exports stream; the dompdf
     * reports return an ordinary response, so both shapes are handled.
     */
    private function body(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedResponse
            ? $response->streamedContent()
            : $response->getContent();
    }

    private function download(string $uri, string $account = 'cfh'): string
    {
        $response = $this->actingAs($this->account($account))->get($uri);
        $response->assertOk();

        return $this->body($response);
    }

    /** Row count of the first worksheet, excluding the header row. */
    private function xlsxDataRows(string $bytes): int
    {
        $path = tempnam(sys_get_temp_dir(), 'e2e').'.xlsx';
        file_put_contents($path, $bytes);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            return max(0, $sheet->getHighestDataRow() - 1);
        } finally {
            @unlink($path);
        }
    }

    // -----------------------------------------------------------------
    // TC-14-01 / TC-14-06 — every shipped report generates and exports
    // -----------------------------------------------------------------

    public static function pdfReportProvider(): array
    {
        return [
            'Exception register (PDF)' => ['/reports/exceptions.pdf'],
            'Testing summary (PDF)' => ['/reports/testing-summary.pdf'],
            'Board pack (PDF)' => ['/reports/board-pack?sections[]=dashboard&sections[]=exceptions&sections[]=effectiveness'],
        ];
    }

    #[DataProvider('pdfReportProvider')]
    public function test_tc_14_01_and_06_pdf_reports_generate(string $uri): void
    {
        $bytes = $this->download($uri);

        $this->assertStringStartsWith('%PDF', $bytes, "{$uri} did not return a PDF.");
        $this->assertGreaterThan(1000, strlen($bytes), "{$uri} produced a suspiciously small PDF.");
    }

    public static function xlsxReportProvider(): array
    {
        return [
            'Exceptions (Excel)' => ['/reports/exceptions.xlsx'],
            'Controls (Excel)' => ['/reports/controls.xlsx'],
            'Test instances (Excel)' => ['/reports/test-instances.xlsx'],
        ];
    }

    #[DataProvider('xlsxReportProvider')]
    public function test_tc_14_06_excel_exports_open_cleanly(string $uri): void
    {
        $bytes = $this->download($uri);

        $this->assertStringStartsWith('PK', $bytes, "{$uri} did not return an xlsx archive.");
        $this->assertGreaterThan(0, $this->xlsxDataRows($bytes), "{$uri} produced no data rows.");
    }

    // -----------------------------------------------------------------
    // TC-14-10 — figure reconciliation (High severity on any variance)
    // -----------------------------------------------------------------

    public function test_tc_14_10_the_exception_export_row_count_reconciles_to_the_database(): void
    {
        $expected = (int) DB::table('control_exceptions')
            ->whereNull('deleted_at')
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->count();

        $this->assertSame(
            $expected,
            $this->xlsxDataRows($this->download('/reports/exceptions.xlsx')),
            'TC-14-10: the exception export does not contain one row per exception in the register.'
        );
    }

    public function test_tc_14_10_the_controls_export_row_count_reconciles(): void
    {
        // The export excludes library templates (is_template = true), which
        // is correct: a template is catalogue content a tenant may adopt,
        // not a control the institution operates. Of 37 seeded controls,
        // 24 are templates and 13 are live.
        $expected = (int) DB::table('controls')
            ->whereNull('deleted_at')
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->where('is_template', false)
            ->count();

        $this->assertSame(
            $expected,
            $this->xlsxDataRows($this->download('/reports/controls.xlsx')),
            'TC-14-10: the controls export does not contain one row per operating (non-template) control.'
        );
    }

    public function test_tc_14_10_the_test_instance_export_row_count_reconciles(): void
    {
        $expected = (int) DB::table('test_instances')
            ->whereNull('deleted_at')
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->count();

        $this->assertSame(
            $expected,
            $this->xlsxDataRows($this->download('/reports/test-instances.xlsx')),
            'TC-14-10: the test-instance export does not contain one row per test instance.'
        );
    }

    // -----------------------------------------------------------------
    // TC-14-03 / TC-14-04 — filtering
    // -----------------------------------------------------------------

    public function test_tc_14_03_the_exception_export_honours_a_severity_filter(): void
    {
        $expected = (int) DB::table('control_exceptions')
            ->whereNull('deleted_at')
            ->where('tenant_id', $this->account('cfh')->tenant_id)
            ->where('severity', 'Critical')
            ->count();

        $this->assertGreaterThan(0, $expected, 'Fixture: no Critical exceptions to filter on.');

        $this->assertSame(
            $expected,
            $this->xlsxDataRows($this->download('/reports/exceptions.xlsx?severity=Critical')),
            'TC-14-03: the export ignored the severity filter — the figures do not match the requested scope.'
        );
    }

    public function test_tc_14_04_exports_are_tenant_scoped(): void
    {
        $bytes = $this->download('/reports/controls.xlsx');

        $this->assertStringNotContainsString(
            'MUST NEVER BE VISIBLE',
            $bytes,
            "TC-14-04 CRITICAL: another tenant's control appears in our export."
        );
    }

    // -----------------------------------------------------------------
    // TC-14-05 — spot check findings reach a report
    // -----------------------------------------------------------------

    public function test_tc_14_05_a_spot_check_report_generates_and_carries_its_findings(): void
    {
        $spotCheck = SpotCheck::withoutGlobalScopes()
            ->where('status', 'Report Issued')
            ->withCount('findings')->first();

        if (! $spotCheck) {
            $this->markTestSkipped('No issued spot check in the fixtures.');
        }

        $this->assertGreaterThan(0, $spotCheck->findings_count, 'Fixture: the issued spot check has no findings.');

        $bytes = $this->download('/reports/spot-checks/'.$spotCheck->id.'.pdf');

        $this->assertStringStartsWith('%PDF', $bytes, 'FR-4.4/FR-4.6: the spot check report must render as a PDF.');
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    // -----------------------------------------------------------------
    // TC-14-07 — empty state
    // -----------------------------------------------------------------

    public function test_tc_14_07_a_report_with_no_matching_data_renders_rather_than_erroring(): void
    {
        // A severity/status combination the register does not contain.
        $response = $this->actingAs($this->account('cfh'))
            ->get('/reports/exceptions.pdf?severity=Low&status=Verified-Closed');

        $response->assertOk();

        $this->assertStringStartsWith(
            '%PDF',
            $this->body($response),
            'TC-14-07: a zero-data report must render an empty-state document, not an error.'
        );
    }

    // -----------------------------------------------------------------
    // Authorisation on the reporting surface
    // -----------------------------------------------------------------

    public function test_exports_require_the_export_reports_permission(): void
    {
        $denied = [];

        foreach (['owner', 'manager'] as $account) {
            $response = $this->actingAs($this->account($account))->get('/reports/exceptions.xlsx');

            if ($response->isSuccessful()) {
                $denied[] = $account;
            }
        }

        $this->assertSame(
            [],
            $denied,
            'Roles without "export reports" downloaded the exception register: '.implode(', ', $denied).'. '
            .'An export is the easiest way to remove data from the platform, so it is gated separately '
            .'from viewing.'
        );
    }

    public function test_another_tenant_cannot_export_our_register(): void
    {
        $response = $this->actingAs($this->otherTenantAccount('other_cfh'))->get('/reports/exceptions.xlsx');

        if (! $response->isSuccessful()) {
            $this->assertTrue(true);

            return;
        }

        $this->assertStringNotContainsString(
            'MUST NEVER BE VISIBLE',
            $this->body($response),
            'Cross-tenant export leak.'
        );

        $this->assertSame(
            (int) DB::table('control_exceptions')->whereNull('deleted_at')
                ->where('tenant_id', $this->otherTenantAccount('other_cfh')->tenant_id)->count(),
            $this->xlsxDataRows($this->body($response)),
            "The other tenant's export must contain only their own records."
        );
    }
}
