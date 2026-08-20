<?php

namespace App\Reports\Engines;

/**
 * The flat form. A CSV cannot carry a cover page or a signature block, so it
 * carries the tabular sections and says so — a spreadsheet that silently
 * dropped the narrative would be mistaken for the whole report.
 */
class CsvEngine implements ReportEngine
{
    public function format(): string
    {
        return 'csv';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function mimeType(): string
    {
        return 'text/csv';
    }

    public function render(array $document): string
    {
        $handle = fopen('php://temp', 'r+');

        // PHP 8.4 deprecates the implicit escape default and will change it;
        // stating it keeps the output byte-identical across versions.
        $write = fn (array $row) => fputcsv($handle, $row, ',', '"', '\\');

        $write([$document['title']]);
        $write(['Period', $document['context']['period'] ?? '']);
        $write(['Entity', $document['context']['entity'] ?? '']);
        $write(['Generated', $document['generated_at'].' by '.$document['generated_by']]);
        $write(['Classification', $document['confidentiality']]);
        $write([]);

        foreach ($document['sections'] as $section) {
            if (! in_array($section['type'], ['table', 'chart'], true)) {
                continue;
            }

            $write([$section['title']]);
            $write($section['table']['columns']);

            foreach ($section['table']['rows'] as $row) {
                $write($row);
            }

            $write([]);
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    public function pageCount(array $document): ?int
    {
        return null;
    }
}
