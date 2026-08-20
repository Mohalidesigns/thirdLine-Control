<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Editor.js documents live in `{field}_rich` beside the original plain
     * column, which becomes a derived mirror (populated on save) so search,
     * filters, exports and PDF/report generation keep working unchanged.
     * Existing rows keep `_rich` NULL — the editor migrates plain text into
     * paragraph blocks on load, and the first save persists the document.
     */
    private const RICH_FIELDS = [
        'controls' => ['description', 'objective', 'control_documentation', 'notes'],
        'control_exceptions' => ['description', 'root_cause', 'remediation_plan', 'closure_notes', 'risk_acceptance_reason'],
        'incidents' => ['description', 'root_cause', 'lessons_learned'],
        'risks' => ['description'],
        'spot_checks' => ['scope_description'],
        'test_scripts' => ['objective', 'sampling_guidance'],
        'compensating_controls' => ['description', 'residual_exposure_note'],
        'risk_treatments' => ['description', 'benefit_description'],
        'effectiveness_ratings' => ['design_rationale', 'operating_rationale'],
        'improvement_actions' => ['description'],
        'exception_responses' => ['management_comment', 'root_cause', 'agreed_action_plan'],
    ];

    public function up(): void
    {
        foreach (self::RICH_FIELDS as $table => $fields) {
            Schema::table($table, function (Blueprint $blueprint) use ($fields) {
                foreach ($fields as $field) {
                    $blueprint->longText("{$field}_rich")->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::RICH_FIELDS as $table => $fields) {
            Schema::table($table, function (Blueprint $blueprint) use ($fields) {
                $blueprint->dropColumn(array_map(fn ($field) => "{$field}_rich", $fields));
            });
        }
    }
};
