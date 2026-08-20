<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The versioned prompt registry (Part C §C.3). A prompt is never edited
     * in place: a change writes a new version row and the old one stays
     * readable forever, because ai_interactions references the version that
     * actually ran and the audit trail must remain reconstructible (R3).
     *
     * tenant_id is nullable on purpose. NULL rows are the shipped library;
     * a tenant row with the same key+version overrides it, so a bank can
     * tune wording for its own regulator without forking the product (R1).
     *
     * output_schema is the contract the model's reply is validated against
     * before anything reaches a human, and — where the model supports it —
     * is also put on the wire as a structured-output format.
     */
    public function up(): void
    {
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->unsignedInteger('version')->default(1);
            $table->string('name');
            $table->text('description')->nullable();
            $table->longText('system_prompt');
            $table->longText('user_template');
            $table->json('output_schema')->nullable();
            $table->json('variables')->nullable();
            $table->string('model_hint', 60)->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('changelog')->nullable();
            $table->decimal('eval_score', 4, 3)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key', 'version']);
            $table->index('tenant_id');
            $table->index('created_by');
            $table->index('approved_by');
            $table->index('key');
            $table->index('is_active');
            $table->index(['key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompts');
    }
};
