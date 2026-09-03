<?php

namespace Database\Seeders;

use App\Models\Dashboard;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * The shipped dashboards, one per role plus the design/operating pair (13.2).
 *
 * These are data, not code: a tenant duplicates one and edits the copy. They
 * are marked is_system so nobody edits the shipped version out from under the
 * next upgrade, and every tile still resolves through the widget registry, so
 * a viewer only ever sees the datasets their permissions allow.
 */
class SystemDashboardSeeder extends Seeder
{
    use Concerns\ResolvesDemoTenant;

    /**
     * name, slug, description, role, sort, widgets[]
     * Each widget: [type, title, source, x, y, w, h, config?]
     */
    private const DASHBOARDS = [
        [
            'Executive & Board', 'executive-board',
            'Control health, top risks, appetite position and the regulatory pressure the board is accountable for.',
            'Executive Viewer', 1,
            [
                ['stat', 'Open exceptions', 'open_exceptions', 0, 0, 3, 2],
                ['stat', 'Critical monitoring findings', 'monitoring_coverage', 3, 0, 3, 2],
                ['stat', 'Penalty exposure', 'penalty_exposure', 6, 0, 3, 2],
                ['stat', 'Net operational losses', 'incident_losses', 9, 0, 3, 2],
                ['donut', 'Control health', 'control_health', 0, 2, 4, 4],
                ['heatmap', 'Risk heatmap', 'risk_heatmap', 4, 2, 8, 5],
                ['table', 'Appetite position', 'appetite_position', 0, 6, 6, 4],
                ['table', 'Regulatory calendar pressure', 'obligation_calendar', 6, 7, 6, 4],
                ['stacked_bar', 'Incident frequency and severity', 'incident_trend', 0, 10, 12, 4],
            ],
        ],
        [
            'Control Function Head', 'control-function-head',
            'The second line\'s own position: testing pipeline, exception ageing, CSA response and framework coverage.',
            'Control Function Head', 2,
            [
                ['stat', 'Overdue tests', 'overdue_tests', 0, 0, 3, 2],
                ['stat', 'Open exceptions', 'open_exceptions', 3, 0, 3, 2],
                ['gauge', 'Testing completion', 'testing_completion_rate', 6, 0, 3, 3],
                ['stat', 'Continuous monitoring coverage', 'monitoring_coverage', 9, 0, 3, 2],
                ['stacked_bar', 'Testing pipeline', 'testing_pipeline', 0, 2, 6, 4],
                ['bar', 'Exception ageing', 'exception_ageing', 6, 3, 6, 4],
                ['line', 'Raised against closed', 'exception_trend', 0, 6, 8, 4],
                ['progress', 'CSA response rates', 'csa_response_rate', 8, 7, 4, 4],
                ['horizontal_bar', 'Framework coverage', 'framework_coverage', 0, 10, 6, 4],
                ['tree', 'Organisation rollup', 'org_unit_rollup', 6, 11, 6, 5],
                // CR-03: the departmental checklist position. A Daily
                // function not performed yesterday is visible here this
                // morning, per sub-unit and per branch.
                ['stat', 'Control tasks due today', 'control_tasks_due_today', 0, 16, 3, 2],
                ['stat', 'Control tasks overdue', 'control_tasks_overdue', 3, 16, 3, 2],
                ['bar', 'Control task completion by sub-unit', 'control_task_completion_by_unit', 6, 16, 6, 4],
                ['horizontal_bar', 'Branch control scorecard', 'branch_control_scorecard', 0, 18, 12, 5],
            ],
        ],
        [
            'Control Officer', 'control-officer',
            'What is on your desk today: your tests, your exceptions and what falls due next.',
            'Control Officer', 3,
            [
                ['stat', 'Overdue tests', 'overdue_tests', 0, 0, 3, 2],
                ['stat', 'Open exceptions', 'open_exceptions', 3, 0, 3, 2],
                ['stat', 'Continuous monitoring coverage', 'monitoring_coverage', 6, 0, 3, 2],
                ['stat', 'Segregation-of-duties exposure', 'sod_exposure', 9, 0, 3, 2],
                ['table', 'My tests due', 'my_tests', 0, 2, 6, 4],
                ['table', 'My exceptions', 'my_exceptions', 6, 2, 6, 4],
                ['donut', 'Open monitoring findings', 'monitoring_findings', 0, 6, 4, 4],
                ['stacked_bar', 'Testing pipeline', 'testing_pipeline', 4, 6, 8, 4],
                // CR-03: the checklist tasks this officer owes today.
                ['stat', 'Control tasks due today', 'control_tasks_due_today', 0, 10, 3, 2],
                ['stat', 'Control tasks overdue', 'control_tasks_overdue', 3, 10, 3, 2],
                ['donut', 'Control functions by frequency', 'control_functions_by_frequency', 6, 10, 6, 4],
            ],
        ],
        [
            'Control Owner', 'control-owner',
            'The controls you own, the exceptions you have to close and the actions assigned to you.',
            'Control Owner', 4,
            [
                ['stat', 'Open exceptions', 'open_exceptions', 0, 0, 3, 2],
                ['stat', 'Key controls', 'key_control_count', 3, 0, 3, 2],
                ['table', 'My exceptions', 'my_exceptions', 0, 2, 6, 4],
                ['table', 'My tests due', 'my_tests', 6, 2, 6, 4],
                ['donut', 'Operating effectiveness', 'control_operating_effectiveness', 0, 6, 4, 4],
                ['progress', 'Distribution implementation', 'control_implementation', 4, 6, 4, 4],
            ],
        ],
        [
            'Compliance Officer', 'compliance-officer',
            'The regulatory position: obligation calendar, submissions, changes, complaint SLA and penalty exposure.',
            null, 5,
            [
                ['stat', 'Penalty exposure', 'penalty_exposure', 0, 0, 3, 2],
                ['gauge', 'Complaint SLA performance', 'complaint_sla', 3, 0, 3, 3],
                ['stat', 'Continuous monitoring coverage', 'monitoring_coverage', 6, 0, 3, 2],
                ['stacked_bar', 'Obligation instances by status', 'obligation_status', 0, 3, 6, 4],
                ['donut', 'Submission packs', 'submission_status', 6, 3, 4, 4],
                ['table', 'Regulatory calendar pressure', 'obligation_calendar', 0, 7, 6, 4],
                ['bar', 'Regulatory change pipeline', 'regulatory_change_pipeline', 6, 7, 6, 4],
                ['table', 'Obligations at risk of being missed', 'obligations_at_risk', 0, 11, 12, 4],
            ],
        ],
        [
            'Risk Officer', 'risk-officer',
            'Heatmaps, appetite breaches, KRI status, treatment progress and the incident trend.',
            null, 6,
            [
                ['heatmap', 'Risk heatmap', 'risk_heatmap', 0, 0, 6, 5],
                ['donut', 'Risks by band', 'risk_band_distribution', 6, 0, 4, 4],
                ['table', 'Appetite position', 'appetite_position', 0, 5, 6, 4],
                ['scorecard', 'KRI status', 'kri_status', 6, 4, 6, 4],
                ['stacked_bar', 'Treatment progress', 'treatment_progress', 0, 9, 5, 4],
                ['stacked_bar', 'Incident frequency and severity', 'incident_trend', 5, 8, 7, 4],
                ['table', 'Top risks', 'top_risks', 0, 13, 6, 4],
            ],
        ],
        [
            'Internal Audit & Third Line', 'internal-audit',
            'Coverage, reliance on second-line assurance, open issues and improvement actions.',
            'Line Manager', 7,
            [
                ['stat', 'Overdue tests', 'overdue_tests', 0, 0, 3, 2],
                ['stat', 'Open exceptions', 'open_exceptions', 3, 0, 3, 2],
                ['horizontal_bar', 'Framework coverage', 'framework_coverage', 0, 2, 6, 4],
                ['stacked_bar', 'Controls by business unit', 'controls_by_unit', 6, 2, 6, 4],
                ['bar', 'Exception ageing', 'exception_ageing', 0, 6, 5, 4],
                ['stacked_bar', 'Improvement actions', 'improvement_actions', 5, 6, 7, 4],
                ['table', 'Controls most likely to fail next period', 'controls_likely_to_fail', 0, 10, 6, 4],
                ['table', 'Recurrence-prone controls', 'exception_recurrence_risk', 6, 10, 6, 4],
            ],
        ],
        [
            'Design Effectiveness', 'design-effectiveness',
            'Whether the controls are built to work: design ratings, coverage and the gaps in the population.',
            null, 8,
            [
                ['donut', 'Design effectiveness', 'control_design_effectiveness', 0, 0, 4, 4],
                ['stat', 'Key controls', 'key_control_count', 4, 0, 3, 2],
                ['donut', 'Controls by lifecycle status', 'control_status_mix', 7, 0, 5, 4],
                ['horizontal_bar', 'Framework coverage', 'framework_coverage', 0, 4, 6, 4],
                ['stacked_bar', 'Controls by business unit', 'controls_by_unit', 6, 4, 6, 4],
                ['tree', 'Organisation rollup', 'org_unit_rollup', 0, 8, 6, 5, ['measure' => 'controls']],
            ],
        ],
        [
            'Operating Effectiveness', 'operating-effectiveness',
            'Whether the controls actually worked: operating ratings, testing results and what failed.',
            null, 9,
            [
                ['donut', 'Operating effectiveness', 'control_operating_effectiveness', 0, 0, 4, 4],
                ['gauge', 'Testing completion', 'testing_completion_rate', 4, 0, 3, 3],
                ['stat', 'Overdue tests', 'overdue_tests', 7, 0, 3, 2],
                ['stacked_bar', 'Testing pipeline', 'testing_pipeline', 0, 4, 6, 4],
                ['line', 'Testing trend', 'test_completion_trend', 6, 4, 6, 4],
                ['donut', 'Open exceptions by severity', 'exceptions_by_severity', 0, 8, 4, 4],
                ['horizontal_bar', 'Open exceptions by unit', 'exceptions_by_unit', 4, 8, 8, 4],
            ],
        ],
    ];

    public function run(): void
    {
        $tenant = $this->demoTenant();

        if (! $tenant) {
            return;
        }

        $roles = Role::pluck('id', 'name');

        foreach (self::DASHBOARDS as [$name, $slug, $description, $roleName, $sortOrder, $widgets]) {
            $dashboard = Dashboard::withoutGlobalScopes()->firstOrNew([
                'tenant_id' => $tenant->id,
                'slug' => $slug,
            ]);

            $dashboard->fill([
                'name' => $name,
                'description' => $description,
                'visibility' => 'tenant',
                'is_system' => true,
                'sort_order' => $sortOrder,
                'is_default_for_role_id' => $roleName ? $roles->get($roleName) : null,
                'refresh_interval_seconds' => 0,
            ])->save();

            // Re-seeding replaces the shipped layout rather than appending to
            // it — these are shipped content, and a duplicate is where a
            // tenant's own edits are supposed to live.
            $dashboard->widgets()->delete();

            foreach ($widgets as $index => $widget) {
                [$type, $title, $source, $x, $y, $w, $h] = $widget;

                $dashboard->widgets()->create([
                    'tenant_id' => $tenant->id,
                    'widget_type' => $type,
                    'title' => $title,
                    'data_source_key' => $source,
                    'query_config' => $widget[7] ?? null,
                    'position' => ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h],
                    'sort_order' => $index,
                    'is_visible' => true,
                ]);
            }
        }
    }
}
