<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A continuous test of a control against real data (12.3). The rule
     * *definition* is JSON validated against a per-type schema, so a new
     * threshold or reconciliation never needs a deployment (R1).
     *
     * A rule cannot reach Active without approval by someone other than
     * its author — enforced in MonitoringService and MonitoringRulePolicy,
     * with the columns here as the record of it (R2).
     */
    public function up(): void
    {
        Schema::create('monitoring_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule_ref', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('rule_type', [
                'threshold', 'reconciliation', 'duplicate', 'gap', 'sod_conflict',
                'exception_list', 'completeness', 'timeliness', 'trend', 'pattern', 'custom_sql',
            ])->default('threshold');
            $table->json('dataset_ids')->nullable();
            $table->json('rule_definition')->nullable();
            $table->enum('severity', ['Critical', 'High', 'Medium', 'Low'])->default('Medium');
            $table->enum('frequency', ['Hourly', 'Daily', 'Weekly', 'Monthly', 'Quarterly', 'Ad hoc'])->default('Daily');
            $table->string('schedule_cron', 100)->nullable();
            $table->json('sample_config')->nullable();
            $table->json('tolerance')->nullable();
            $table->json('exception_template')->nullable();
            $table->boolean('auto_create_exception')->default(true);
            $table->boolean('auto_create_incident')->default(false);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->enum('status', ['Draft', 'Pending Approval', 'Active', 'Paused', 'Retired'])->default('Draft');
            $table->unsignedInteger('version_no')->default(1);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'rule_ref']);
            $table->index('tenant_id');
            $table->index('control_id');
            $table->index('owner_id');
            $table->index('created_by');
            $table->index('approved_by');
            $table->index('status');
            $table->index('rule_type');
            $table->index(['tenant_id', 'status', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_rules');
    }
};
