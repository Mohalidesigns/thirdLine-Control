<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A signed statement: the exact text version attested is snapshotted
        // so later edits to the policy can never change what was signed (9.5).
        Schema::create('attestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_attestations_tenant')->cascadeOnDelete();
            $table->string('attestable_type');
            $table->unsignedBigInteger('attestable_id');
            $table->foreignId('user_id')
                ->constrained('users', indexName: 'fk_attestations_user')->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()
                ->constrained('csa_campaigns', indexName: 'fk_attestations_campaign')->nullOnDelete();
            $table->timestamp('attested_at');
            $table->longText('attestation_text_snapshot');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->enum('method', ['web', 'mobile', 'whatsapp', 'email'])->default('web');
            $table->string('version_attested')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['attestable_type', 'attestable_id']);
            $table->index('user_id');
            $table->index('campaign_id');
            $table->unique(['user_id', 'campaign_id', 'attestable_type', 'attestable_id'], 'uq_attestation_per_campaign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attestations');
    }
};
