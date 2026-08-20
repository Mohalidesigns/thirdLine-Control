<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR2-A: the control universe — what the second line oversees. Three
     * org concepts now coexist: organisation_units (the operational tree),
     * entities (the Phase-16 legal-entity register) and control_entities
     * (this table). Branch rows BRIDGE the operational tree through
     * organisation_unit_id — they never replace it (R-A).
     */
    public function up(): void
    {
        Schema::create('control_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_unit_id')->constrained('control_units')->cascadeOnDelete();
            // Branches nest their activities here.
            $table->foreignId('parent_id')->nullable()->constrained('control_entities')->cascadeOnDelete();
            $table->string('reference', 30);
            $table->string('name');
            $table->text('description')->nullable();
            $table->longText('description_rich')->nullable();
            $table->enum('entity_kind', ['department', 'domain', 'branch', 'activity']);
            // The bridge to the operational tree — mandatory for branches (R-A).
            $table->foreignId('organisation_unit_id')->nullable()->constrained('organisation_units')->nullOnDelete();
            $table->foreignId('business_process_id')->nullable()->constrained('business_processes')->nullOnDelete();
            // The second-line relationship officer.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('risk_rating', ['Critical', 'High', 'Medium', 'Low'])->nullable();
            $table->enum('review_frequency', ['monthly', 'quarterly', 'semiannual', 'annual'])->nullable();
            $table->date('last_reviewed_at')->nullable();
            $table->date('next_review_due_at')->nullable();
            // Branch-activity template rows only (copy-on-write source).
            $table->boolean('is_template')->default(false);
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'control_unit_id', 'parent_id', 'name']);
            $table->index(['tenant_id', 'control_unit_id', 'entity_kind']);
            $table->index(['tenant_id', 'organisation_unit_id']);
            $table->index(['tenant_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_entities');
    }
};
