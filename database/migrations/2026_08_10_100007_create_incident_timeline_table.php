<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The incident chronology (11.2) — what happened, when, and who did it.
     * Distinct from the audit trail: this is the investigator's narrative,
     * with material events flagged for the board extract.
     */
    public function up(): void
    {
        Schema::create('incident_timeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_incident_timeline_tenant')->cascadeOnDelete();
            $table->foreignId('incident_id')
                ->constrained('incidents', indexName: 'fk_incident_timeline_incident')->cascadeOnDelete();
            // dateTime: a NOT NULL TIMESTAMP would inherit MySQL's implicit
            // ON UPDATE CURRENT_TIMESTAMP and rewrite the chronology.
            $table->dateTime('occurred_at');
            $table->foreignId('actor_id')->nullable()
                ->constrained('users', indexName: 'fk_incident_timeline_actor')->nullOnDelete();
            $table->string('event_type', 60);
            $table->text('description');
            $table->boolean('is_material')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('incident_id');
            $table->index('actor_id');
            $table->index(['incident_id', 'occurred_at'], 'idx_incident_timeline_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_timeline');
    }
};
