<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-01: the remediation a department COMMITS TO in an accepted
     * response. Not the improvement database — link the two through
     * entity_links if a tenant wants the roll-up.
     */
    public function up(): void
    {
        Schema::create('exception_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exception_id')->constrained('control_exceptions')->cascadeOnDelete();
            $table->foreignId('escalation_id')->constrained('exception_escalations')->cascadeOnDelete();
            $table->foreignId('response_id')->constrained('exception_responses')->cascadeOnDelete();
            $table->string('reference');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('action_type', ['corrective', 'preventive', 'detective'])->default('corrective');
            $table->foreignId('owner_id')->constrained('users', indexName: 'fk_exc_action_owner');
            $table->foreignId('unit_id')->nullable()
                ->constrained('organisation_units', indexName: 'fk_exc_action_unit')->nullOnDelete();
            $table->date('target_date');
            $table->date('revised_target_date')->nullable();
            $table->text('extension_reason')->nullable();
            $table->foreignId('extension_approved_by')->nullable()
                ->constrained('users', indexName: 'fk_exc_action_ext_approver')->nullOnDelete();
            $table->enum('status', ['Not Started', 'In Progress', 'Completed', 'Overdue', 'Cancelled'])
                ->default('Not Started');
            $table->decimal('progress_percentage', 5, 2)->default(0);
            $table->text('progress_note')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()
                ->constrained('users', indexName: 'fk_exc_action_completed_by')->nullOnDelete();
            $table->enum('validation_status', ['Effective', 'Partially Effective', 'Ineffective'])->nullable();
            $table->foreignId('validated_by')->nullable()
                ->constrained('users', indexName: 'fk_exc_action_validated_by')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('validation_note')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
            $table->index(['tenant_id', 'status', 'target_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_actions');
    }
};
