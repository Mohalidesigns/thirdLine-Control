<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec §2.8 / §5.3 — the investigation report as a REVIEWED document.
 *
 * CR-04 already generates the report: thirteen sections assembled from the
 * record and delivered through the shared report pipeline, which supplies
 * the run record, the checksum, the expiring download token and the PDF
 * engine. What it had no notion of was the report being reviewed by
 * anybody. `report_runs.status` is Queued / Running / Completed / Failed /
 * Expired — the state of a RENDER, not of an approval.
 *
 * So this table carries the approval and the rendering stays where it is,
 * referenced by `report_run_id`. Two consequences worth stating:
 *
 *   1. Every other report type in the product shares `report_runs`. Adding
 *      a five-state approval workflow and four reviewer columns there
 *      would put columns on a board pack and a regulatory submission that
 *      mean nothing to either.
 *   2. A report can be re-rendered without being re-approved, and
 *      re-approved without being re-rendered. Those are genuinely
 *      different events and conflating them is how an issued document
 *      quietly acquires new content.
 *
 * `snapshot` is the immutability guarantee. At the moment of issue the
 * assembled sections and figures are frozen into it, and the issued report
 * is served from the snapshot rather than rebuilt from a case that has
 * since moved on. A later edit produces -R02; it does not rewrite -R01.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_inv_reports_tenant')->cascadeOnDelete();
            $table->foreignId('investigation_id')
                ->constrained('investigations', indexName: 'fk_inv_reports_investigation')->cascadeOnDelete();

            // INV-2026-001-R01. Derived from the case reference, so a
            // report cannot be filed under a case it does not belong to.
            $table->string('report_number', 60);
            $table->unsignedInteger('version')->default(1);

            $table->enum('workflow_state', [
                'draft', 'manager_review', 'ghic_review', 'approved', 'issued',
            ])->default('draft');

            // The rendered document, in the shared pipeline. Nullable: the
            // approval exists before the final render does, and a failed
            // render must not destroy an approval already given.
            $table->foreignId('report_run_id')->nullable()
                ->constrained('report_runs', indexName: 'fk_inv_reports_run')->nullOnDelete();

            // ── The review trail ────────────────────────────────────────
            // Three separate people in the general case. Stored as three
            // separate sets rather than a generic approvals table because
            // the report prints them as three named, differently-worded
            // blocks and a reader must be able to tell which is which.
            $table->foreignId('prepared_by_id')->nullable()
                ->constrained('users', indexName: 'fk_inv_reports_preparer')->nullOnDelete();

            $table->foreignId('manager_reviewed_by_id')->nullable()
                ->constrained('users', indexName: 'fk_inv_reports_manager')->nullOnDelete();
            $table->timestamp('manager_reviewed_at')->nullable();
            $table->text('manager_comment')->nullable();

            // GHIC — Group Head Internal Control, this product's equivalent
            // of the reference implementation's CAE.
            $table->foreignId('ghic_reviewed_by_id')->nullable()
                ->constrained('users', indexName: 'fk_inv_reports_ghic')->nullOnDelete();
            $table->timestamp('ghic_reviewed_at')->nullable();
            $table->text('ghic_comment')->nullable();

            $table->foreignId('approved_by_id')->nullable()
                ->constrained('users', indexName: 'fk_inv_reports_approver')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamp('issued_at')->nullable();
            // Calendar date, cast Y-m-d — see docs/runbooks/investigation-spec-defects.md §7.1.
            $table->date('issue_date')->nullable();

            // Frozen at issue. Null while the report is still moving.
            $table->json('snapshot')->nullable();

            // Why a returned report came back. Cleared when it moves on, so
            // it always describes the CURRENT return rather than the last
            // one that ever happened.
            $table->text('returned_reason')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users', indexName: 'fk_inv_reports_creator')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'report_number'], 'uniq_inv_reports_tenant_number');
            $table->unique(['investigation_id', 'version'], 'uniq_inv_reports_case_version');
            $table->index(['tenant_id', 'workflow_state'], 'inv_reports_tenant_state_index');
            $table->index('investigation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_reports');
    }
};
