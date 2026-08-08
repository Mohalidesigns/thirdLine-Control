<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The assessment scales are DATA (R1): grid size, level labels, money
     * bands and cell colours all live here, so a tenant can run a 4×4 or a
     * 6×6 matrix without a code change. Money bands are integer minor units
     * plus an ISO-4217 code (R7).
     */
    public function up(): void
    {
        Schema::create('risk_assessment_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('scale_type', ['likelihood', 'impact', 'velocity', 'control_effect', 'rating_band']);
            $table->string('dimension', 60)->nullable();
            $table->unsignedTinyInteger('level');
            $table->string('label', 100);
            $table->text('description')->nullable();
            $table->bigInteger('lower_bound_minor')->nullable();
            $table->bigInteger('upper_bound_minor')->nullable();
            // rating_band rows: the score window the band covers, so the
            // band cut-offs are data too — not a constant in a PHP class.
            $table->decimal('score_from', 8, 2)->nullable();
            $table->decimal('score_to', 8, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->char('colour_hex', 7)->default('#e1e0d9');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'scale_type']);
            $table->index(['tenant_id', 'scale_type', 'dimension', 'level'], 'risk_scales_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessment_scales');
    }
};
