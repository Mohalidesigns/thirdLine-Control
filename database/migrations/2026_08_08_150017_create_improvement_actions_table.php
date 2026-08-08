<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The improvement database (9.9): actions from any source — tests,
        // CSA, spot checks, exceptions, surveys, audits or manual entry.
        Schema::create('improvement_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_improvement_actions_tenant')->cascadeOnDelete();
            $table->string('reference')->nullable();
            $table->enum('source_type', [
                'test', 'csa', 'spot_check', 'incident', 'audit', 'exception', 'survey', 'manual',
            ])->default('manual');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->enum('priority', ['Low', 'Medium', 'High', 'Critical'])->default('Medium');
            $table->foreignId('owner_id')->nullable()
                ->constrained('users', indexName: 'fk_improvement_actions_owner')->nullOnDelete();
            $table->date('due_at')->nullable();
            $table->enum('status', [
                'Proposed', 'Approved', 'In Progress', 'Implemented', 'Verified', 'Rejected',
            ])->default('Proposed');
            $table->foreignId('approved_by')->nullable()
                ->constrained('users', indexName: 'fk_improvement_actions_approver')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()
                ->constrained('users', indexName: 'fk_improvement_actions_verifier')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('benefit_description')->nullable();
            $table->string('effort_estimate')->nullable();
            $table->foreignId('control_id')->nullable()
                ->constrained('controls', indexName: 'fk_improvement_actions_control')->nullOnDelete();
            $table->foreignId('risk_id')->nullable()
                ->constrained('risks', indexName: 'fk_improvement_actions_risk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('priority');
            $table->index('control_id');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_actions');
    }
};
