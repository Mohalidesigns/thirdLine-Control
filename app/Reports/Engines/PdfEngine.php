<?php

namespace App\Reports\Engines;

use App\Models\TenantBranding;
use Barryvdh\DomPDF\Facade\Pdf;

class PdfEngine implements ReportEngine
{
    public function format(): string
    {
        return 'pdf';
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function render(array $document): string
    {
        $orientation = ($document['styling']['orientation'] ?? 'portrait') === 'landscape'
            ? 'landscape'
            : 'portrait';

        return Pdf::loadView('reports.definition', [
            'document' => $document,
            'branding' => TenantBranding::first(),
            'palette' => config('charts'),
        ])->setPaper($document['styling']['paper'] ?? 'a4', $orientation)->output();
    }

    /**
     * Section-derived rather than measured: dompdf only knows the true page
     * count after rasterising, and holding the whole document in memory a
     * second time to find out is not worth the number.
     */
    public function pageCount(array $document): ?int
    {
        $breaks = count(array_filter(
            $document['sections'] ?? [],
            fn (array $section) => ($section['type'] ?? '') === 'page_break',
        ));

        return $breaks + 1;
    }
}
