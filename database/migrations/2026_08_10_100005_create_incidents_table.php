<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Incident management (11.2). Loss is stored as integer minor units plus
     * an ISO-4217 code (R7) — never a float. The Basel II/III event type is
     * captured so operational-risk capital work has its taxonomy at source.
     *
     * Regulatory notification obligations are NOT columns here: they are
     * resolved from the seeded obligation records into incident_notifications
     * (R1), so a change to the obligation changes the countdown.
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_incidents_tenant')->cascadeOnDelete();
            $table->string('incident_ref', 40);
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('incident_type', [
                'operational', 'security', 'cyber', 'fraud', 'compliance', 'data_breach',
                'health_safety', 'conduct', 'third_party', 'continuity', 'other',
            ])->default('operational');
            $table->enum('basel_event_type', [
                'Internal Fraud', 'External Fraud', 'Employment Practices',
                'Clients Products & Business Practices', 'Damage to Physical Assets',
                'Business Disruption & System Failures', 'Execution Delivery & Process Mgmt',
            ])->nullable();
            $table->enum('severity', ['Low', 'Medium', 'High', 'Critical'])->default('Medium');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->string('detection_method')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('reported_by')->nullable()
                ->constrained('users', indexName: 'fk_incidents_reporter')->nullOnDelete();
            $table->foreignId('entity_id')->nullable()
                ->constrained('organisation_units', indexName: 'fk_incidents_entity')->nullOnDelete();
            $table->foreignId('process_id')->nullable()
                ->constrained('business_processes', indexName: 'fk_incidents_process')->nullOnDelete();
            $table->enum('status', [
                'Reported', 'Triaged', 'Under Investigation', 'Contained',
                'Remediated', 'Closed', 'Reopened',
            ])->default('Reported');

            // R7: minor units + currency code, never a float.
            $table->bigInteger('gross_loss_minor')->default(0);
            $table->bigInteger('recovery_minor')->default(0);
            $table->bigInteger('net_loss_minor')->default(0);
            $table->char('currency', 3)->nullable();
            $table->boolean('near_miss')->default(false);

            $table->boolean('is_reportable')->default(false);
            $table->json('regulator_ids')->nullable();
            $table->timestamp('notification_due_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->string('notification_reference')->nullable();

            $table->text('root_cause')->nullable();
            $table->string('root_cause_category')->nullable();
            $table->json('contributing_factors')->nullable();
            $table->text('lessons_learned')->nullable();

            $table->foreignId('owner_id')->nullable()
                ->constrained('users', indexName: 'fk_incidents_owner')->nullOnDelete();
            $table->foreignId('investigator_id')->nullable()
                ->constrained('users', indexName: 'fk_incidents_investigator')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()
                ->constrained('users', indexName: 'fk_incidents_closer')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->json('control_failure_ids')->nullable();
            $table->json('risk_ids')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'incident_ref'], 'uniq_incidents_tenant_ref');
            $table->index('tenant_id');
            $table->index('entity_id');
            $table->index('process_id');
            $table->index('owner_id');
            $table->index('investigator_id');
            $table->index('reported_by');
            $table->index('closed_by');
            $table->index('status');
            $table->index('severity');
            $table->index('incident_type');
            $table->index('near_miss');
            $table->index('is_reportable');
            $table->index('occurred_at');
            $table->index('notification_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
