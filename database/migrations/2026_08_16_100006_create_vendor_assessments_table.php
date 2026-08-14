<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Due-diligence assessments (17.2). The questionnaire itself is the
     * Phase 9 engine — `campaign_id` points at the CSA campaign whose
     * questionnaire was used, so questions, scoring method and responses
     * are not rebuilt here (R8, extend rather than duplicate).
     *
     * `expires_at` is derived from `review_frequency_months` at completion
     * and is what the nightly sweep reads to raise the review obligation.
     * The assessor cannot be the approver — the column pair is what makes
     * that provable after the fact (R2).
     */
    public function up(): void
    {
        Schema::create('vendor_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('csa_campaigns')->nullOnDelete();
            $table->foreignId('response_id')->nullable()->constrained('csa_responses')->nullOnDelete();
            $table->string('reference', 30);
            $table->enum('assessment_type', [
                'onboarding', 'periodic', 'triggered', 'exit',
            ])->default('onboarding');
            $table->string('period_label', 40)->nullable();
            $table->decimal('risk_score', 6, 2)->nullable();
            $table->enum('risk_band', ['Critical', 'High', 'Medium', 'Low'])->nullable();
            $table->text('conclusion')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedTinyInteger('review_frequency_months')->default(12);
            $table->date('expires_at')->nullable();
            // Set when the expiry sweep has already raised the review
            // obligation, so a repeat run does not raise a second one.
            $table->timestamp('review_raised_at')->nullable();
            $table->foreignId('review_exception_id')->nullable()
                ->constrained('control_exceptions')->nullOnDelete();
            $table->enum('status', ['Draft', 'In Progress', 'Submitted', 'Approved', 'Rejected', 'Expired'])
                ->default('Draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
            $table->index('tenant_id');
            $table->index('vendor_id');
            $table->index('campaign_id');
            $table->index('response_id');
            $table->index('assessed_by');
            $table->index('approved_by');
            $table->index('review_exception_id');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_assessments');
    }
};
