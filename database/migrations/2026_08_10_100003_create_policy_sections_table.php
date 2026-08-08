<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Section-level policy structure (11.1). Sections carry the mandatory
     * flag and the control/requirement linkage that makes policy gap
     * analysis possible — "which framework requirement has no governing
     * policy" is a query, not a spreadsheet.
     */
    public function up(): void
    {
        Schema::create('policy_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')
                ->constrained('policies', indexName: 'fk_policy_sections_policy')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()
                ->constrained('policy_sections', indexName: 'fk_policy_sections_parent')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->string('heading');
            $table->longText('body')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->json('control_ids')->nullable();
            $table->json('requirement_ids')->nullable();
            $table->timestamps();

            $table->index('policy_id');
            $table->index('parent_id');
            $table->index(['policy_id', 'sequence'], 'idx_policy_sections_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_sections');
    }
};
