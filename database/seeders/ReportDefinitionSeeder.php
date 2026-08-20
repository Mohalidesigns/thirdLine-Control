<?php

namespace Database\Seeders;

use App\Models\ReportDefinition;
use Illuminate\Database\Seeder;

/**
 * The shipped report definitions (13.3).
 *
 * Every section names a registered dashboard data source, so a report can
 * never read something a dashboard could not — one permission model, two
 * surfaces. Narrative text uses {{ token }} interpolation rather than fixed
 * dates, so the same definition is correct next quarter.
 */
class ReportDefinitionSeeder extends Seeder
{
    use Concerns\ResolvesDemoTenant;

    private const DEFINITIONS = [
        [
            'code' => 'BOARD-PACK',
            'name' => 'Board and committee pack',
            'description' => 'The standing control and risk pack for the board audit committee.',
            'report_type' => 'board',
            'confidentiality' => 'Board',
            'output_formats' => ['pdf', 'docx', 'pptx'],
            'sections' => [
                ['type' => 'cover', 'title' => 'Board and Committee Pack', 'subtitle' => '{{ period }} · {{ entity }}'],
                ['type' => 'toc', 'title' => 'Contents'],
                [
                    'type' => 'narrative',
                    'title' => 'Executive summary',
                    'body' => 'This pack covers {{ period }} for {{ entity }}. It sets out the control position, '
                        ."the open exception profile, the risk heat and the regulatory calendar pressure.\n\n"
                        .'Prepared by {{ owner }} on {{ generated_at }}. Figures are drawn live from the control '
                        .'platform at the time of generation.',
                ],
                [
                    'type' => 'kpi_row',
                    'title' => 'Headline position',
                    'items' => [
                        ['source' => 'open_exceptions', 'label' => 'Open exceptions'],
                        ['source' => 'overdue_tests', 'label' => 'Overdue tests'],
                        ['source' => 'testing_completion_rate', 'label' => 'Testing completion'],
                        ['source' => 'penalty_exposure', 'label' => 'Penalty exposure'],
                    ],
                ],
                ['type' => 'chart', 'title' => 'Control health', 'source' => 'control_health', 'chart_type' => 'bar'],
                ['type' => 'chart', 'title' => 'Open exceptions by severity', 'source' => 'exceptions_by_severity', 'chart_type' => 'bar'],
                ['type' => 'chart', 'title' => 'Exception ageing', 'source' => 'exception_ageing', 'chart_type' => 'bar'],
                ['type' => 'page_break'],
                ['type' => 'table', 'title' => 'Top risks', 'source' => 'top_risks'],
                ['type' => 'table', 'title' => 'Appetite position', 'source' => 'appetite_position'],
                ['type' => 'table', 'title' => 'Regulatory calendar pressure', 'source' => 'obligation_calendar'],
                ['type' => 'chart', 'title' => 'Incident frequency and severity', 'source' => 'incident_trend', 'chart_type' => 'bar'],
                ['type' => 'page_break'],
                [
                    'type' => 'signature_block',
                    'title' => 'Noted by',
                    'signatories' => [
                        ['role' => 'Chief Control Officer'],
                        ['role' => 'Chairman, Board Audit Committee'],
                    ],
                ],
            ],
            'parameters' => [
                ['key' => 'period', 'label' => 'Period', 'type' => 'period', 'default' => 'last_quarter'],
                ['key' => 'entity_id', 'label' => 'Entity', 'type' => 'entity'],
            ],
            'styling' => ['orientation' => 'portrait', 'paper' => 'a4'],
        ],
        [
            'code' => 'MGT-CONTROL',
            'name' => 'Management control report',
            'description' => 'The monthly second-line report: testing pipeline, exceptions and monitoring coverage.',
            'report_type' => 'management',
            'confidentiality' => 'Confidential',
            'output_formats' => ['pdf', 'xlsx', 'docx'],
            'sections' => [
                ['type' => 'cover', 'title' => 'Management Control Report', 'subtitle' => '{{ period }}'],
                [
                    'type' => 'narrative',
                    'title' => 'Position',
                    'body' => 'Control testing and exception position for {{ period }}, {{ entity }}.',
                ],
                [
                    'type' => 'kpi_row',
                    'title' => 'Headlines',
                    'items' => [
                        ['source' => 'testing_completion_rate', 'label' => 'Testing completion'],
                        ['source' => 'overdue_tests', 'label' => 'Overdue tests'],
                        ['source' => 'open_exceptions', 'label' => 'Open exceptions'],
                        ['source' => 'monitoring_coverage', 'label' => 'Monitoring coverage'],
                    ],
                ],
                ['type' => 'chart', 'title' => 'Testing pipeline', 'source' => 'testing_pipeline', 'chart_type' => 'bar'],
                ['type' => 'chart', 'title' => 'Raised against closed', 'source' => 'exception_trend', 'chart_type' => 'line'],
                ['type' => 'chart', 'title' => 'Open exceptions by unit', 'source' => 'exceptions_by_unit', 'chart_type' => 'horizontal_bar'],
                ['type' => 'table', 'title' => 'Controls most likely to fail next period', 'source' => 'controls_likely_to_fail'],
            ],
            'parameters' => [
                ['key' => 'period', 'label' => 'Period', 'type' => 'period', 'default' => 'last_month'],
                ['key' => 'entity_id', 'label' => 'Entity', 'type' => 'entity'],
            ],
        ],
        [
            'code' => 'TESTING-SUMMARY',
            'name' => 'Control testing summary',
            'description' => 'Testing results for the period, with the effectiveness distribution.',
            'report_type' => 'operational',
            'confidentiality' => 'Internal',
            'output_formats' => ['pdf', 'xlsx', 'csv'],
            'sections' => [
                ['type' => 'cover', 'title' => 'Control Testing Summary', 'subtitle' => '{{ period }}'],
                ['type' => 'chart', 'title' => 'Testing pipeline', 'source' => 'testing_pipeline', 'chart_type' => 'bar'],
                ['type' => 'chart', 'title' => 'Design effectiveness', 'source' => 'control_design_effectiveness', 'chart_type' => 'bar'],
                ['type' => 'chart', 'title' => 'Operating effectiveness', 'source' => 'control_operating_effectiveness', 'chart_type' => 'bar'],
                ['type' => 'chart', 'title' => 'Controls by business unit', 'source' => 'controls_by_unit', 'chart_type' => 'stacked_bar'],
            ],
            'parameters' => [
                ['key' => 'period', 'label' => 'Period', 'type' => 'period', 'default' => 'current_quarter'],
            ],
        ],
        [
            'code' => 'EXCEPTION-REGISTER',
            'name' => 'Exception register',
            'description' => 'The open exception population with ageing and unit distribution.',
            'report_type' => 'operational',
            'confidentiality' => 'Internal',
            'output_formats' => ['pdf', 'xlsx', 'csv'],
            'sections' => [
                ['type' => 'cover', 'title' => 'Exception Register', 'subtitle' => '{{ period }} · {{ entity }}'],
                ['type' => 'chart', 'title' => 'Open exceptions by severity', 'source' => 'exceptions_by_severity', 'chart_type' => 'bar'],
                ['type' => 'chart', 'title' => 'Exception ageing', 'source' => 'exception_ageing', 'chart_type' => 'bar'],
                ['type' => 'chart', 'title' => 'Open exceptions by unit', 'source' => 'exceptions_by_unit', 'chart_type' => 'horizontal_bar'],
                ['type' => 'table', 'title' => 'Recurrence-prone controls', 'source' => 'exception_recurrence_risk'],
            ],
            'parameters' => [
                ['key' => 'entity_id', 'label' => 'Entity', 'type' => 'entity'],
            ],
        ],
        [
            'code' => 'RISK-PROFILE',
            'name' => 'Risk profile report',
            'description' => 'Heat, band distribution, appetite position, KRI status and treatment progress.',
            'report_type' => 'management',
            'confidentiality' => 'Confidential',
            'output_formats' => ['pdf', 'pptx', 'docx'],
            'sections' => [
                ['type' => 'cover', 'title' => 'Risk Profile', 'subtitle' => '{{ period }} · {{ entity }}'],
                ['type' => 'chart', 'title' => 'Risks by band', 'source' => 'risk_band_distribution', 'chart_type' => 'bar'],
                ['type' => 'table', 'title' => 'Risk heatmap', 'source' => 'risk_heatmap'],
                ['type' => 'table', 'title' => 'Top risks', 'source' => 'top_risks'],
                ['type' => 'table', 'title' => 'Appetite position', 'source' => 'appetite_position'],
                ['type' => 'table', 'title' => 'KRI status', 'source' => 'kri_status'],
                ['type' => 'chart', 'title' => 'Treatment progress', 'source' => 'treatment_progress', 'chart_type' => 'bar'],
            ],
            'parameters' => [
                ['key' => 'period', 'label' => 'Period', 'type' => 'period', 'default' => 'current_quarter'],
                ['key' => 'entity_id', 'label' => 'Entity', 'type' => 'entity'],
            ],
        ],
        [
            'code' => 'COMPLIANCE-POSITION',
            'name' => 'Regulatory compliance position',
            'description' => 'Obligation status, submissions, penalty exposure and the change pipeline.',
            'report_type' => 'regulatory',
            'confidentiality' => 'Confidential',
            'output_formats' => ['pdf', 'docx', 'xlsx'],
            'sections' => [
                ['type' => 'cover', 'title' => 'Regulatory Compliance Position', 'subtitle' => '{{ period }}'],
                [
                    'type' => 'narrative',
                    'title' => 'Basis of preparation',
                    'body' => 'Positions are drawn from the obligation register as at {{ generated_at }}. '
                        .'Content sourced from unverified regulatory records is excluded from filed submissions '
                        .'and listed separately in the relevant submission pack.',
                ],
                [
                    'type' => 'kpi_row',
                    'title' => 'Headlines',
                    'items' => [
                        ['source' => 'penalty_exposure', 'label' => 'Penalty exposure'],
                        ['source' => 'complaint_sla', 'label' => 'Complaint SLA performance'],
                    ],
                ],
                ['type' => 'chart', 'title' => 'Obligation instances by status', 'source' => 'obligation_status', 'chart_type' => 'bar'],
                ['type' => 'chart', 'title' => 'Submission packs', 'source' => 'submission_status', 'chart_type' => 'bar'],
                ['type' => 'table', 'title' => 'Regulatory calendar pressure', 'source' => 'obligation_calendar'],
                ['type' => 'table', 'title' => 'Obligations at risk of being missed', 'source' => 'obligations_at_risk'],
                ['type' => 'chart', 'title' => 'Regulatory change pipeline', 'source' => 'regulatory_change_pipeline', 'chart_type' => 'bar'],
            ],
            'parameters' => [
                ['key' => 'period', 'label' => 'Period', 'type' => 'period', 'default' => 'current_quarter'],
            ],
        ],
    ];

    public function run(): void
    {
        $tenant = $this->demoTenant();

        if (! $tenant) {
            return;
        }

        foreach (self::DEFINITIONS as $definition) {
            ReportDefinition::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $definition['code']],
                $definition + [
                    'is_system' => true,
                    'is_active' => true,
                    'version_no' => 1,
                    'header_config' => ['title' => 'Atheris Control', 'subtitle' => 'Second line of defence'],
                    'footer_config' => ['text' => 'Confidential — prepared by the control function'],
                ],
            );
        }
    }
}
