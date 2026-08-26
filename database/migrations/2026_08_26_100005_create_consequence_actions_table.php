<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-04 §C.5 — what was done about it.
 *
 * The eleven action types are HR-policy vocabulary a Nigerian bank will
 * recognise unchanged, so they carry over from the source verbatim.
 *
 * Two departures. `evidence_id` replaces a free-text `evidence` string:
 * the query letter, the warning letter and the police report are documents
 * and belong in the evidence repository under legal hold, not in a
 * varchar. And rejection now requires a reason — an action recommended
 * against a named person and then dismissed without one is exactly the
 * record a disciplinary appeal asks for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consequence_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_consequences_tenant')->cascadeOnDelete();
            $table->foreignId('investigation_id')
                ->constrained('investigations', indexName: 'fk_consequences_investigation')->cascadeOnDelete();
            $table->foreignId('investigation_subject_id')->nullable()
                ->constrained('investigation_subjects', indexName: 'fk_consequences_subject')->nullOnDelete();
            $table->string('reference', 40);                    // CON-2026-001

            $table->enum('action_type', [
                'query_issued', 'warning_letter', 'suspension', 'demotion', 'dismissal',
                'restitution_recovery', 'prosecution_police_report', 'regulatory_report',
                'training_counselling', 'process_change', 'no_action',
            ]);
            $table->text('description')->nullable();
            $table->enum('status', ['recommended', 'approved', 'in_progress', 'implemented', 'rejected'])
                ->default('recommended');

            $table->foreignId('recommended_by')->nullable()
                ->constrained('users', indexName: 'fk_consequences_recommender')->nullOnDelete();
            $table->date('recommended_on')->nullable();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users', indexName: 'fk_consequences_approver')->nullOnDelete();
            $table->date('approved_on')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->date('due_date')->nullable();
            $table->date('implemented_on')->nullable();
            $table->foreignId('implemented_by')->nullable()
                ->constrained('users', indexName: 'fk_consequences_implementer')->nullOnDelete();
            $table->text('implementation_note')->nullable();

            $table->decimal('amount_recovered', 18, 2)->nullable();

            // The letter, the report, the signed acknowledgement.
            $table->foreignId('evidence_id')->nullable()
                ->constrained('evidence', indexName: 'fk_consequences_evidence')->nullOnDelete();
            // A 'process_change' consequence is remediation work, and
            // remediation work in this product is an improvement action.
            $table->foreignId('improvement_action_id')->nullable()
                ->constrained('improvement_actions', indexName: 'fk_consequences_improvement')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference'], 'uniq_consequences_tenant_ref');
            $table->index(['investigation_id', 'status'], 'consequences_investigation_status_index');
            $table->index(['tenant_id', 'action_type'], 'consequences_tenant_type_index');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consequence_actions');
    }
};
