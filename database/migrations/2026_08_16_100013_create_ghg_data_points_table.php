<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GHG data lineage (17.3). Every emissions figure carries the activity
     * data it was computed from, the emission factor and where that factor
     * came from, the control that governs the figure, and the evidence
     * behind it — which is the whole point: a tCO2e number with no lineage
     * is not assurable.
     *
     * The preparer and the verifier are different columns and the service
     * refuses them being the same person (R2). Scope 3 rows can be marked
     * deferred under the IFRS S2 transition reliefs; the deferral is
     * recorded on the row with its reason rather than inferred, so a
     * reader can tell "not reported yet" from "reported as zero".
     */
    public function up(): void
    {
        Schema::create('ghg_data_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->enum('scope', ['1', '2', '3']);
            // The GHG Protocol category, e.g. 'Stationary combustion' or
            // 'Category 6: Business travel'. Data, not an enum: the
            // categories are the Protocol's and they change.
            $table->string('category');
            $table->string('period_label', 40);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('activity_data', 20, 6)->nullable();
            $table->string('activity_unit', 40)->nullable();
            $table->decimal('emission_factor', 20, 10)->nullable();
            $table->string('emission_factor_source')->nullable();
            $table->decimal('tco2e', 20, 6)->nullable();
            $table->enum('data_quality', ['measured', 'calculated', 'estimated', 'proxy'])->default('calculated');
            $table->enum('methodology', ['location_based', 'market_based', 'not_applicable'])->default('not_applicable');
            $table->foreignId('control_id')->nullable()->constrained('controls')->nullOnDelete();
            $table->foreignId('evidence_id')->nullable()->constrained('evidence')->nullOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->boolean('is_deferred')->default(false);
            $table->string('deferral_reason')->nullable();
            $table->enum('status', ['Draft', 'Prepared', 'Verified', 'Rejected', 'Deferred'])->default('Draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('entity_id');
            $table->index('control_id');
            $table->index('evidence_id');
            $table->index('prepared_by');
            $table->index('verified_by');
            $table->index('status');
            $table->index(['tenant_id', 'period_label', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ghg_data_points');
    }
};
