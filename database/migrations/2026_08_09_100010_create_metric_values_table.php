<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('metric_id')->constrained()->cascadeOnDelete();
            $table->string('period_label', 40);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('value', 20, 6);
            $table->decimal('target', 20, 6)->nullable();
            $table->enum('threshold_level_hit', ['Green', 'Amber', 'Red', 'Critical'])->nullable();
            $table->boolean('breach_flag')->default(false);
            $table->decimal('variance_from_target', 20, 6)->nullable();
            $table->enum('source', ['manual', 'integration', 'calculated'])->default('manual');
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->foreignId('supporting_evidence_id')->nullable()->constrained('evidence')->nullOnDelete();
            $table->timestamps();

            $table->unique(['metric_id', 'period_label']);
            $table->index('tenant_id');
            $table->index('metric_id');
            $table->index('captured_by');
            $table->index('approved_by');
            $table->index('breach_flag');
            $table->index('threshold_level_hit');
            $table->index(['metric_id', 'period_start'], 'metric_value_series_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_values');
    }
};
