<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The regulator-shaped output (13.4). content holds the answered
     * sections; unverified_items holds everything the generator refused to
     * put in the document because its source is not verified (R10), so the
     * exclusion is visible rather than silent.
     *
     * Three distinct people appear here on purpose: generated_by, reviewed_by
     * and approved_by must differ, enforced in SubmissionPackService and
     * SubmissionPackPolicy with no administrator bypass (R2).
     *
     * locked_at is set on approval. An approved pack is immutable; changing
     * anything means generating a new version, which is what supersedes_id
     * records.
     */
    public function up(): void
    {
        Schema::create('submission_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obligation_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pack_ref', 40);
            $table->string('pack_type', 60);
            $table->string('period_label', 60);
            $table->enum('status', [
                'Draft', 'Under Review', 'Approved', 'Submitted',
                'Acknowledged', 'Rejected', 'Resubmitted',
            ])->default('Draft');
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('content')->nullable();
            $table->json('evidence_ids')->nullable();
            $table->unsignedTinyInteger('completeness_score')->default(0);
            $table->json('unverified_items')->nullable();
            $table->json('validation_errors')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submission_reference')->nullable();
            $table->string('acknowledgement_path')->nullable();
            $table->string('acknowledgement_ref')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('report_run_id')->nullable()->constrained('report_runs')->nullOnDelete();
            $table->string('checksum', 64)->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('submission_packs')->nullOnDelete();
            $table->unsignedInteger('version_no')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'pack_ref']);
            $table->index('tenant_id');
            $table->index('obligation_instance_id');
            $table->index('generated_by');
            $table->index('reviewed_by');
            $table->index('approved_by');
            $table->index('submitted_by');
            $table->index('report_run_id');
            $table->index('supersedes_id');
            $table->index('status');
            $table->index('pack_type');
            $table->index(['tenant_id', 'pack_type', 'period_label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_packs');
    }
};
