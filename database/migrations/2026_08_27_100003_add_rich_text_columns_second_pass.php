<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §9 — the Editor.js sweep, second pass.
 *
 * The first pass (2026_08_20_100002) converted eleven tables. This one
 * carries the rest of the narrative fields the sweep found: thirty tables,
 * thirty-nine columns, every one of them a place where somebody types
 * prose that later has to be read back — a rationale, a justification, a
 * review note, a conclusion.
 *
 * The storage shape is unchanged and deliberately so: the Editor.js
 * document goes in `{field}_rich`, and the ORIGINAL column stays as a
 * derived plain mirror that HasRichText repopulates on every save. Search,
 * filters, exports and report generation keep reading the column they have
 * always read, and nothing downstream needs to know the editor exists.
 *
 * No data migration is required. `_rich` starts NULL on every existing
 * row; the editor turns legacy plain text into paragraph blocks when it
 * loads (RichTextEditor::toDoc), and the first save persists the document.
 * A row nobody edits keeps working forever on the plain column alone.
 *
 * Deliberately NOT converted — these are textareas holding something that
 * is not prose, and a block editor would corrupt them:
 *
 *   sso_configurations.x509_cert     a PEM certificate, byte-exact
 *   test_instances.sample_items      newline-separated ids, parsed server-side
 *   ai_prompts.system_prompt         LLM templates with placeholders
 *   ai_prompts.user_template
 *   tenant_brandings.report_*_html   raw HTML for the PDF masthead
 *   data_sources.connection_config   JSON, parsed before submit
 *   monitoring_rules.definition      the rule DSL
 *
 * See docs/testing/editorjs-conversion.md for the full register.
 */
return new class extends Migration
{
    private const RICH_FIELDS = [
        'cases' => ['description'],
        'complaints' => ['description', 'resolution_summary'],
        'consequence_actions' => ['implementation_note', 'rejection_reason'],
        'cross_border_transfers' => ['description', 'lawful_basis_note'],
        'data_sources' => ['data_residency_note'],
        'entity_links' => ['notes'],
        'exception_escalations' => ['closure_note'],
        'frameworks' => ['description'],
        'initiatives' => ['description'],
        'investigation_subjects' => ['outcome_rationale'],
        'investigations' => ['archive_reason'],
        'materiality_assessments' => ['rationale'],
        'metric_breaches' => ['action_taken'],
        'metric_values' => ['comment'],
        'monitoring_findings' => ['review_notes'],
        'monitoring_rules' => ['description'],
        'objective_metrics' => ['note'],
        'objectives' => ['description'],
        'obligation_instances' => ['notes'],
        'policy_exceptions' => ['compensating_measures', 'justification'],
        'regulatory_changes' => ['impact_assessment', 'summary'],
        'risk_appetites' => ['metric_definition', 'statement'],
        'risk_assessments' => ['impact_rationale', 'likelihood_rationale'],
        'risk_treatments' => ['acceptance_reason', 'verification_notes'],
        'sod_conflict_rules' => ['description'],
        'sustainability_filing_stages' => ['note'],
        'sustainability_filings' => ['verification_note'],
        'vendor_assessments' => ['conclusion'],
        'vendor_screenings' => ['disposition', 'summary'],
        'vendors' => ['services_provided'],
    ];

    public function up(): void
    {
        foreach (self::RICH_FIELDS as $table => $fields) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $fields) {
                foreach ($fields as $field) {
                    if (! Schema::hasColumn($table, "{$field}_rich")) {
                        $blueprint->longText("{$field}_rich")->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::RICH_FIELDS as $table => $fields) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($fields) {
                $blueprint->dropColumn(array_map(fn ($field) => "{$field}_rich", $fields));
            });
        }
    }
};
