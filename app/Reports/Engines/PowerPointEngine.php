<?php

namespace App\Reports\Engines;

use App\Models\TenantBranding;
use App\Reports\Pptx\PresentationWriter;

/**
 * The board-meeting form. One slide per section, because a slide that scrolls
 * is a document — a section that would not fit is summarised and its detail
 * left to the PDF, which is stated on the slide rather than silently cropped.
 */
class PowerPointEngine implements ReportEngine
{
    public function format(): string
    {
        return 'pptx';
    }

    public function extension(): string
    {
        return 'pptx';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    }

    public function render(array $document): string
    {
        $branding = TenantBranding::first();
        $palette = array_values((array) config('charts.categorical'));
        $status = (array) config('charts.status');

        $writer = new PresentationWriter(
            title: $document['title'],
            author: $branding?->product_name ?? config('app.name'),
            createdAt: now()->utc()->format('Y-m-d\TH:i:s\Z'),
        );

        $writer->addSlide([
            ['kind' => 'title', 'text' => $document['title'], 'size' => 36, 'align' => 'ctr'],
            ['kind' => 'text', 'lines' => [
                ['text' => $document['subtitle'] ?: ($document['context']['tenant'] ?? ''), 'size' => 16, 'colour' => 'C9A227'],
                ['text' => ($document['context']['period'] ?? '').'  ·  '.($document['context']['entity'] ?? ''), 'size' => 14, 'colour' => '718096'],
                ['text' => 'Generated '.$document['generated_at'].' by '.$document['generated_by'], 'size' => 11, 'colour' => 'A0AEC0'],
                ['text' => 'Classification: '.$document['confidentiality'], 'size' => 11, 'bold' => true, 'colour' => 'C53030'],
            ]],
        ]);

        foreach ($document['sections'] as $section) {
            $blocks = match ($section['type']) {
                'narrative', 'appendix' => $this->narrativeSlide($section),
                'kpi_row' => $this->kpiSlide($section),
                'chart' => $this->chartSlide($section, $palette, $status),
                'table' => $this->tableSlide($section),
                'signature_block' => $this->signatureSlide($section),
                default => null,
            };

            if ($blocks !== null) {
                $writer->addSlide($blocks);
            }
        }

        return $writer->save();
    }

    public function pageCount(array $document): ?int
    {
        return 1 + count(array_filter(
            $document['sections'],
            fn (array $section) => in_array(
                $section['type'],
                ['narrative', 'appendix', 'kpi_row', 'chart', 'table', 'signature_block'],
                true,
            ),
        ));
    }

    private function narrativeSlide(array $section): ?array
    {
        $paragraphs = array_values(array_filter(array_map(
            'trim',
            preg_split('/\n{2,}/', (string) ($section['body'] ?? '')) ?: [],
        )));

        if ($paragraphs === [] && ($section['title'] ?? '') === '') {
            return null;
        }

        return [
            ['kind' => 'title', 'text' => $section['title'] ?: 'Commentary', 'size' => 26],
            ['kind' => 'text', 'lines' => array_map(fn (string $paragraph) => [
                'text' => mb_strimwidth($paragraph, 0, 220, '…'),
                'size' => 14,
                'bullet' => true,
            ], array_slice($paragraphs, 0, 6))],
        ];
    }

    private function kpiSlide(array $section): array
    {
        return [
            ['kind' => 'title', 'text' => $section['title'] ?: 'Headlines', 'size' => 26],
            ['kind' => 'bars', 'rows' => array_map(fn (array $item) => [
                'label' => $item['label'],
                'value' => 1,
                'display' => (string) $item['value'],
                'colour' => '2A78D6',
            ], $section['items']), 'max' => 1],
        ];
    }

    private function chartSlide(array $section, array $palette, array $status): array
    {
        $payload = $section['data'];
        $categories = $payload['categories'] ?? [];
        $series = $payload['series'] ?? [];

        if ($categories === [] || $series === []) {
            return $this->tableSlide($section);
        }

        $first = $series[0];
        $tones = $first['tones'] ?? null;

        $rows = [];

        foreach ($categories as $index => $category) {
            $value = (float) ($first['values'][$index] ?? 0);

            $rows[] = [
                'label' => $category['label'],
                'value' => $value,
                'display' => $this->number($value, $payload['unit'] ?? null),
                // A severity or rating scale keeps its reserved status tone;
                // anything else takes the categorical slot the chart used.
                'colour' => ltrim($tones
                    ? ($status[$tones[$index] ?? 'muted'] ?? $palette[0])
                    : ($palette[(($first['slot'] ?? 1) - 1) % max(1, count($palette))]), '#'),
            ];
        }

        return [
            ['kind' => 'title', 'text' => $section['title'], 'size' => 26],
            ['kind' => 'bars', 'rows' => $rows],
        ];
    }

    private function tableSlide(array $section): array
    {
        return [
            ['kind' => 'title', 'text' => $section['title'], 'size' => 26],
            [
                'kind' => 'table',
                'columns' => $section['table']['columns'],
                'rows' => $section['table']['rows'],
            ],
            ...(count($section['table']['rows']) > 10 ? [[
                'kind' => 'text',
                'lines' => [[
                    'text' => 'Showing the first 10 of '.count($section['table']['rows']).' rows — the full table is in the PDF and workbook.',
                    'size' => 10,
                    'colour' => '718096',
                ]],
            ]] : []),
        ];
    }

    private function signatureSlide(array $section): array
    {
        return [
            ['kind' => 'title', 'text' => $section['title'], 'size' => 26],
            ['kind' => 'table',
                'columns' => ['Role', 'Name', 'Signature', 'Date'],
                'rows' => array_map(fn (array $signatory) => [
                    $signatory['role'], $signatory['name'] ?? '', '', '',
                ], $section['signatories']),
            ],
        ];
    }

    private function number(float $value, ?string $unit): string
    {
        return match ($unit) {
            'percent' => rtrim(rtrim(number_format($value, 1), '0'), '.').'%',
            default => number_format($value),
        };
    }
}
