<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulatory_obligations', function (Blueprint $table) {
            $table->id();
            // NULL = shipped content (R1); a tenant row is a tenant-authored
            // obligation or a customised copy that wins over the shipped one.
            $table->foreignId('tenant_id')->nullable()
                ->constrained('tenants', indexName: 'fk_regulatory_obligations_tenant')->cascadeOnDelete();
            $table->foreignId('regulator_id')
                ->constrained('regulators', indexName: 'fk_regulatory_obligations_regulator')->cascadeOnDelete();
            $table->foreignId('framework_id')->nullable()
                ->constrained('frameworks', indexName: 'fk_regulatory_obligations_framework')->nullOnDelete();
            $table->foreignId('requirement_id')->nullable()
                ->constrained('framework_requirements', indexName: 'fk_regulatory_obligations_requirement')->nullOnDelete();
            $table->string('obligation_ref', 80);
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->enum('obligation_type', [
                'filing', 'disclosure', 'notification', 'approval', 'registration',
                'payment', 'assessment', 'training', 'retention', 'operational',
            ])->default('filing');
            // Entity types and licence categories this obligation binds.
            $table->json('applies_to')->nullable();
            $table->enum('trigger_type', ['calendar', 'event', 'threshold', 'on_demand'])->default('calendar');
            $table->enum('frequency', [
                'one_off', 'daily', 'weekly', 'monthly', 'quarterly',
                'semi_annual', 'annual', 'biennial', 'triennial', 'event_driven',
            ])->default('annual');
            // {"type":"fixed_date","month":3,"day":31}
            // {"type":"relative","anchor":"fiscal_year_end","offset_days":60}
            // {"type":"event_relative","event":"breach_detected","offset_hours":72}
            $table->json('due_rule');
            $table->unsignedSmallInteger('grace_period_days')->default(0);
            $table->text('penalty_description')->nullable();
            // R7: integer minor units + ISO-4217 code, never a float.
            $table->bigInteger('penalty_amount_minor')->nullable();
            $table->char('penalty_currency', 3)->nullable();
            $table->enum('penalty_basis', ['fixed', 'per_day', 'per_week', 'per_instance', 'percentage'])->nullable();
            $table->string('legal_reference', 500)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            // R10: unverified/draft obligations never enter a generated submission.
            $table->enum('verification_status', ['verified', 'unverified', 'draft'])->default('unverified');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('regulator_id');
            $table->index('framework_id');
            $table->index('requirement_id');
            $table->index('obligation_type');
            $table->index('trigger_type');
            $table->index('frequency');
            $table->index('verification_status');
            $table->index('is_active');
            $table->unique(['tenant_id', 'obligation_ref'], 'uq_regulatory_obligations_tenant_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulatory_obligations');
    }
};
