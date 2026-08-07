<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Self-referencing tree: domain → principle → control objective.
        Schema::create('framework_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('framework_id')
                ->constrained('frameworks', indexName: 'fk_framework_requirements_framework')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()
                ->constrained('framework_requirements', indexName: 'fk_framework_requirements_parent')->cascadeOnDelete();
            $table->string('ref_code', 60);
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->text('guidance')->nullable();
            $table->tinyInteger('level')->default(1);
            $table->integer('sort_order')->default(0);
            $table->enum('requirement_type', [
                'component', 'principle', 'domain', 'objective',
                'control', 'clause', 'article', 'section',
            ])->default('principle');
            $table->boolean('is_testable')->default(true);
            $table->string('suggested_frequency', 30)->nullable();
            $table->json('suggested_evidence')->nullable();
            $table->enum('verification_status', ['verified', 'unverified', 'draft'])->default('unverified');
            $table->string('source_reference', 500)->nullable();
            $table->timestamps();

            $table->index('framework_id');
            $table->index('parent_id');
            $table->index('requirement_type');
            $table->index('verification_status');
            $table->index('is_testable');
            $table->unique(['framework_id', 'ref_code'], 'uq_framework_requirements_framework_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('framework_requirements');
    }
};
