<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Treatment plans (10.4): strategy, cost/benefit, milestones, progress,
     * overdue alerting and independent verification. Acceptance carries an
     * expiry so an accepted risk is re-confirmed, never forgotten.
     */
    public function up(): void
    {
        Schema::create('risk_treatments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40);
            $table->foreignId('risk_id')->constrained()->cascadeOnDelete();
            $table->enum('strategy', ['Avoid', 'Reduce', 'Transfer', 'Accept', 'Exploit'])->default('Reduce');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('target_rating', ['Low', 'Moderate', 'High', 'Critical'])->nullable();
            $table->bigInteger('cost_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->text('benefit_description')->nullable();
            $table->date('start_at')->nullable();
            $table->date('due_at')->nullable();
            $table->enum('status', [
                'Proposed', 'Approved', 'In Progress', 'Implemented',
                'Verified', 'Overdue', 'Cancelled',
            ])->default('Proposed');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->timestamp('last_update_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('control_id')->nullable()->constrained('controls')->nullOnDelete();

            // Risk acceptance (strategy = Accept) — senior approval + expiry,
            // mirroring the exception accept-risk pattern.
            $table->text('acceptance_reason')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('acceptance_expiry')->nullable();
            $table->boolean('alerts_enabled')->default(true);
            $table->unsignedSmallInteger('alert_lead_days')->default(7);
            $table->timestamp('last_alert_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference']);
            $table->index('tenant_id');
            $table->index('risk_id');
            $table->index('owner_id');
            $table->index('approver_id');
            $table->index('verified_by');
            $table->index('control_id');
            $table->index('accepted_by');
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_treatments');
    }
};
