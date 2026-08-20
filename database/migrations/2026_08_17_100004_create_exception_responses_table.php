<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-01: the department's on-the-record answer. One per round, immutable
     * once submitted (R-F) — a rejected round stays readable forever.
     */
    public function up(): void
    {
        Schema::create('exception_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('escalation_id')->constrained('exception_escalations')->cascadeOnDelete();
            $table->foreignId('exception_id')->constrained('control_exceptions')->cascadeOnDelete();
            $table->unsignedInteger('round_no')->default(1);
            $table->foreignId('responder_id')->nullable()
                ->constrained('users', indexName: 'fk_exc_resp_responder')->nullOnDelete();
            $table->string('responder_name')->nullable();
            $table->string('responder_email')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->enum('channel', ['portal', 'secure_link'])->default('portal');
            $table->enum('position', [
                'Accepted', 'Partially Accepted', 'Disputed', 'Already Remediated',
                'More Information Required',
            ]);
            $table->text('management_comment');
            $table->text('root_cause')->nullable();
            $table->enum('root_cause_category', ['people', 'process', 'technology', 'governance', 'external'])->nullable();
            $table->text('agreed_action_plan')->nullable();
            $table->date('proposed_target_date')->nullable();
            $table->boolean('is_late')->default(false);
            $table->enum('review_status', ['Pending Review', 'Accepted', 'Rejected', 'Superseded'])
                ->default('Pending Review');
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users', indexName: 'fk_exc_resp_reviewed_by')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'review_status']);
            $table->index(['escalation_id', 'round_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_responses');
    }
};
