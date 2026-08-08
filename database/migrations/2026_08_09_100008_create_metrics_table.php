<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The KRI/KPI/KCI engine (10.5). A metric is a definition; its numbers
     * live in metric_values and its bands in metric_thresholds — so adding
     * an indicator never needs code.
     */
    public function up(): void
    {
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('metric_type', ['KRI', 'KPI', 'KCI', 'KPI_control'])->default('KRI');
            $table->string('code', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('risk_categories')->nullOnDelete();
            $table->text('formula')->nullable();
            $table->string('unit', 40)->nullable();
            $table->enum('data_type', ['number', 'percentage', 'currency', 'ratio', 'count', 'duration'])->default('number');
            $table->char('currency', 3)->nullable();
            $table->enum('direction', ['higher_is_better', 'lower_is_better', 'target_is_best'])->default('lower_is_better');
            $table->enum('frequency', ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Semi-annual', 'Annual'])->default('Monthly');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['manual', 'integration', 'calculated'])->default('manual');
            $table->json('source_config')->nullable();
            $table->text('calculation_expression')->nullable();
            $table->foreignId('entity_id')->nullable()->constrained('organisation_units')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('first_period', 40)->nullable();
            $table->enum('aggregation', ['sum', 'average', 'last', 'max', 'min'])->default('last');
            $table->decimal('target_value', 20, 6)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index('tenant_id');
            $table->index('category_id');
            $table->index('owner_id');
            $table->index('entity_id');
            $table->index('is_active');
            $table->index(['tenant_id', 'metric_type', 'is_active'], 'metrics_type_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics');
    }
};
