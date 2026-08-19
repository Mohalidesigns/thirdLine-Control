<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-01: the addressed instrument the control function ISSUES to a
     * department, processor or external party. One row per target — three
     * departments means three escalations, three clocks, three threads.
     * Deliberately separate from escalation_matrices/escalation_events,
     * which remain the automatic time-driven FR-8 tier ladder.
     */
    public function up(): void
    {
        Schema::create('exception_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exception_id')->constrained('control_exceptions')->cascadeOnDelete();
            $table->string('reference');
            $table->unsignedInteger('round_no')->default(1);
            $table->enum('target_type', ['unit', 'process', 'user', 'external_party']);
            $table->foreignId('unit_id')->nullable()
                ->constrained('organisation_units', indexName: 'fk_exc_esc_unit')->nullOnDelete();
            $table->foreignId('process_id')->nullable()
                ->constrained('business_processes', indexName: 'fk_exc_esc_process')->nullOnDelete();
            $table->foreignId('respondent_id')->nullable()
                ->constrained('users', indexName: 'fk_exc_esc_respondent')->nullOnDelete();
            $table->string('external_name')->nullable();
            $table->string('external_email')->nullable();
            $table->string('external_organisation')->nullable();
            $table->json('cc_user_ids')->nullable();
            $table->enum('severity', ['Critical', 'High', 'Medium', 'Low']);
            $table->foreignId('issued_by')->nullable()
                ->constrained('users', indexName: 'fk_exc_esc_issued_by')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->text('issue_note');
            $table->enum('required_response', ['root_cause', 'action_plan', 'both', 'confirmation'])->default('both');
            $table->timestamp('acknowledge_due_at')->nullable();
            $table->timestamp('response_due_at')->nullable();
            $table->foreignId('sla_policy_id')->nullable()
                ->constrained('exception_sla_policies', indexName: 'fk_exc_esc_sla')->nullOnDelete();
            $table->enum('status', [
                'Draft', 'Issued', 'Acknowledged', 'Responded', 'Under Review',
                'Accepted', 'Rejected', 'Reissued', 'Closed', 'Withdrawn',
            ])->default('Draft');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()
                ->constrained('users', indexName: 'fk_exc_esc_acknowledged_by')->nullOnDelete();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('last_response_at')->nullable();
            $table->boolean('is_acknowledgement_late')->default(false);
            $table->boolean('is_response_late')->default(false);
            $table->unsignedInteger('reminder_count')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamp('matrix_escalated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()
                ->constrained('users', indexName: 'fk_exc_esc_closed_by')->nullOnDelete();
            $table->text('closure_note')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()
                ->constrained('users', indexName: 'fk_exc_esc_withdrawn_by')->nullOnDelete();
            $table->text('withdrawal_reason')->nullable();
            $table->json('notified_to')->nullable();
            $table->foreignId('superseded_by_escalation_id')->nullable()
                ->constrained('exception_escalations', indexName: 'fk_exc_esc_superseded_by')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'status', 'response_due_at']);
            $table->index(['exception_id', 'round_no']);
            $table->index(['tenant_id', 'unit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_escalations');
    }
};
