<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The compliance calendar. Rows are generated idempotently by
        // atheris:generate-obligation-instances within a rolling horizon.
        Schema::create('obligation_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_obligation_instances_tenant')->cascadeOnDelete();
            $table->foreignId('obligation_id')
                ->constrained('regulatory_obligations', indexName: 'fk_obligation_instances_obligation')->cascadeOnDelete();
            $table->foreignId('assignment_id')
                ->constrained('obligation_assignments', indexName: 'fk_obligation_instances_assignment')->cascadeOnDelete();
            $table->string('period_label', 60);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('due_at');
            $table->enum('status', [
                'Not Started', 'In Progress', 'Submitted', 'Accepted',
                'Rejected', 'Overdue', 'Waived',
            ])->default('Not Started');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()
                ->constrained('users', indexName: 'fk_obligation_instances_submitter')->nullOnDelete();
            $table->string('submission_reference', 120)->nullable();
            $table->string('acknowledgement_ref', 120)->nullable();
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users', indexName: 'fk_obligation_instances_reviewer')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('evidence_count')->default(0);
            $table->integer('days_overdue')->default(0);
            // R7: minor units; the currency comes from the obligation.
            $table->bigInteger('penalty_exposure_minor')->default(0);
            $table->char('penalty_currency', 3)->nullable();
            $table->json('reminders_sent')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('obligation_id');
            $table->index('assignment_id');
            $table->index('status');
            $table->index('due_at');
            $table->index(['tenant_id', 'status', 'due_at'], 'idx_obligation_instances_calendar');
            $table->unique(['tenant_id', 'assignment_id', 'period_label'], 'uq_obligation_instances_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obligation_instances');
    }
};
