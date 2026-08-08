<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Red or Critical reading opens a breach record; per tenant
     * configuration it also opens a linked exception (10.5) so the
     * remediation runs through the existing exception lifecycle.
     */
    public function up(): void
    {
        Schema::create('metric_breaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('metric_id')->constrained()->cascadeOnDelete();
            $table->foreignId('metric_value_id')->constrained('metric_values')->cascadeOnDelete();
            $table->enum('level', ['Amber', 'Red', 'Critical']);
            $table->timestamp('detected_at');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('action_taken')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('exception_id')->nullable()->constrained('control_exceptions')->nullOnDelete();
            $table->unsignedBigInteger('incident_id')->nullable();
            $table->foreignId('escalation_event_id')->nullable()->constrained('escalation_events')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('metric_id');
            $table->index('metric_value_id');
            $table->index('exception_id');
            $table->index('escalation_event_id');
            $table->index('acknowledged_by');
            $table->index('level');
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_breaches');
    }
};
