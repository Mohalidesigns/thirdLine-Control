<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An objective's measures: the Phase 10 metrics that say whether it is
     * being met (17.1). Baseline and target live on the link, not on the
     * metric, because the same KRI can measure two objectives from two
     * starting points — and because a target is a planning statement that
     * changes with the cycle while the indicator itself does not.
     */
    public function up(): void
    {
        Schema::create('objective_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('objective_id')->constrained('objectives')->cascadeOnDelete();
            $table->foreignId('metric_id')->constrained('metrics')->cascadeOnDelete();
            $table->decimal('baseline_value', 20, 6)->nullable();
            $table->decimal('target_value', 20, 6)->nullable();
            $table->unsignedSmallInteger('weight')->default(1);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['objective_id', 'metric_id']);
            $table->index('tenant_id');
            $table->index('metric_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objective_metrics');
    }
};
