<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Toxic-combination definitions (12.3). Function names are the source
     * system's own — a Finacle menu id, an Entra role, an SAP transaction
     * code — so the matrix is seeded data per system, never PHP (R1).
     */
    public function up(): void
    {
        Schema::create('sod_conflict_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('system_key', 60)->nullable();
            $table->string('function_a');
            $table->string('function_b');
            $table->enum('conflict_type', [
                'create_approve', 'create_pay', 'maintain_reconcile', 'request_authorise', 'custom',
            ])->default('create_approve');
            $table->enum('risk_level', ['Critical', 'High', 'Medium', 'Low'])->default('High');
            $table->foreignId('mitigating_control_id')->nullable()->constrained('controls')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'system_key', 'function_a', 'function_b'], 'sod_rules_unique_pair');
            $table->index('tenant_id');
            $table->index('mitigating_control_id');
            $table->index('is_active');
            $table->index('risk_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sod_conflict_rules');
    }
};
