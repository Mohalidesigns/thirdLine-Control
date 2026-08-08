<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consumer complaints (11.3), built around the CBN Consumer Protection
     * Regulations. Every clock and every naira in this table is derived from
     * the seeded CBN-CPF obligation records at runtime (R1): the columns
     * store the computed result, never the rule.
     *
     * acknowledged_at is written server-side only — the client can never
     * supply the time that starts or stops a regulatory clock.
     */
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_complaints_tenant')->cascadeOnDelete();
            $table->string('complaint_ref', 40);
            $table->string('tracking_id', 40);
            $table->enum('channel', [
                'letter', 'email', 'phone', 'social_media', 'branch', 'app',
                'ussd', 'web', 'whatsapp', 'regulator_referral',
            ])->default('email');
            // dateTime, not timestamp: MySQL gives the first NOT NULL
            // TIMESTAMP column an implicit ON UPDATE CURRENT_TIMESTAMP, which
            // would silently reset the moment a regulatory clock starts from
            // on every save.
            $table->dateTime('received_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('acknowledgement_due_at')->nullable();

            $table->string('customer_name')->nullable();
            $table->string('customer_reference')->nullable();
            $table->json('customer_contact')->nullable();

            $table->foreignId('category_id')->nullable()
                ->constrained('complaint_categories', indexName: 'fk_complaints_category')->nullOnDelete();
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('product')->nullable();
            $table->bigInteger('amount_disputed_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->foreignId('entity_id')->nullable()
                ->constrained('organisation_units', indexName: 'fk_complaints_entity')->nullOnDelete();
            $table->string('branch')->nullable();
            $table->foreignId('assigned_to')->nullable()
                ->constrained('users', indexName: 'fk_complaints_assignee')->nullOnDelete();

            $table->enum('status', [
                'Received', 'Acknowledged', 'Under Investigation', 'Awaiting Customer',
                'Resolved', 'Closed', 'Escalated to Regulator', 'Reopened',
            ])->default('Received');
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->enum('resolution_type', ['upheld', 'partially_upheld', 'not_upheld', 'withdrawn'])->nullable();
            $table->bigInteger('redress_amount_minor')->nullable();
            $table->char('redress_currency', 3)->nullable();
            $table->boolean('customer_satisfied')->nullable();

            $table->boolean('sla_breached')->default(false);
            $table->unsignedInteger('days_open')->default(0);
            $table->bigInteger('penalty_exposure_minor')->default(0);
            $table->char('penalty_currency', 3)->nullable();

            $table->boolean('escalated_to_regulator')->default(false);
            $table->string('regulator_reference')->nullable();
            $table->foreignId('root_cause_id')->nullable()
                ->constrained('complaint_categories', indexName: 'fk_complaints_root_cause')->nullOnDelete();
            $table->foreignId('linked_incident_id')->nullable()
                ->constrained('incidents', indexName: 'fk_complaints_incident')->nullOnDelete();
            $table->foreignId('linked_control_id')->nullable()
                ->constrained('controls', indexName: 'fk_complaints_control')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'complaint_ref'], 'uniq_complaints_tenant_ref');
            $table->unique(['tenant_id', 'tracking_id'], 'uniq_complaints_tenant_tracking');
            $table->index('tenant_id');
            $table->index('category_id');
            $table->index('root_cause_id');
            $table->index('entity_id');
            $table->index('assigned_to');
            $table->index('linked_incident_id');
            $table->index('linked_control_id');
            $table->index('status');
            $table->index('channel');
            $table->index('received_at');
            $table->index('acknowledgement_due_at');
            $table->index('resolution_due_at');
            $table->index('sla_breached');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
