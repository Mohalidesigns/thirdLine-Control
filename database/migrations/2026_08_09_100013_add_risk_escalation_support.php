<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appetite breaches, KRI breaches and overdue treatments escalate
     * through the existing EscalationService rather than a parallel
     * notifier (10.3–10.5). Additive (R8): three new trigger_condition
     * values and three nullable subject columns on the event.
     */
    public function up(): void
    {
        Schema::table('escalation_matrices', function (Blueprint $table) {
            $table->enum('trigger_condition', [
                'exception_unassigned', 'exception_overdue', 'exception_inactive',
                'test_overdue', 'extension_pending', 'attestation_overdue',
                'appetite_breach', 'kri_breach', 'treatment_overdue',
            ])->change();
        });

        Schema::table('escalation_events', function (Blueprint $table) {
            $table->foreignId('risk_id')->nullable()->after('subject_user_id')
                ->constrained('risks', indexName: 'fk_escalation_events_risk')->nullOnDelete();
            $table->foreignId('metric_id')->nullable()->after('risk_id')
                ->constrained('metrics', indexName: 'fk_escalation_events_metric')->nullOnDelete();
            $table->foreignId('treatment_id')->nullable()->after('metric_id')
                ->constrained('risk_treatments', indexName: 'fk_escalation_events_treatment')->nullOnDelete();
        });

        // A Red/Critical KRI reading opens an exception, so 'KRI' joins the
        // source vocabulary (additive, R8 — the same pattern as 'CSA').
        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->enum('source_type', ['Test', 'Spot Check', 'Manual', 'CSA', 'KRI'])
                ->default('Manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('control_exceptions', function (Blueprint $table) {
            $table->enum('source_type', ['Test', 'Spot Check', 'Manual', 'CSA'])
                ->default('Manual')->change();
        });

        Schema::table('escalation_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('risk_id');
            $table->dropConstrainedForeignId('metric_id');
            $table->dropConstrainedForeignId('treatment_id');
        });

        Schema::table('escalation_matrices', function (Blueprint $table) {
            $table->enum('trigger_condition', [
                'exception_unassigned', 'exception_overdue', 'exception_inactive',
                'test_overdue', 'extension_pending', 'attestation_overdue',
            ])->change();
        });
    }
};
