<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Continuous monitoring of a third party (17.2): sanctions, PEP and
     * adverse-media screening, run through a Phase 12 connector where one
     * is configured and recorded by hand where it is not.
     *
     * The AI summary is a draft and is stored as one: `ai_interaction_id`
     * carries the interaction it came from and `cleared_by` is null until a
     * named human has looked at the hits. A screening never clears itself
     * (R4).
     */
    public function up(): void
    {
        Schema::create('vendor_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('data_source_id')->nullable()->constrained('data_sources')->nullOnDelete();
            $table->enum('screening_type', ['sanctions', 'pep', 'adverse_media', 'credit', 'litigation'])
                ->default('sanctions');
            $table->timestamp('screened_at');
            $table->enum('result', ['clear', 'potential_match', 'confirmed_match', 'error'])->default('clear');
            $table->unsignedSmallInteger('hit_count')->default(0);
            $table->json('hits')->nullable();
            $table->text('summary')->nullable();
            $table->foreignId('ai_interaction_id')->nullable()->constrained('ai_interactions')->nullOnDelete();
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->text('disposition')->nullable();
            $table->foreignId('finding_id')->nullable()->constrained('vendor_findings')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('vendor_id');
            $table->index('data_source_id');
            $table->index('ai_interaction_id');
            $table->index('cleared_by');
            $table->index('finding_id');
            $table->index('result');
            $table->index(['vendor_id', 'screening_type', 'screened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_screenings');
    }
};
