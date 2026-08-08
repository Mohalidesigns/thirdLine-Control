<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Complaint subject categories and root-cause categories (11.3), in one
     * seeded taxonomy distinguished by `kind`. Both are reference data
     * (R1) — a regulator's return categories change, and they change in the
     * database, not in a deploy.
     */
    public function up(): void
    {
        Schema::create('complaint_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()
                ->constrained('tenants', indexName: 'fk_complaint_categories_tenant')->cascadeOnDelete();
            $table->enum('kind', ['category', 'root_cause'])->default('category');
            $table->string('code', 40);
            $table->string('name');
            $table->foreignId('parent_id')->nullable()
                ->constrained('complaint_categories', indexName: 'fk_complaint_categories_parent')->nullOnDelete();
            $table->string('regulatory_code', 40)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'code'], 'uniq_complaint_categories_tenant_kind_code');
            $table->index('tenant_id');
            $table->index('parent_id');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_categories');
    }
};
