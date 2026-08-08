<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // respondent_id is NULLABLE by design: anonymous survey responses
        // never carry an identifying column (9.4) — no user id, no IP.
        Schema::create('csa_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_csa_responses_tenant')->cascadeOnDelete();
            $table->foreignId('campaign_id')
                ->constrained('csa_campaigns', indexName: 'fk_csa_responses_campaign')->cascadeOnDelete();
            $table->foreignId('questionnaire_id')
                ->constrained('csa_questionnaires', indexName: 'fk_csa_responses_questionnaire')->cascadeOnDelete();
            $table->foreignId('respondent_id')->nullable()
                ->constrained('users', indexName: 'fk_csa_responses_respondent')->nullOnDelete();
            $table->foreignId('entity_id')->nullable()
                ->constrained('organisation_units', indexName: 'fk_csa_responses_entity')->nullOnDelete();
            $table->foreignId('control_id')->nullable()
                ->constrained('controls', indexName: 'fk_csa_responses_control')->nullOnDelete();
            $table->enum('status', [
                'Not Started', 'In Progress', 'Submitted', 'Under Review', 'Accepted', 'Returned',
            ])->default('Not Started');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users', indexName: 'fk_csa_responses_reviewer')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->string('self_rating')->nullable();
            $table->string('reviewer_rating')->nullable();
            $table->boolean('variance_flag')->default(false);
            // CSA feeds design effectiveness as a PROPOSAL only (R4-adjacent):
            // a Control Function Head must approve before any rating changes.
            $table->string('proposed_design_rating')->nullable();
            $table->foreignId('rating_approved_by')->nullable()
                ->constrained('users', indexName: 'fk_csa_responses_rating_approver')->nullOnDelete();
            $table->timestamp('rating_approved_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('campaign_id');
            $table->index('status');
            $table->index('respondent_id');
            $table->index('control_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csa_responses');
    }
};
