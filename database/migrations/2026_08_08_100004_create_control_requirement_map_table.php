<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Maker-checker: an officer maps, a Control Function Head approves.
        // Only approved mappings count toward coverage or a submission.
        Schema::create('control_requirement_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_control_requirement_map_tenant')->cascadeOnDelete();
            $table->foreignId('control_id')
                ->constrained('controls', indexName: 'fk_control_requirement_map_control')->cascadeOnDelete();
            $table->foreignId('requirement_id')
                ->constrained('framework_requirements', indexName: 'fk_control_requirement_map_requirement')->cascadeOnDelete();
            $table->enum('coverage', ['full', 'partial', 'supporting'])->default('full');
            $table->text('notes')->nullable();
            $table->foreignId('mapped_by')->nullable()
                ->constrained('users', indexName: 'fk_control_requirement_map_mapper')->nullOnDelete();
            $table->timestamp('mapped_at')->nullable();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users', indexName: 'fk_control_requirement_map_approver')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('control_id');
            $table->index('requirement_id');
            $table->index('approved_at');
            $table->unique(['tenant_id', 'control_id', 'requirement_id'], 'uq_control_requirement_map');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_requirement_map');
    }
};
