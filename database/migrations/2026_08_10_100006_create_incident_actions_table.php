<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Containment, corrective, preventive, communication and regulatory
     * actions (11.2). is_mandatory drives the closure gate: an incident
     * cannot close while a mandatory action is still open.
     */
    public function up(): void
    {
        Schema::create('incident_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_incident_actions_tenant')->cascadeOnDelete();
            $table->foreignId('incident_id')
                ->constrained('incidents', indexName: 'fk_incident_actions_incident')->cascadeOnDelete();
            $table->enum('action_type', [
                'containment', 'corrective', 'preventive', 'communication', 'regulatory',
            ])->default('corrective');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()
                ->constrained('users', indexName: 'fk_incident_actions_owner')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->enum('status', ['Open', 'In Progress', 'Completed', 'Verified', 'Cancelled'])
                ->default('Open');
            $table->boolean('is_mandatory')->default(true);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('verified_by')->nullable()
                ->constrained('users', indexName: 'fk_incident_actions_verifier')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('evidence_id')->nullable()
                ->constrained('evidence', indexName: 'fk_incident_actions_evidence')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('incident_id');
            $table->index('owner_id');
            $table->index('verified_by');
            $table->index('evidence_id');
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_actions');
    }
};
