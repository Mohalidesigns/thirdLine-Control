<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (group control, entity). 'Decline Requested' is an
        // addition to the BRD enum: an entity may decline only with a reason
        // AND Control Function Head approval, so the request is its own
        // state rather than a silent jump to Declined.
        Schema::create('control_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants', indexName: 'fk_control_distributions_tenant')->cascadeOnDelete();
            $table->foreignId('control_id')
                ->constrained('controls', indexName: 'fk_control_distributions_control')->cascadeOnDelete();
            $table->foreignId('entity_id')
                ->constrained('organisation_units', indexName: 'fk_control_distributions_entity')->cascadeOnDelete();
            $table->foreignId('child_control_id')->nullable()
                ->constrained('controls', indexName: 'fk_control_distributions_child')->nullOnDelete();
            $table->foreignId('assigned_owner_id')->nullable()
                ->constrained('users', indexName: 'fk_control_distributions_owner')->nullOnDelete();
            $table->foreignId('distributed_by')->nullable()
                ->constrained('users', indexName: 'fk_control_distributions_distributor')->nullOnDelete();
            $table->timestamp('distributed_at')->nullable();
            $table->date('due_at')->nullable();
            $table->enum('status', [
                'Pending', 'Acknowledged', 'In Progress', 'Completed', 'Decline Requested', 'Declined',
            ])->default('Pending');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('local_adaptations')->nullable();
            $table->text('decline_reason')->nullable();
            $table->foreignId('decline_approved_by')->nullable()
                ->constrained('users', indexName: 'fk_control_distributions_decline_approver')->nullOnDelete();
            $table->timestamp('decline_approved_at')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('due_at');
            $table->unique(['control_id', 'entity_id'], 'uq_control_distribution_entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_distributions');
    }
};
