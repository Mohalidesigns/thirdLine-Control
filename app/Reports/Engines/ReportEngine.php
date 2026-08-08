<?php

namespace App\Reports\Engines;

/**
 * One output format (13.3). Engines take the resolved document from
 * ReportSectionResolver — never the database — so every format says the
 * same thing about the same period.
 */
interface ReportEngine
{
    public function format(): string;

    public function extension(): string;

    public function mimeType(): string;

    /**
     * @param  array<string, mixed>  $document
     * @return string raw file contents
     */
    public function render(array $document): string;

    /** Pages, slides or sheets, when the format has a meaningful count. */
    public function pageCount(array $document): ?int;
}
