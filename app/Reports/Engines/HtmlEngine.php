<?php

namespace App\Reports\Engines;

use App\Models\TenantBranding;
use Illuminate\Support\Facades\View;

class HtmlEngine implements ReportEngine
{
    public function format(): string
    {
        return 'html';
    }

    public function extension(): string
    {
        return 'html';
    }

    public function mimeType(): string
    {
        return 'text/html';
    }

    public function render(array $document): string
    {
        return View::make('reports.definition', [
            'document' => $document,
            'branding' => TenantBranding::first(),
            'palette' => config('charts'),
        ])->render();
    }

    public function pageCount(array $document): ?int
    {
        return null;
    }
}
