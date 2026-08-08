<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Policy taxonomy (11.1). Seeded as global reference data
     * (tenant_id NULL) and extendable per tenant — never an enum in PHP (R1).
     */
    public function up(): void
    {
        Schema::create('policy_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()
                ->constrained('tenants', indexName: 'fk_policy_categories_tenant')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'uniq_policy_categories_tenant_code');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_categories');
    }
};
