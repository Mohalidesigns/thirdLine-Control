<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One engine, three campaign types: control self-assessment,
        // attestation and general survey (9.3–9.5). Surveys may be
        // anonymous — is_anonymous is immutable once responses exist.
        Schema::create('csa_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_csa_campaigns_tenant')->cascadeOnDelete();
            $table->string('reference')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('campaign_type', ['csa', 'attestation', 'survey'])->default('csa');
            $table->json('scope_definition')->nullable();
            $table->string('period_label')->nullable();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->json('reminder_schedule')->nullable();
            $table->enum('status', ['Draft', 'Scheduled', 'Open', 'Closed', 'Archived'])->default('Draft');
            $table->boolean('is_anonymous')->default(false);
            $table->unsignedTinyInteger('variance_threshold')->default(1);
            $table->foreignId('created_by')->nullable()
                ->constrained('users', indexName: 'fk_csa_campaigns_creator')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users', indexName: 'fk_csa_campaigns_approver')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedTinyInteger('response_rate_target')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('campaign_type');
            $table->index('closes_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csa_campaigns');
    }
};
