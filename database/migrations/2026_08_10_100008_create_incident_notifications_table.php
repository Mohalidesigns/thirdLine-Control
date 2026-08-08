<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per regulatory notification an incident owes (11.2).
     *
     * The row points at the obligation it came from and stores no window of
     * its own: due_at is recomputed from the obligation's due_rule, so
     * editing "72 hours" on the NDPC record moves every open countdown (R1).
     * An incident cannot close while a required notification is outstanding.
     */
    public function up(): void
    {
        Schema::create('incident_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_incident_notifications_tenant')->cascadeOnDelete();
            $table->foreignId('incident_id')
                ->constrained('incidents', indexName: 'fk_incident_notifications_incident')->cascadeOnDelete();
            $table->foreignId('obligation_id')
                ->constrained('regulatory_obligations', indexName: 'fk_incident_notifications_obligation')
                ->cascadeOnDelete();
            $table->foreignId('regulator_id')->nullable()
                ->constrained('regulators', indexName: 'fk_incident_notifications_regulator')->nullOnDelete();
            $table->foreignId('entity_id')->nullable()
                ->constrained('organisation_units', indexName: 'fk_incident_notifications_entity')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->enum('status', ['Pending', 'Notified', 'Not Required', 'Waived'])->default('Pending');
            $table->boolean('is_required')->default(true);
            $table->timestamp('notified_at')->nullable();
            $table->string('notification_reference')->nullable();
            $table->foreignId('notified_by')->nullable()
                ->constrained('users', indexName: 'fk_incident_notifications_notifier')->nullOnDelete();
            $table->text('notes')->nullable();
            // R10: an unverified obligation still shows as an internal duty but
            // is never presented as a confirmed statutory deadline.
            $table->string('verification_status', 20)->default('unverified');
            $table->timestamps();

            $table->unique(['incident_id', 'obligation_id'], 'uniq_incident_notifications_pair');
            $table->index('tenant_id');
            $table->index('incident_id');
            $table->index('obligation_id');
            $table->index('regulator_id');
            $table->index('entity_id');
            $table->index('notified_by');
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_notifications');
    }
};
