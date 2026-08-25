<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-03 §C.1: frequency becomes a first-class object rather than an
     * enum. The client's workbook writes thirteen distinct spellings of
     * five ideas, and three of them ("On request", "As per sales by CBN",
     * "Anytime there a new circular") are not cycles at all — they are
     * triggers. Behaviour keys on `cycle` and `generation_mode`, never on
     * the display label, the same rule CR-02 applied to control_units.domain.
     *
     * Tenant-scoped and nullable: tenant_id NULL rows are the platform
     * catalogue every tenant inherits; a tenant that needs its own rhythm
     * adds a row without a deploy.
     */
    public function up(): void
    {
        Schema::create('control_frequencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('label');
            $table->enum('cycle', [
                'daily', 'weekly', 'monthly', 'quarterly', 'semiannual', 'annual',
                'continuous', 'event',
            ]);
            // scheduled = the nightly job manufactures the instance;
            // continuous = one rolling instance per entity, rolled monthly;
            // event = nothing is generated until something triggers it.
            $table->enum('generation_mode', ['scheduled', 'continuous', 'event'])->default('scheduled');
            // Days after period end before the instance counts as overdue.
            $table->unsignedInteger('grace_days')->default(5);
            $table->string('trigger_event')->nullable();
            // The nearest value of the legacy controls.frequency enum, so
            // everything already reading that column keeps working (§C.1).
            $table->string('legacy_frequency', 30)->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'generation_mode', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_frequencies');
    }
};
