<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-04 §C.3 — who is being investigated.
 *
 * The most sensitive table CR-04 introduces. `name`, `staff_id` and
 * `account_number` are covered by the tenant scope and the investigation's
 * visibility scope, and must never reach a dashboard aggregate or a board
 * extract — InvestigationDashboardService returns references and titles
 * only, and InvestigationDashboardTest asserts it.
 *
 * `user_id` is what makes "the subject of an investigation may not be on
 * its team" enforceable (§D.4-1) — a name string cannot be compared to a
 * team membership. `outcome_rationale` exists because "culpable" recorded
 * against a named person with no reason is not defensible at a
 * disciplinary panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_inv_subjects_tenant')->cascadeOnDelete();
            $table->foreignId('investigation_id')
                ->constrained('investigations', indexName: 'fk_inv_subjects_investigation')->cascadeOnDelete();

            $table->enum('subject_type', ['staff', 'customer', 'vendor', 'third_party', 'system_process', 'unknown'])
                ->default('staff');
            $table->string('name');
            // The platform account behind a staff subject, where there is
            // one. Nullable by necessity: a customer or a vendor has none.
            $table->foreignId('user_id')->nullable()
                ->constrained('users', indexName: 'fk_inv_subjects_user')->nullOnDelete();
            $table->string('staff_id')->nullable();
            $table->string('account_number')->nullable();
            $table->string('department')->nullable();
            $table->foreignId('organisation_unit_id')->nullable()
                ->constrained('organisation_units', indexName: 'fk_inv_subjects_org_unit')->nullOnDelete();
            $table->string('position')->nullable();

            $table->enum('role_in_case', ['primary_subject', 'witness', 'person_of_interest'])
                ->default('primary_subject');
            $table->enum('outcome', ['pending', 'exonerated', 'culpable', 'partially_culpable', 'inconclusive'])
                ->default('pending');
            $table->text('outcome_rationale')->nullable();
            $table->date('outcome_recorded_on')->nullable();
            $table->foreignId('outcome_recorded_by')->nullable()
                ->constrained('users', indexName: 'fk_inv_subjects_outcome_by')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['investigation_id', 'role_in_case'], 'inv_subjects_investigation_role_index');
            $table->index(['tenant_id', 'outcome'], 'inv_subjects_tenant_outcome_index');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_subjects');
    }
};
