<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-tenant, per-capability settings (Part C §C.3). One row per
     * capability_key per tenant; a missing row means the capability is off,
     * so a fresh installation ships with AI dormant until someone turns it
     * on deliberately.
     *
     * requires_approval_role_id names the role permitted to accept a draft
     * from this capability where that is narrower than the capability's own
     * permission — the answer to "who is allowed to make the AI's suggestion
     * real". It never grants anything: AiGateway checks it in addition to the
     * domain permission, never instead of it (R4, R2).
     *
     * temperature is stored because the specification's data model has it,
     * but the gateway only puts it on the wire for models that accept it —
     * the current Opus/Sonnet generation rejects sampling parameters with a
     * 400. See config/services.php.
     */
    public function up(): void
    {
        Schema::create('ai_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('capability_key', 60);
            $table->boolean('is_enabled')->default(false);
            $table->string('model', 60)->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->foreignId('system_prompt_id')->nullable()->constrained('ai_prompts')->nullOnDelete();
            $table->unsignedBigInteger('monthly_token_budget')->nullable();
            $table->foreignId('requires_approval_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->decimal('min_confidence_to_surface', 4, 3)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'capability_key']);
            $table->index('tenant_id');
            $table->index('system_prompt_id');
            $table->index('requires_approval_role_id');
            $table->index('created_by');
            $table->index('approved_by');
            $table->index('capability_key');
            $table->index('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_configurations');
    }
};
