<?php

namespace App\Services;

use App\Models\CheckItem;
use App\Models\Control;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CR-03 §E.4 report 5 — the round trip. The register goes back out in
 * the client's own workbook layout, so the document they gave us stays
 * the document they recognise: same sheets, same five columns, same
 * merged-cell hierarchy where Units and Function are written once and
 * left blank down the continuation rows.
 *
 * Frequency is written back as the bank's OWN wording where we have it
 * (frequency_raw), not ours. A file that comes back saying "Quarterly"
 * where theirs said "Quaterly" is a file they have to reconcile by hand.
 */
class ControlFunctionExportService
{
    /** The columns of the source workbook, in its order. */
    private const HEADINGS = ['S/N', 'Units', 'Function', 'Checklist', 'Frequency of Activity'];

    public function stream(int $tenantId, string $filename = 'Departmental Control Function Checklists.xlsx'): StreamedResponse
    {
        $spreadsheet = $this->build($tenantId);

        return new StreamedResponse(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function build(int $tenantId): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach (ControlFunctionImportService::SHEETS as $sheetName => $config) {
            $this->writeSheet($spreadsheet, $tenantId, $sheetName, $config['unit_code']);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function writeSheet(Spreadsheet $spreadsheet, int $tenantId, string $sheetName, string $unitCode): void
    {
        $sheet = $spreadsheet->createSheet();
        // Excel caps a sheet name at 31 characters; the client's own names
        // fit, but a tenant-renamed unit might not.
        $sheet->setTitle(mb_substr($sheetName, 0, 31));

        // Row 1 is the spacer the client's layout opens with — reproduced
        // so a re-import of our export lands on the same rows.
        $sheet->fromArray(self::HEADINGS, null, 'A2');
        $sheet->getStyle('A2:E2')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A2:E2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A365D');

        $functions = Control::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_control_function', true)
            ->whereHas('controlUnit', fn ($q) => $q->where('code', $unitCode))
            ->with(['homeEntity:id,name', 'controlUnit:id,code,name', 'controlFrequency:id,label'])
            ->orderBy('control_ref')
            ->get();

        $row = 3;
        $serial = 0;
        $lastUnit = null;

        foreach ($functions as $function) {
            // The branch sheet holds one unit; the head office sheet holds
            // a desk per Units value, which is what homeEntity carries.
            $unitName = $function->homeEntity?->name ?? $function->controlUnit?->name ?? '';

            $items = $function->activeTestScript()?->checkItems()->orderBy('sequence')->get() ?? collect();

            if ($items->isEmpty()) {
                continue;
            }

            $serial++;
            $first = true;

            foreach ($items as $item) {
                $sheet->setCellValue("A{$row}", $first ? $serial : null);
                // Written once, blank on the continuation rows — the
                // hierarchy the client's document uses.
                $sheet->setCellValue("B{$row}", $unitName !== $lastUnit ? $unitName : null);
                $sheet->setCellValue("C{$row}", $first ? $function->title : null);
                $sheet->setCellValueExplicit(
                    "D{$row}",
                    $item->question,
                    DataType::TYPE_STRING,
                );
                $sheet->setCellValue("E{$row}", $this->frequencyFor($function, $item));

                if ($unitName !== $lastUnit) {
                    $lastUnit = $unitName;
                }

                $first = false;
                $row++;
            }

            // The blank spacer row that separates functions in the source.
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(32);
        $sheet->getColumnDimension('C')->setWidth(40);
        $sheet->getColumnDimension('D')->setWidth(90);
        $sheet->getColumnDimension('E')->setWidth(22);
        $sheet->getStyle('D3:D'.max(3, $row))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    }

    /**
     * The bank's own wording first: a line that said "bi-annually" goes
     * back out saying "bi-annually", whatever we resolved it to.
     */
    private function frequencyFor(Control $function, CheckItem $item): ?string
    {
        if ($item->frequency_raw !== null) {
            return $item->frequency_raw;
        }

        if ($item->frequency_id) {
            return $item->controlFrequency?->label;
        }

        return $function->frequency_raw ?: $function->controlFrequency?->label ?: $function->frequency;
    }
}
