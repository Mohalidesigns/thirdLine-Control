<?php

namespace Database\Seeders;

use App\Models\ReportTemplate;
use Illuminate\Database\Seeder;

class ReportTemplateSeeder extends Seeder
{
    use Concerns\ResolvesDemoTenant;

    /**
     * Three shipped defaults (Phase 4 req. 5). Layout lives in sections
     * data, per tenant, never hard-coded.
     */
    public function run(): void
    {
        $tenant = $this->demoTenantOrFail();

        $defaults = [
            ['Standard Spot Check Report', 'Spot Check', [
                ['type' => 'scope', 'title' => 'Scope & Coverage'],
                ['type' => 'findings', 'title' => 'Findings & Management Responses'],
                ['type' => 'sign_off', 'title' => 'Sign-off'],
            ]],
            ['Standard Exception Register', 'Exception Register', [
                ['type' => 'summary', 'title' => 'Summary'],
                ['type' => 'register', 'title' => 'Exception Register'],
            ]],
            ['Standard Control Testing Summary', 'Test Summary', [
                ['type' => 'metrics', 'title' => 'Testing Metrics'],
                ['type' => 'results', 'title' => 'Test Results'],
            ]],
        ];

        foreach ($defaults as [$name, $type, $sections]) {
            ReportTemplate::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'report_type' => $type, 'name' => $name],
                [
                    'sections' => $sections,
                    'header_config' => ['title' => 'SecondLine', 'subtitle' => 'Atheris Control Solution'],
                    'footer_config' => ['text' => 'Confidential — prepared by the control function'],
                    'is_default' => true,
                ],
            );
        }
    }
}
