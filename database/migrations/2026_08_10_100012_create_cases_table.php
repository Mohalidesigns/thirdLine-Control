<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Investigations, whistleblowing, disciplinary and litigation cases
     * (11.4).
     *
     * Two deliberate departures from the rest of the platform:
     *
     *  1. Access is an explicit allowlist (access_user_ids) enforced in the
     *     model's global scope AND the policy. A System Administrator gets
     *     no automatic reach — whistleblowing integrity depends on it.
     *  2. An anonymous report stores no reporter identity at all. reporter_id
     *     stays NULL and reporter_token_hash holds only a one-way hash of the
     *     token handed to the reporter, so they can follow up without ever
     *     being resolvable back to a person.
     */
    public function up(): void
    {
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_cases_tenant')->cascadeOnDelete();
            $table->string('case_ref', 40);
            $table->enum('case_type', [
                'investigation', 'whistleblowing', 'disciplinary', 'fraud',
                'conflict_of_interest', 'regulatory_enquiry', 'litigation',
            ])->default('investigation');
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('confidentiality', ['Standard', 'Restricted', 'Highly Restricted'])
                ->default('Restricted');
            $table->boolean('is_anonymous')->default(false);
            $table->foreignId('reporter_id')->nullable()
                ->constrained('users', indexName: 'fk_cases_reporter')->nullOnDelete();
            $table->string('reporter_token_hash', 64)->nullable();
            $table->boolean('was_anonymised')->default(false);
            $table->string('anonymisation_note')->nullable();
            // dateTime for the same reason as complaints.received_at: a NOT
            // NULL TIMESTAMP would pick up MySQL's implicit ON UPDATE.
            $table->dateTime('received_at');
            $table->string('channel', 40)->nullable();
            $table->foreignId('entity_id')->nullable()
                ->constrained('organisation_units', indexName: 'fk_cases_entity')->nullOnDelete();
            $table->json('subject_persons')->nullable();
            $table->enum('status', [
                'Received', 'Assessed', 'Under Investigation', 'Substantiated',
                'Unsubstantiated', 'Referred', 'Closed',
            ])->default('Received');
            $table->enum('severity', ['Low', 'Medium', 'High', 'Critical'])->default('Medium');
            $table->foreignId('lead_investigator_id')->nullable()
                ->constrained('users', indexName: 'fk_cases_lead')->nullOnDelete();
            $table->text('investigation_plan')->nullable();
            $table->text('findings')->nullable();
            $table->text('conclusion')->nullable();
            $table->text('actions_taken')->nullable();
            $table->string('referred_to')->nullable();
            $table->foreignId('closed_by')->nullable()
                ->constrained('users', indexName: 'fk_cases_closer')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('related_incident_id')->nullable()
                ->constrained('incidents', indexName: 'fk_cases_incident')->nullOnDelete();
            $table->foreignId('related_complaint_id')->nullable()
                ->constrained('complaints', indexName: 'fk_cases_complaint')->nullOnDelete();
            $table->json('access_user_ids')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'case_ref'], 'uniq_cases_tenant_ref');
            $table->unique('reporter_token_hash', 'uniq_cases_reporter_token');
            $table->index('tenant_id');
            $table->index('entity_id');
            $table->index('lead_investigator_id');
            $table->index('reporter_id');
            $table->index('closed_by');
            $table->index('related_incident_id');
            $table->index('related_complaint_id');
            $table->index('status');
            $table->index('case_type');
            $table->index('severity');
            $table->index('confidentiality');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cases');
    }
};
