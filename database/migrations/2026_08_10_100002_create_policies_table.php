<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Policy management (11.1). A policy is the governing instrument; the
     * optional document_id points at the signed artefact in the Phase 9
     * document store, so the two never diverge.
     *
     * Publication requires an approver different from the owner — the rule
     * lives in PolicyPolicy + PolicyService, and the columns here exist so
     * the decision is provable after the fact.
     */
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_policies_tenant')->cascadeOnDelete();
            $table->string('policy_ref', 40);
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('policy_type', ['policy', 'procedure', 'standard', 'guideline', 'charter', 'code'])
                ->default('policy');
            $table->foreignId('category_id')->nullable()
                ->constrained('policy_categories', indexName: 'fk_policies_category')->nullOnDelete();
            $table->foreignId('document_id')->nullable()
                ->constrained('documents', indexName: 'fk_policies_document')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()
                ->constrained('users', indexName: 'fk_policies_owner')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()
                ->constrained('users', indexName: 'fk_policies_approver')->nullOnDelete();
            $table->unsignedInteger('version_no')->default(1);
            $table->enum('status', [
                'Draft', 'Under Review', 'Pending Approval', 'Approved',
                'Published', 'Under Revision', 'Superseded', 'Withdrawn',
            ])->default('Draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->enum('review_frequency', [
                'none', 'monthly', 'quarterly', 'semi_annual', 'annual', 'biennial', 'triennial',
            ])->default('annual');
            $table->date('next_review_at')->nullable();
            $table->json('applies_to')->nullable();
            $table->boolean('requires_attestation')->default(false);
            $table->enum('attestation_frequency', [
                'on_publication', 'annual', 'semi_annual', 'quarterly', 'on_change',
            ])->nullable();
            $table->foreignId('supersedes_policy_id')->nullable()
                ->constrained('policies', indexName: 'fk_policies_supersedes')->nullOnDelete();
            $table->foreignId('attestation_campaign_id')->nullable()
                ->constrained('csa_campaigns', indexName: 'fk_policies_campaign')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->boolean('mandatory_training')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'policy_ref'], 'uniq_policies_tenant_ref');
            $table->index('tenant_id');
            $table->index('category_id');
            $table->index('document_id');
            $table->index('owner_id');
            $table->index('approver_id');
            $table->index('status');
            $table->index('policy_type');
            $table->index('next_review_at');
            $table->index('effective_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
