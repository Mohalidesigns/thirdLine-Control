<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_id')->constrained()->cascadeOnDelete();
            $table->foreignId('test_script_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference');
            $table->string('period_label');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->foreignId('assigned_tester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['Scheduled', 'In Progress', 'Submitted', 'Reviewed', 'Closed', 'Reopened'])->default('Scheduled');
            $table->boolean('is_ad_hoc')->default(false);
            $table->unsignedInteger('population_size')->nullable();
            $table->unsignedInteger('sample_size')->nullable();
            $table->string('sampling_method')->nullable();
            $table->text('sample_items')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['control_id', 'period_label']);
            $table->index(['tenant_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_instances');
    }
};
