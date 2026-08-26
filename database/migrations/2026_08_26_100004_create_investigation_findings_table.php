<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-04 §C.4 — what the investigation established.
 *
 * The three foreign keys at the bottom are the reason this module is worth
 * more in internal control than it is in internal audit: a finding names
 * WHICH control failed, links to the exception that failure raised, and
 * its recommendation becomes a tracked improvement action rather than a
 * paragraph in a PDF (§F.1, §F.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_inv_findings_tenant')->cascadeOnDelete();
            $table->foreignId('investigation_id')
                ->constrained('investigations', indexName: 'fk_inv_findings_investigation')->cascadeOnDelete();
            $table->string('reference', 40);                    // INVF-2026-001

            $table->string('title');
            $table->longText('description')->nullable();
            $table->longText('description_rich')->nullable();
            $table->enum('severity', ['Low', 'Moderate', 'High', 'Critical'])->default('Moderate');

            $table->longText('root_cause')->nullable();
            $table->longText('root_cause_rich')->nullable();
            $table->longText('control_failure')->nullable();
            $table->longText('control_failure_rich')->nullable();
            $table->longText('recommendation')->nullable();
            $table->longText('recommendation_rich')->nullable();

            $table->decimal('financial_impact', 18, 2)->nullable();

            // ── The loop back into the control product ──────────────────
            $table->foreignId('control_id')->nullable()
                ->constrained('controls', indexName: 'fk_inv_findings_control')->nullOnDelete();
            $table->foreignId('exception_id')->nullable()
                ->constrained('control_exceptions', indexName: 'fk_inv_findings_exception')->nullOnDelete();
            $table->foreignId('improvement_action_id')->nullable()
                ->constrained('improvement_actions', indexName: 'fk_inv_findings_improvement')->nullOnDelete();

            $table->foreignId('raised_by')->nullable()
                ->constrained('users', indexName: 'fk_inv_findings_raiser')->nullOnDelete();
            $table->date('established_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference'], 'uniq_inv_findings_tenant_ref');
            $table->index(['investigation_id', 'severity'], 'inv_findings_investigation_severity_index');
            $table->index('control_id');
            $table->index('exception_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_findings');
    }
};
