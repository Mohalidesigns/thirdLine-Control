<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One execution of one rule (12.3/12.4). The run records which
     * snapshots it read and — where the rule names a control — which
     * TestInstance it produced, so a continuous result flows into the
     * existing effectiveness machinery rather than a parallel universe.
     */
    public function up(): void
    {
        Schema::create('monitoring_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('monitoring_rules')->cascadeOnDelete();
            $table->string('run_ref', 40);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->enum('status', ['Queued', 'Running', 'Completed', 'Failed', 'Partial'])->default('Queued');
            $table->unsignedBigInteger('records_evaluated')->default(0);
            $table->unsignedBigInteger('records_passed')->default(0);
            $table->unsignedBigInteger('records_failed')->default(0);
            $table->decimal('exception_rate', 8, 5)->default(0);
            $table->json('result_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->json('snapshot_ids')->nullable();
            $table->foreignId('test_instance_id')->nullable()->constrained('test_instances')->nullOnDelete();
            $table->unsignedBigInteger('sampling_seed')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->enum('trigger', ['schedule', 'manual', 'replay'])->default('schedule');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'run_ref']);
            $table->index('tenant_id');
            $table->index('rule_id');
            $table->index('status');
            $table->index('test_instance_id');
            $table->index('triggered_by');
            $table->index(['tenant_id', 'rule_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_runs');
    }
};
