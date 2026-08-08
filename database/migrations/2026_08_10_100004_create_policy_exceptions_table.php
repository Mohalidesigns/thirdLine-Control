<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Policy waivers (11.1). A deviation from a published policy is a
     * time-boxed, approved, reviewable decision — never a silent practice.
     * Approval is never the requester's to give (PolicyExceptionPolicy).
     */
    public function up(): void
    {
        Schema::create('policy_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_policy_exceptions_tenant')->cascadeOnDelete();
            $table->string('reference', 40);
            $table->foreignId('policy_id')
                ->constrained('policies', indexName: 'fk_policy_exceptions_policy')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()
                ->constrained('users', indexName: 'fk_policy_exceptions_requester')->nullOnDelete();
            $table->foreignId('entity_id')->nullable()
                ->constrained('organisation_units', indexName: 'fk_policy_exceptions_entity')->nullOnDelete();
            $table->text('justification');
            $table->text('risk_assessment')->nullable();
            $table->text('compensating_measures')->nullable();
            $table->date('requested_from');
            $table->date('requested_to');
            $table->enum('status', ['Requested', 'Under Review', 'Approved', 'Rejected', 'Expired', 'Revoked'])
                ->default('Requested');
            $table->foreignId('approved_by')->nullable()
                ->constrained('users', indexName: 'fk_policy_exceptions_approver')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->date('review_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->text('revoked_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference'], 'uniq_policy_exceptions_tenant_ref');
            $table->index('tenant_id');
            $table->index('policy_id');
            $table->index('requested_by');
            $table->index('entity_id');
            $table->index('approved_by');
            $table->index('status');
            $table->index('requested_to');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_exceptions');
    }
};
