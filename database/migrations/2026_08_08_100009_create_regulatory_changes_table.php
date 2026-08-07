<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The circular feed: New → Under Review → Impact Assessed → Actioned,
        // with maker-checker on Actioned.
        Schema::create('regulatory_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_regulatory_changes_tenant')->cascadeOnDelete();
            $table->foreignId('regulator_id')
                ->constrained('regulators', indexName: 'fk_regulatory_changes_regulator')->cascadeOnDelete();
            $table->string('title', 500);
            $table->string('reference', 200)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->date('effective_at')->nullable();
            $table->string('document_url', 500)->nullable();
            $table->text('summary')->nullable();
            $table->text('impact_assessment')->nullable();
            $table->enum('status', ['New', 'Under Review', 'Impact Assessed', 'Actioned', 'Not Applicable'])
                ->default('New');
            $table->foreignId('assessed_by')->nullable()
                ->constrained('users', indexName: 'fk_regulatory_changes_assessor')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();
            $table->foreignId('actioned_by')->nullable()
                ->constrained('users', indexName: 'fk_regulatory_changes_actioner')->nullOnDelete();
            $table->timestamp('actioned_at')->nullable();
            $table->json('affected_obligation_ids')->nullable();
            $table->json('affected_control_ids')->nullable();
            $table->enum('source', ['manual', 'rss', 'ai_parsed'])->default('manual');
            $table->string('source_guid', 500)->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('regulator_id');
            $table->index('status');
            $table->index('published_at');
            $table->unique(['tenant_id', 'regulator_id', 'source_guid'], 'uq_regulatory_changes_source_guid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulatory_changes');
    }
};
