<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-04 §C.1 — the investigation aggregate.
 *
 * Not the Speak Up register on `cases`: that is intake, this is casework.
 * The two are joined by origin_type/origin_id when an investigation is
 * raised from a report, and by nothing else.
 *
 * Two columns exist here that internal audit's version of this module has
 * no need for. `confidentiality_locked` is the Speak Up boundary: a
 * whistleblowing-origin investigation inherits confidentiality and the
 * investigating team cannot lower it. `has_sod_conflict` carries the
 * "the officer who owns the failed control is leading the investigation
 * into it" warning, which the entitlement-shaped SodConflictRule tables
 * genuinely cannot express (§D.4-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_investigations_tenant')->cascadeOnDelete();
            $table->string('reference', 40);                    // INV-2026-001

            // ── Identity ────────────────────────────────────────────────
            $table->string('title');
            $table->longText('description')->nullable();
            $table->longText('description_rich')->nullable();
            $table->enum('category', [
                'fraud', 'staff_misconduct', 'customer_complaint', 'whistleblowing',
                'regulatory_directive', 'asset_misappropriation', 'cyber_it_incident',
                'conflict_of_interest', 'process_breach', 'other',
            ]);
            $table->enum('source', [
                'whistleblowing', 'management_directive', 'control_exception',
                'control_test_failure', 'regulator', 'customer_complaint',
                'system_alert', 'anonymous_tip', 'internal_audit_finding', 'other',
            ]);

            // ── Where it sits in the control structure (CR-02) ──────────
            // Two columns, not one: the investigating desk and the
            // investigated department are rarely the same.
            $table->foreignId('control_entity_id')->nullable()
                ->constrained('control_entities', indexName: 'fk_investigations_control_entity')->nullOnDelete();
            $table->foreignId('organisation_unit_id')->nullable()
                ->constrained('organisation_units', indexName: 'fk_investigations_org_unit')->nullOnDelete();

            // ── Provenance: the record this was raised from ─────────────
            // SpeakUpCase | ControlException | Incident | Complaint | TestInstance
            $table->nullableMorphs('origin');

            // ── Workflow ────────────────────────────────────────────────
            $table->enum('status', ['draft', 'reported', 'under_investigation',
                'pending_review', 'completed', 'closed', 'suspended'])->default('draft');
            $table->enum('priority', ['Low', 'Medium', 'High', 'Critical'])->default('Medium');
            $table->enum('risk_rating', ['Low', 'Moderate', 'High', 'Critical'])->nullable();
            $table->boolean('is_confidential')->default(false);
            $table->boolean('confidentiality_locked')->default(false);
            $table->boolean('has_sod_conflict')->default(false);
            $table->text('sod_conflict_note')->nullable();

            // ── Dates ───────────────────────────────────────────────────
            // date, not timestamp: MySQL gives the first TIMESTAMP column
            // in a table an implicit ON UPDATE CURRENT_TIMESTAMP.
            $table->date('reported_date');
            $table->date('commenced_date')->nullable();
            $table->date('target_completion_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->date('closed_date')->nullable();

            $table->foreignId('lead_investigator_id')->nullable()
                ->constrained('users', indexName: 'fk_investigations_lead')->nullOnDelete();

            // ── Financial impact ────────────────────────────────────────
            $table->decimal('estimated_financial_impact', 18, 2)->nullable();
            $table->decimal('confirmed_financial_loss', 18, 2)->nullable();
            $table->decimal('amount_recovered', 18, 2)->nullable();
            $table->string('currency', 3)->default('NGN');

            // ── Narrative (report sections) — plain + _rich pairs ───────
            foreach (['background', 'scope', 'objectives', 'methodology', 'chronology', 'conclusion'] as $field) {
                $table->longText($field)->nullable();
                $table->longText($field.'_rich')->nullable();
            }

            // ── Archive ─────────────────────────────────────────────────
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()
                ->constrained('users', indexName: 'fk_investigations_archiver')->nullOnDelete();
            $table->text('archive_reason')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users', indexName: 'fk_investigations_creator')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()
                ->constrained('users', indexName: 'fk_investigations_updater')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference'], 'uniq_investigations_tenant_ref');
            $table->index(['tenant_id', 'status'], 'investigations_tenant_status_index');
            $table->index(['tenant_id', 'reported_date'], 'investigations_tenant_reported_index');
            $table->index(['tenant_id', 'is_archived', 'status'], 'investigations_tenant_archived_status_index');
            $table->index('category');
            $table->index('risk_rating');
            $table->index('priority');
            $table->index('is_confidential');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigations');
    }
};
