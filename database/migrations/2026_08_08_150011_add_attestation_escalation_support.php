<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Non-attestation escalates through the existing EscalationService
     * (9.5). Additive (R8): a new trigger_condition value and two nullable
     * columns tying an escalation event to a campaign + subject user.
     */
    public function up(): void
    {
        Schema::table('escalation_matrices', function (Blueprint $table) {
            $table->enum('trigger_condition', [
                'exception_unassigned', 'exception_overdue', 'exception_inactive',
                'test_overdue', 'extension_pending', 'attestation_overdue',
            ])->change();
        });

        Schema::table('escalation_events', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('test_instance_id')
                ->constrained('csa_campaigns', indexName: 'fk_escalation_events_campaign')->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->after('campaign_id')
                ->constrained('users', indexName: 'fk_escalation_events_subject')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('escalation_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropConstrainedForeignId('subject_user_id');
        });

        Schema::table('escalation_matrices', function (Blueprint $table) {
            $table->enum('trigger_condition', [
                'exception_unassigned', 'exception_overdue', 'exception_inactive',
                'test_overdue', 'extension_pending',
            ])->change();
        });
    }
};
