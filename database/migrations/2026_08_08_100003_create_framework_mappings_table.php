<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The "test once, satisfy many" graph between framework requirements.
        Schema::create('framework_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_requirement_id')
                ->constrained('framework_requirements', indexName: 'fk_framework_mappings_source')->cascadeOnDelete();
            $table->foreignId('target_requirement_id')
                ->constrained('framework_requirements', indexName: 'fk_framework_mappings_target')->cascadeOnDelete();
            $table->enum('relationship', ['equivalent', 'partial', 'supports', 'informs'])->default('supports');
            $table->unsignedTinyInteger('coverage_percentage')->default(100);
            $table->text('rationale')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users', indexName: 'fk_framework_mappings_creator')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()
                ->constrained('users', indexName: 'fk_framework_mappings_verifier')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('source_requirement_id');
            $table->index('target_requirement_id');
            $table->index('relationship');
            $table->unique(['source_requirement_id', 'target_requirement_id'], 'uq_framework_mappings_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_mappings');
    }
};
