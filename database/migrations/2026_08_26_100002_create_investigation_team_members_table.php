<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CR-04 §C.2 — who is on an investigation.
 *
 * This table is also the access-control list: InvestigationPolicy and the
 * model's visibility scope both read it, so being on the team and being
 * able to see the case are the same fact rather than two that can drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_inv_team_tenant')->cascadeOnDelete();
            $table->foreignId('investigation_id')
                ->constrained('investigations', indexName: 'fk_inv_team_investigation')->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users', indexName: 'fk_inv_team_user')->cascadeOnDelete();
            $table->enum('role', ['lead', 'investigator', 'reviewer', 'observer', 'subject_matter_expert'])
                ->default('investigator');
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('assigned_by')->nullable()
                ->constrained('users', indexName: 'fk_inv_team_assigner')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['investigation_id', 'user_id'], 'uniq_inv_team_member');
            $table->index(['tenant_id', 'user_id'], 'inv_team_tenant_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_team_members');
    }
};
