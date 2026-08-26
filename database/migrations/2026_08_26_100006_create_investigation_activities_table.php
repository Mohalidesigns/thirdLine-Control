<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-04 §C.6 — the case diary, and the report's Chronology section.
 *
 * The TYPES / MANUAL_TYPES split on the model is the point of this table:
 * six types a human may log, eight the service writes itself. Without that
 * split the diary degenerates into a free-text notes field and the
 * chronology stops being evidence of what actually happened.
 *
 * `confidential_view` is CR-04's addition — §D.3 requires every read of a
 * confidential investigation to be visible on the case timeline, not only
 * in the audit trail an investigator never opens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_inv_activities_tenant')->cascadeOnDelete();
            $table->foreignId('investigation_id')
                ->constrained('investigations', indexName: 'fk_inv_activities_investigation')->cascadeOnDelete();

            $table->enum('activity_type', [
                'case_created', 'status_changed', 'team_assigned', 'interview_conducted',
                'evidence_collected', 'document_requested', 'site_visit', 'finding_added',
                'report_issued', 'action_recommended', 'case_completed', 'case_archived',
                'comment', 'confidential_view',
            ]);
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('activity_date');

            $table->foreignId('performed_by')->nullable()
                ->constrained('users', indexName: 'fk_inv_activities_performer')->nullOnDelete();

            // The finding, evidence row or report run the entry concerns.
            $table->nullableMorphs('linked');

            $table->timestamps();

            $table->index(['investigation_id', 'activity_date'], 'inv_activities_investigation_date_index');
            $table->index(['tenant_id', 'activity_type'], 'inv_activities_tenant_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_activities');
    }
};
