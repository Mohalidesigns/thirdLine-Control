<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Risk register → enterprise risk register (Phase 10). Purely additive
     * (R8): the phase 0-6 columns (inherent_likelihood/impact/rating,
     * residual_rating, risk_owner_id, category) keep their meaning, and
     * risk_owner_id remains THE owner column — the phase brief's `owner_id`
     * is that column under its existing name.
     */
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->foreignId('risk_category_id')->nullable()->after('category')
                ->constrained('risk_categories')->nullOnDelete();
            $table->enum('risk_type', ['qualitative', 'quantitative', 'both'])->default('qualitative')->after('risk_category_id');
            $table->enum('risk_source', ['internal', 'external', 'both'])->default('internal')->after('risk_type');
            $table->string('taxonomy_ref', 100)->nullable()->after('risk_source');
            $table->enum('basel_event_type', [
                'Internal Fraud',
                'External Fraud',
                'Employment Practices & Workplace Safety',
                'Clients Products & Business Practices',
                'Damage to Physical Assets',
                'Business Disruption & System Failures',
                'Execution Delivery & Process Management',
            ])->nullable()->after('taxonomy_ref');
            $table->enum('velocity', ['Slow', 'Medium', 'Fast'])->nullable()->after('basel_event_type');
            $table->boolean('is_emerging')->default(false)->after('velocity');
            $table->string('horizon', 60)->nullable()->after('is_emerging');

            $table->foreignId('appetite_id')->nullable()->after('horizon')
                ->constrained('risk_appetites')->nullOnDelete();
            $table->foreignId('parent_risk_id')->nullable()->after('appetite_id')
                ->constrained('risks')->nullOnDelete();
            $table->foreignId('second_line_reviewer_id')->nullable()->after('risk_owner_id')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('entity_id')->nullable()->after('second_line_reviewer_id')
                ->constrained('organisation_units')->nullOnDelete();
            $table->foreignId('process_id')->nullable()->after('entity_id')
                ->constrained('business_processes')->nullOnDelete();

            // Denormalised current position — the heatmap and appetite
            // indicator read these instead of re-aggregating assessments.
            $table->unsignedTinyInteger('residual_likelihood')->nullable()->after('residual_rating');
            $table->unsignedTinyInteger('residual_impact')->nullable()->after('residual_likelihood');
            $table->unsignedTinyInteger('target_likelihood')->nullable()->after('residual_impact');
            $table->unsignedTinyInteger('target_impact')->nullable()->after('target_likelihood');
            $table->decimal('target_rating', 6, 2)->nullable()->after('target_impact');
            $table->string('current_rating_band', 20)->nullable()->after('target_rating');

            // Quantitative results cache (R7 — minor units + currency).
            $table->bigInteger('financial_impact_minor')->nullable()->after('current_rating_band');
            $table->bigInteger('expected_loss_minor')->nullable()->after('financial_impact_minor');
            $table->bigInteger('var_95_minor')->nullable()->after('expected_loss_minor');
            $table->bigInteger('var_99_minor')->nullable()->after('var_95_minor');
            $table->char('loss_currency', 3)->nullable()->after('var_99_minor');

            $table->boolean('appetite_breached')->default(false)->after('loss_currency');
            $table->timestamp('appetite_breach_at')->nullable()->after('appetite_breached');

            $table->index('risk_category_id');
            $table->index('appetite_id');
            $table->index('parent_risk_id');
            $table->index('entity_id');
            $table->index('process_id');
            $table->index('second_line_reviewer_id');
            $table->index('appetite_breached');
            $table->index('current_rating_band');
        });
    }

    public function down(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->dropForeign(['risk_category_id']);
            $table->dropForeign(['appetite_id']);
            $table->dropForeign(['parent_risk_id']);
            $table->dropForeign(['second_line_reviewer_id']);
            $table->dropForeign(['entity_id']);
            $table->dropForeign(['process_id']);

            $table->dropColumn([
                'risk_category_id', 'risk_type', 'risk_source', 'taxonomy_ref',
                'basel_event_type', 'velocity', 'is_emerging', 'horizon',
                'appetite_id', 'parent_risk_id', 'second_line_reviewer_id',
                'entity_id', 'process_id',
                'residual_likelihood', 'residual_impact', 'target_likelihood',
                'target_impact', 'target_rating', 'current_rating_band',
                'financial_impact_minor', 'expected_loss_minor', 'var_95_minor',
                'var_99_minor', 'loss_currency', 'appetite_breached', 'appetite_breach_at',
            ]);
        });
    }
};
