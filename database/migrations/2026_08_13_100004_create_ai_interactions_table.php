<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The AI audit log (Part C §C.3, R3). Append-only in the same sense as
     * audit_trails: rows are written and decided upon, never rewritten and
     * never deleted. Every call lands here — success, refusal, budget stop,
     * rate limit and schema failure alike — because "the model was never
     * asked" and "the model was asked and we ignored it" are different facts
     * and a regulator will want both.
     *
     * input_redacted holds the payload AFTER redaction, which is the only
     * form of it that is safe to keep. input_hash is over the redacted
     * payload too, so identical questions are recognisable without storing
     * the original.
     *
     * retrieved_context holds record identifiers only, never their content
     * (§C.3) — the chunks live in ai_knowledge_chunks and are re-derivable,
     * and copying tenant content into a second table only widens the blast
     * radius.
     *
     * human_decision is the enforcement point for R4. It starts at Pending
     * and nothing an AI produced becomes real until a human moves it, with
     * decided_by recording who.
     */
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('capability_key', 60);
            $table->foreignId('prompt_id')->nullable()->constrained('ai_prompts')->nullOnDelete();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('model', 60)->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->char('input_hash', 64)->nullable();
            $table->json('input_redacted')->nullable();
            $table->json('retrieved_context')->nullable();
            $table->longText('raw_output')->nullable();
            $table->json('parsed_output')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->json('citations')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->unsignedInteger('latency_ms')->default(0);
            $table->enum('status', [
                'Pending', 'Completed', 'Failed', 'Rejected by Schema',
                'Budget Exceeded', 'Rate Limited', 'Unavailable',
            ])->default('Pending');
            $table->text('error_message')->nullable();
            $table->enum('human_decision', ['Pending', 'Accepted', 'Edited', 'Rejected'])->default('Pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->json('edit_diff')->nullable();
            $table->text('rejection_reason')->nullable();

            // What the accepted draft actually became, so the trail runs
            // both ways: from the interaction to the record it created, and
            // from the record's audit entries back to the interaction.
            $table->string('applied_type')->nullable();
            $table->unsignedBigInteger('applied_id')->nullable();

            $table->timestamps();

            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('prompt_id');
            $table->index('decided_by');
            $table->index('capability_key');
            $table->index('status');
            $table->index('human_decision');
            $table->index('model');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['applied_type', 'applied_id']);
            $table->index(['tenant_id', 'capability_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
    }
};
