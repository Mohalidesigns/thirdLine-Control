<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A dated, evidenced assessment of a risk (10.1). Inherent, residual and
     * target are separate rows so movement over time is a query, not a diff.
     * Above a configurable score threshold the row stays unpublished until a
     * second-line reviewer approves it.
     */
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->enum('assessment_type', ['inherent', 'residual', 'target']);
            $table->timestamp('assessed_at');
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->enum('status', ['Draft', 'Pending Review', 'Published', 'Rejected'])->default('Draft');

            $table->unsignedTinyInteger('likelihood_level');
            $table->unsignedTinyInteger('impact_level');
            $table->text('likelihood_rationale')->nullable();
            $table->text('impact_rationale')->nullable();
            $table->decimal('score', 8, 2)->default(0);
            $table->enum('rating', ['Low', 'Moderate', 'High', 'Critical'])->default('Low');

            $table->bigInteger('financial_impact_minor')->nullable();
            $table->char('currency', 3)->nullable();
            // Quantitative inputs: min / most-likely / max, minor units.
            $table->bigInteger('loss_min_minor')->nullable();
            $table->bigInteger('loss_likely_minor')->nullable();
            $table->bigInteger('loss_max_minor')->nullable();
            $table->unsignedSmallInteger('likelihood_percent')->nullable();
            $table->bigInteger('expected_loss_minor')->nullable();
            $table->bigInteger('var_95_minor')->nullable();
            $table->bigInteger('var_99_minor')->nullable();
            $table->json('loss_curve')->nullable();
            $table->timestamp('simulated_at')->nullable();

            $table->json('impact_dimensions')->nullable();
            $table->string('driving_dimension', 60)->nullable();
            $table->enum('confidence', ['Low', 'Medium', 'High'])->default('Medium');
            $table->enum('method', ['workshop', 'interview', 'data_driven', 'scenario', 'monte_carlo'])->default('workshop');
            $table->string('period_label', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('risk_id');
            $table->index('assessed_by');
            $table->index('approved_by');
            $table->index('status');
            $table->index(['risk_id', 'assessment_type', 'status'], 'risk_assessment_current_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
