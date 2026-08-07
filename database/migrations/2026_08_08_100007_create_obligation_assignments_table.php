<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which entity carries an obligation, who owns it, and — where the
        // tenant declares it does not apply — the reasoned, approved override.
        Schema::create('obligation_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_obligation_assignments_tenant')->cascadeOnDelete();
            $table->foreignId('obligation_id')
                ->constrained('regulatory_obligations', indexName: 'fk_obligation_assignments_obligation')->cascadeOnDelete();
            $table->foreignId('entity_id')
                ->constrained('organisation_units', indexName: 'fk_obligation_assignments_entity')->cascadeOnDelete();
            $table->foreignId('owner_id')
                ->constrained('users', indexName: 'fk_obligation_assignments_owner')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()
                ->constrained('users', indexName: 'fk_obligation_assignments_reviewer')->nullOnDelete();
            $table->boolean('is_applicable')->default(true);
            $table->text('non_applicability_reason')->nullable();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users', indexName: 'fk_obligation_assignments_approver')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('obligation_id');
            $table->index('entity_id');
            $table->index('owner_id');
            $table->index('is_applicable');
            $table->unique(['tenant_id', 'obligation_id', 'entity_id'], 'uq_obligation_assignments');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obligation_assignments');
    }
};
