<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-01 wires the deliberate departmental escalation into the automatic
     * FR-8 tier ladder: a department that misses its SLA feeds the matrix
     * through three new trigger conditions. Additive (R8), following
     * 2026_08_08_150011 / 2026_08_09_100013 exactly: new trigger_condition
     * values plus nullable subject columns on the event. escalation_round_no
     * lets the ladder re-fire for a reissued round without re-firing for a
     * round that has already been answered.
     */
    public function up(): void
    {
        Schema::table('escalation_matrices', function (Blueprint $table) {
            $table->enum('trigger_condition', [
                'exception_unassigned', 'exception_overdue', 'exception_inactive',
                'test_overdue', 'extension_pending', 'attestation_overdue',
                'appetite_breach', 'kri_breach', 'treatment_overdue',
                'escalation_unacknowledged', 'escalation_response_overdue',
                'escalation_response_rejected',
            ])->change();
        });

        Schema::table('escalation_events', function (Blueprint $table) {
            $table->foreignId('exception_escalation_id')->nullable()->after('treatment_id')
                ->constrained('exception_escalations', indexName: 'fk_escalation_events_exc_esc')->nullOnDelete();
            $table->unsignedInteger('escalation_round_no')->nullable()->after('exception_escalation_id');
        });
    }

    public function down(): void
    {
        Schema::table('escalation_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exception_escalation_id');
            $table->dropColumn('escalation_round_no');
        });

        Schema::table('escalation_matrices', function (Blueprint $table) {
            $table->enum('trigger_condition', [
                'exception_unassigned', 'exception_overdue', 'exception_inactive',
                'test_overdue', 'extension_pending', 'attestation_overdue',
                'appetite_breach', 'kri_breach', 'treatment_overdue',
            ])->change();
        });
    }
};
