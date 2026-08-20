<?php

namespace App\Reports\Engines;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * One worksheet per tabular section, plus a summary sheet carrying the cover
 * fields, the narrative and the classification — so an analyst who opens
 * only the workbook still sees what the document is and who may read it.
 */
class ExcelEngine implements ReportEngine
{
    public function format(): string
    {
        return 'xlsx';
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function render(array $document): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle(mb_substr($document['title'], 0, 255))
            ->setCreator($document['generated_by'])
            ->setCategory($document['confidentiality']);

        $this->summarySheet($spreadsheet->getActiveSheet(), $document);

        $used = ['Summary'];

        foreach ($document['sections'] as $section) {
            if (! in_array($section['type'], ['table', 'chart'], true)) {
                continue;
            }

            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->uniqueTitle($section['title'] ?: 'Data', $used));
            $this->tableSheet($sheet, $section);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $contents = ob_get_clean();

        $spreadsheet->disconnectWorksheets();

        return $contents;
    }

    public function pageCount(array $document): ?int
    {
        return 1 + count(array_filter(
            $document['sections'],
            fn (array $section) => in_array($section['type'], ['table', 'chart'], true),
        ));
    }

    private function summarySheet($sheet, array $document): void
    {
        $sheet->setTitle('Summary');
        $sheet->setCellValue('A1', $document['title']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $row = 3;

        foreach ([
            'Period' => $document['context']['period'] ?? '',
            'Entity' => $document['context']['entity'] ?? '',
            'Generated' => $document['generated_at'],
            'Prepared by' => $document['generated_by'],
            'Classification' => $document['confidentiality'],
        ] as $label => $value) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->setCellValue("B{$row}", $value);
            $row++;
        }

        $row++;

        foreach ($document['sections'] as $section) {
            if (! in_array($section['type'], ['narrative', 'appendix'], true) || ($section['body'] ?? '') === '') {
                continue;
            }

            $sheet->setCellValue("A{$row}", $section['title']);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
            $sheet->setCellValueExplicit("A{$row}", $section['body'], DataType::TYPE_STRING);
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->getRowDimension($row)->setRowHeight(60);
            $row += 2;
        }

        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(48);
    }

    private function tableSheet($sheet, array $section): void
    {
        $sheet->setCellValue('A1', $section['title']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $columns = $section['table']['columns'];

        if ($columns === []) {
            return;
        }

        $sheet->fromArray($columns, null, 'A3');

        $lastColumn = $sheet->getCell([count($columns), 3])->getCoordinate();
        $sheet->getStyle("A3:{$lastColumn}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A3:{$lastColumn}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0B1F3A');

        $rowIndex = 4;

        foreach ($section['table']['rows'] as $row) {
            $sheet->fromArray(array_values($row), null, "A{$rowIndex}");
            $rowIndex++;
        }

        foreach (range(1, count($columns)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        $sheet->freezePane('A4');
    }

    /** Worksheet names are capped at 31 characters and must be unique. */
    private function uniqueTitle(string $title, array &$used): string
    {
        $clean = preg_replace('/[\\\\\/\?\*\[\]:]/', '-', $title) ?: 'Data';
        $base = mb_substr($clean, 0, 28);
        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $used, true)) {
            $candidate = mb_substr($base, 0, 28).' '.$suffix++;
        }

        $used[] = $candidate;

        return $candidate;
    }
}
