<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legal entities for banking groups and holding companies (16.1). An
     * entity is the legal person a licence, an obligation or a residency
     * declaration attaches to; the organisation-unit tree remains the
     * operational hierarchy and a promoted unit keeps a bridge back to it
     * (R8 — obligation assignments keep pointing at organisation_units).
     *
     * The entity-type list mirrors the licence categories the platform
     * serves; it is a column, not a class hierarchy, so a new licence
     * class is data, not a deployment (R1).
     */
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->foreignId('organisation_unit_id')->nullable()->constrained('organisation_units')->nullOnDelete();
            $table->string('reference', 30);
            $table->string('legal_name');
            $table->string('trading_name')->nullable();
            $table->enum('entity_type', [
                'holding', 'bank', 'merchant_bank', 'microfinance_bank', 'payment_service_bank',
                'pssp', 'ptsp', 'switching', 'mmo', 'super_agent', 'insurer', 'broker',
                'pfa', 'pfc', 'cmo', 'telco', 'corporate', 'subsidiary', 'branch',
                'representative_office',
            ])->default('corporate');
            $table->string('jurisdiction', 2)->default('NG');
            $table->string('registration_number')->nullable();
            $table->string('tax_id')->nullable();
            $table->json('licence_categories')->nullable();
            $table->json('regulators')->nullable();
            // Month-day of the statutory year end, e.g. '12-31' (R7 — never
            // assume a December year end).
            $table->string('fiscal_year_end', 5)->nullable();
            $table->string('functional_currency', 3)->default('NGN');
            $table->enum('consolidation_method', ['full', 'proportional', 'equity', 'none'])->default('full');
            $table->decimal('ownership_percentage', 5, 2)->default(100);
            $table->boolean('is_regulated')->default(true);
            // The declared data plane for this entity's records. Changing it
            // is a re-provisioning event, never a runtime edit (16.2).
            $table->string('data_residency_country', 2)->default('NG');
            $table->enum('status', ['active', 'dormant', 'divested'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
            $table->index('tenant_id');
            $table->index('parent_entity_id');
            $table->index('organisation_unit_id');
            $table->index('created_by');
            $table->index('status');
            $table->index('entity_type');
            $table->index(['tenant_id', 'jurisdiction']);
            $table->index('data_residency_country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
