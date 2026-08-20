<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CR-01: who an exception is issued to by default. Evaluated in
     * sequence order, first match wins — and a rule only PRE-FILLS the
     * issue form; the control officer always confirms the recipient.
     */
    public function up(): void
    {
        Schema::create('exception_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('match_unit_id')->nullable()
                ->constrained('organisation_units', indexName: 'fk_exc_routing_match_unit')->nullOnDelete();
            $table->foreignId('match_process_id')->nullable()
                ->constrained('business_processes', indexName: 'fk_exc_routing_match_process')->nullOnDelete();
            $table->foreignId('match_control_category_id')->nullable()
                ->constrained('control_categories', indexName: 'fk_exc_routing_match_category')->nullOnDelete();
            $table->enum('match_severity', ['Critical', 'High', 'Medium', 'Low'])->nullable();
            $table->string('match_source_type')->nullable();
            $table->enum('route_to_type', ['unit_head', 'process_owner', 'role', 'user']);
            $table->string('route_to_role')->nullable();
            $table->foreignId('route_to_user_id')->nullable()
                ->constrained('users', indexName: 'fk_exc_routing_route_user')->nullOnDelete();
            $table->foreignId('route_to_unit_id')->nullable()
                ->constrained('organisation_units', indexName: 'fk_exc_routing_route_unit')->nullOnDelete();
            $table->string('cc_role')->nullable();
            $table->json('cc_user_ids')->nullable();
            $table->foreignId('sla_policy_id')->nullable()
                ->constrained('exception_sla_policies', indexName: 'fk_exc_routing_sla')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_routing_rules');
    }
};
