<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The report review workflow (spec §5.3) puts four new events on the case
 * diary — submitted, reviewed, returned, approved — and only the last step
 * is an issue. Logging the other four as `report_issued` would print
 * "Report Issued — moved to Manager Review" in the report's own chronology
 * table, which is a legal document asserting something that did not
 * happen.
 *
 * Additive: existing rows are untouched, and `report_issued` keeps its
 * meaning for the act it names.
 */
return new class extends Migration
{
    private const EXISTING = [
        'case_created', 'status_changed', 'team_assigned', 'interview_conducted',
        'evidence_collected', 'document_requested', 'site_visit', 'finding_added',
        'report_issued', 'action_recommended', 'case_completed', 'case_archived',
        'comment', 'confidential_view',
    ];

    public function up(): void
    {
        Schema::table('investigation_activities', function (Blueprint $table) {
            $table->enum('activity_type', [...self::EXISTING, 'report_reviewed'])->change();
        });
    }

    /**
     * A narrowed enum has no room for the value, so those diary entries
     * become the closest thing that survives rather than being truncated
     * to an empty string mid-rollback.
     */
    public function down(): void
    {
        DB::table('investigation_activities')
            ->where('activity_type', 'report_reviewed')
            ->update(['activity_type' => 'report_issued']);

        Schema::table('investigation_activities', function (Blueprint $table) {
            $table->enum('activity_type', self::EXISTING)->change();
        });
    }
};
