<?php

namespace App\Reports\Engines;

use App\Models\TenantBranding;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;
use PhpOffice\PhpWord\Style\Tab;

/**
 * The editable form (13.3). A compliance officer who has to add a paragraph
 * before filing needs a document, not a picture of one — which is the whole
 * reason Word is an output format alongside PDF.
 *
 * Charts are native Word charts rather than rasterised images, so the figures
 * stay legible when the document is printed or re-scaled, and each is
 * followed by its table view.
 */
class WordEngine implements ReportEngine
{
    private const NAVY = '1A365D';

    private const GOLD = 'C9A227';

    public function format(): string
    {
        return 'docx';
    }

    public function extension(): string
    {
        return 'docx';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    public function render(array $document): string
    {
        $branding = TenantBranding::first();
        $word = new PhpWord;

        $word->getSettings()->setThemeFontLang(new Language(Language::EN_GB));
        $word->getDocInfo()
            ->setTitle($document['title'])
            ->setCreator($document['generated_by'])
            ->setCategory($document['confidentiality']);

        $this->registerStyles($word);

        $section = $word->addSection([
            'marginTop' => 900, 'marginBottom' => 900,
            'marginLeft' => 900, 'marginRight' => 900,
        ]);

        $header = $section->addHeader();
        $header->addText(
            $branding?->product_name ?? $document['header']['title'] ?? config('app.name'),
            ['bold' => true, 'color' => self::NAVY, 'size' => 9],
        );

        $footer = $section->addFooter();
        $footer->addPreserveText(
            trim(($document['footer']['text'] ?? $document['confidentiality']).'  ·  Page {PAGE} of {NUMPAGES}'),
            ['size' => 8, 'color' => '718096'],
            ['alignment' => Jc::CENTER],
        );

        foreach ($document['sections'] as $block) {
            $this->block($section, $block, $document);
        }

        $writer = IOFactory::createWriter($word, 'Word2007');

        ob_start();
        $writer->save('php://output');
        $contents = ob_get_clean();

        return $contents;
    }

    public function pageCount(array $document): ?int
    {
        return null;
    }

    private function registerStyles(PhpWord $word): void
    {
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);

        $word->addTitleStyle(1, ['size' => 18, 'bold' => true, 'color' => self::NAVY]);
        $word->addTitleStyle(2, ['size' => 13, 'bold' => true, 'color' => self::NAVY], ['spaceBefore' => 240]);

        $word->addTableStyle('AtherisTable', [
            'borderColor' => 'E2E8F0', 'borderSize' => 6, 'cellMargin' => 60,
        ], ['bgColor' => self::NAVY]);

        $word->addTableStyle('AtherisPlain', ['cellMargin' => 60, 'borderSize' => 0]);
    }

    private function block($section, array $block, array $document): void
    {
        match ($block['type']) {
            'cover' => $this->cover($section, $block, $document),
            'toc' => $this->toc($section, $block),
            'narrative', 'appendix' => $this->narrative($section, $block),
            'kpi_row' => $this->kpiRow($section, $block),
            'table' => $this->table($section, $block),
            'chart' => $this->chart($section, $block),
            'page_break' => $section->addPageBreak(),
            'signature_block' => $this->signatures($section, $block),
            default => null,
        };
    }

    private function cover($section, array $block, array $document): void
    {
        $section->addTextBreak(4);
        $section->addText($block['title'], ['size' => 26, 'bold' => true, 'color' => self::NAVY], ['alignment' => Jc::CENTER]);

        if ($block['subtitle']) {
            $section->addText($block['subtitle'], ['size' => 12, 'color' => self::GOLD], ['alignment' => Jc::CENTER]);
        }

        $section->addTextBreak(2);

        $table = $section->addTable('AtherisPlain');

        foreach ($block['fields'] as $field) {
            $row = $table->addRow();
            $row->addCell(3000)->addText($field['label'], ['bold' => true, 'size' => 9, 'color' => '718096']);
            $row->addCell(6000)->addText((string) $field['value'], ['size' => 9]);
        }

        $section->addTextBreak(2);
        $section->addText(
            'Classification: '.$document['confidentiality'],
            ['size' => 9, 'bold' => true, 'color' => 'C53030'],
            ['alignment' => Jc::CENTER],
        );
        $section->addPageBreak();
    }

    private function toc($section, array $block): void
    {
        $section->addText($block['title'], ['size' => 13, 'bold' => true, 'color' => self::NAVY]);
        $section->addTOC(['size' => 10], ['tabLeader' => Tab::TAB_LEADER_DOT], 1, 2);
        $section->addTextBreak(1);
    }

    private function narrative($section, array $block): void
    {
        if ($block['title']) {
            $section->addTitle($block['title'], 2);
        }

        foreach (preg_split('/\n{2,}/', $block['body'] ?? '') ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $section->addText(trim($paragraph), [], ['spaceAfter' => 120]);
            }
        }
    }

    private function kpiRow($section, array $block): void
    {
        if ($block['title']) {
            $section->addTitle($block['title'], 2);
        }

        if ($block['items'] === []) {
            return;
        }

        $table = $section->addTable('AtherisPlain');
        $row = $table->addRow();
        $width = (int) (9000 / max(1, count($block['items'])));

        foreach ($block['items'] as $item) {
            $cell = $row->addCell($width, ['bgColor' => 'F7FAFC']);
            $cell->addText((string) $item['value'], ['size' => 18, 'bold' => true, 'color' => self::NAVY]);
            $cell->addText($item['label'], ['size' => 8, 'color' => '718096']);

            if ($item['caption']) {
                $cell->addText($item['caption'], ['size' => 7, 'color' => 'A0AEC0']);
            }
        }

        $section->addTextBreak(1);
    }

    private function table($section, array $block): void
    {
        if ($block['title']) {
            $section->addTitle($block['title'], 2);
        }

        $this->dataTable($section, $block['table']);
    }

    private function chart($section, array $block): void
    {
        if ($block['title']) {
            $section->addTitle($block['title'], 2);
        }

        $payload = $block['data'];
        $categories = array_column($payload['categories'] ?? [], 'label');
        $series = $payload['series'] ?? [];

        if ($categories !== [] && $series !== []) {
            $chart = $section->addChart(
                $this->chartType($block['chart_type'] ?? 'bar'),
                $categories,
                array_map(fn ($value) => is_numeric($value) ? $value + 0 : 0, $series[0]['values'] ?? []),
                [
                    'width' => Converter::cmToEmu(16),
                    'height' => Converter::cmToEmu(8),
                    'showAxisLabels' => true,
                    'showGridX' => false,
                    'showLegend' => count($series) > 1,
                    'colors' => array_values(config('charts.categorical')),
                ],
                $series[0]['label'] ?? null,
            );

            foreach (array_slice($series, 1) as $extra) {
                $chart->addSeries(
                    $categories,
                    array_map(fn ($value) => is_numeric($value) ? $value + 0 : 0, $extra['values'] ?? []),
                    $extra['label'] ?? null,
                );
            }

            $section->addTextBreak(1);
        }

        // The chart's table twin — the WCAG-clean equivalent, and the only
        // reading of the figures that survives a greyscale printout.
        $this->dataTable($section, $block['table']);
    }

    private function chartType(string $type): string
    {
        return match ($type) {
            'line', 'sparkline' => 'line',
            'donut' => 'doughnut',
            'pie' => 'pie',
            'horizontal_bar' => 'bar',
            'stacked_bar' => 'stacked_bar',
            'area' => 'area',
            default => 'column',
        };
    }

    private function dataTable($section, array $table): void
    {
        if (($table['columns'] ?? []) === []) {
            return;
        }

        $element = $section->addTable('AtherisTable');
        $header = $element->addRow();

        foreach ($table['columns'] as $column) {
            $header->addCell(null, ['bgColor' => self::NAVY])
                ->addText((string) $column, ['bold' => true, 'color' => 'FFFFFF', 'size' => 8]);
        }

        foreach (array_slice($table['rows'] ?? [], 0, 300) as $row) {
            $line = $element->addRow();

            foreach ($row as $cell) {
                $line->addCell()->addText((string) ($cell ?? '—'), ['size' => 8]);
            }
        }

        $section->addTextBreak(1);
    }

    private function signatures($section, array $block): void
    {
        $section->addTitle($block['title'], 2);
        $section->addTextBreak(1);

        $table = $section->addTable('AtherisPlain');

        foreach (array_chunk($block['signatories'], 2) as $pair) {
            $row = $table->addRow();

            foreach ($pair as $signatory) {
                $cell = $row->addCell(4500);
                $cell->addTextBreak(2);
                $cell->addText(str_repeat('_', 34), ['color' => '718096']);
                $cell->addText($signatory['name'] ?? '', ['bold' => true, 'size' => 9]);
                $cell->addText($signatory['role'], ['size' => 8, 'color' => '718096']);
                $cell->addText('Date: ____________________', ['size' => 8, 'color' => '718096']);
            }

            if (count($pair) === 1) {
                $row->addCell(4500);
            }
        }
    }
}
